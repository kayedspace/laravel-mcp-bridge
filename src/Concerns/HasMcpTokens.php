<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use KayedSpace\McpBridge\Data\NewMcpToken;
use KayedSpace\McpBridge\Models\McpToken;

/**
 * Add to the user model that agents connect as.
 *
 * It sits beside Sanctum's `HasApiTokens` on one model without conflict,
 * which is why nothing here is called `tokenCan()`: the authorization layer
 * asks `currentMcpToken()` for the package's own token and falls back to
 * `tokenCan()` for a Sanctum or Passport one. An application that issues
 * Sanctum tokens to its agents needs nothing from this trait.
 *
 * @phpstan-require-extends Model
 */
trait HasMcpTokens
{
    protected ?McpToken $mcpToken = null;

    /** @return MorphMany<McpToken, $this> */
    public function mcpTokens(): MorphMany
    {
        return $this->morphMany(McpToken::class, 'tokenable');
    }

    /**
     * The scopes are a decision, so they are written down by whoever issues
     * the token: `['*']` holds every scope, `[]` holds none, and there is no
     * default to reach either by forgetting.
     *
     * @param  list<string>  $scopes
     */
    public function createMcpToken(
        string $name,
        array $scopes,
        ?DateTimeInterface $expiresAt = null,
        ?string $client = null,
    ): NewMcpToken {
        return McpToken::issue($this, $name, $scopes, $expiresAt, $client);
    }

    /** The token this request arrived with, set by the guard. */
    public function withMcpToken(McpToken $token): static
    {
        $this->mcpToken = $token;

        return $this;
    }

    /**
     * The token this request arrived with, or null for a user read from a
     * session or a factory, who holds no scopes at all.
     */
    public function currentMcpToken(): ?McpToken
    {
        return $this->mcpToken;
    }
}
