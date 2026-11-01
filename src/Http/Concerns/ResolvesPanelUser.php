<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Http\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use KayedSpace\McpBridge\Concerns\HasMcpTokens;
use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Models\McpToken;
use RuntimeException;

/**
 * Who the panel is about. The middleware refused a guest already; what is
 * left is that the user is a model a token can belong to, and the queries
 * every panel page scopes to that user.
 */
trait ResolvesPanelUser
{
    protected function user(Request $request): Model
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            throw new RuntimeException('The MCP token panel needs a signed-in Eloquent user to issue tokens for.');
        }

        return $user;
    }

    protected function holdsTokens(Model $user): bool
    {
        return in_array(HasMcpTokens::class, class_uses_recursive($user), true);
    }

    /** @return Builder<McpToken> */
    protected function tokensOf(Model $user): Builder
    {
        return McpToken::query()->whereMorphedTo('tokenable', $user);
    }

    /**
     * The calls made as this user: as the actor, or with one of their tokens.
     * The audit names the actor by class and id, the way it was given, so the
     * match is on the same two.
     *
     * @return Builder<McpAuditEntry>
     */
    protected function callsOf(Model $user): Builder
    {
        // The audit stores token ids as text, and PostgreSQL will not compare
        // text with a numeric subquery, so the ids go in as strings.
        $tokens = $this->tokensOf($user)->pluck($this->tokensOf($user)->getModel()->getKeyName())
            ->map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '')
            ->all();

        $key = $user->getKey();
        $actorId = is_scalar($key) ? (string) $key : '';

        return McpAuditEntry::query()->where(function (Builder $query) use ($user, $actorId, $tokens): void {
            $query->where(function (Builder $actor) use ($user, $actorId): void {
                $actor->where('actor_type', $user::class)->where('actor_id', $actorId);
            })->orWhere(function (Builder $mine) use ($tokens): void {
                $mine->where('token_type', (new McpToken)->getMorphClass())->whereIn('token_id', $tokens);
            });
        });
    }
}
