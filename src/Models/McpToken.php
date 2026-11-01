<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Data\NewMcpToken;
use KayedSpace\McpBridge\Models\Concerns\OnBridgeConnection;

/**
 * A token an agent connects with.
 *
 * The token itself is never stored, only its hash, so a leaked database row
 * cannot be used as a credential. The plain text exists once, in the result of
 * `issue()`, and whoever asked for it is the only one who will ever see it.
 *
 * @property int $id
 * @property string $name
 * @property string $token
 * @property list<string> $scopes
 * @property string|null $client
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class McpToken extends Model
{
    use OnBridgeConnection;

    /** @var list<string> */
    protected $fillable = [
        'tokenable_type',
        'tokenable_id',
        'name',
        'token',
        'scopes',
        'client',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected function bridgeTable(): string
    {
        return 'tokens';
    }

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Issue a token for a user. The plain text is returned with the record
     * and never kept. Scopes have no default: a forgotten argument must not
     * mint a token that can do everything.
     *
     * @param  list<string>  $scopes
     */
    public static function issue(
        Model $tokenable,
        string $name,
        array $scopes,
        ?DateTimeInterface $expiresAt = null,
        ?string $client = null,
    ): NewMcpToken {
        $plain = config()->string('mcp-bridge.tokens.prefix', 'mcpb_').Str::random(40);

        $token = new self;
        $token->fill([
            'tokenable_type' => $tokenable->getMorphClass(),
            'tokenable_id' => $tokenable->getKey(),
            'name' => $name,
            'token' => hash('sha256', $plain),
            'scopes' => $scopes,
            'client' => $client,
            'expires_at' => $expiresAt,
        ]);
        $token->save();

        return new NewMcpToken($token, $plain);
    }

    /** The one row a presented token could be, or none. */
    public static function findByPlainText(string $plain): ?self
    {
        return self::query()->where('token', hash('sha256', $plain))->first();
    }

    /**
     * Not revoked and not expired: the tokens a guard would still accept.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this token carries a scope.
     *
     * Only `*` holds everything, and a token issued with no scopes holds
     * nothing, the honest reading for a credential: an empty list used to be
     * the wildcard, so a token created
     * without thinking about scopes was a token that could do anything.
     */
    public function can(string $scope): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        foreach ($this->scopes as $granted) {
            if ($granted === $scope || Str::is($granted, $scope)) {
                return true;
            }
        }

        return false;
    }

    public function holdsEveryScope(): bool
    {
        return in_array('*', $this->scopes, true);
    }

    /**
     * Once a minute at most. A client lists tools and calls one in quick
     * succession, and a row written on every request is a write the trail
     * does not need, since "last used" is not "every use".
     */
    public function markUsed(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(Date::now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => Date::now()])->saveQuietly();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => Date::now()])->save();
    }
}
