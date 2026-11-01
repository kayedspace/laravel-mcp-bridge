<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Security;

use Illuminate\Contracts\Auth\Authenticatable;
use KayedSpace\McpBridge\Models\McpToken;

/**
 * Whether a token holds a scope.
 *
 * Smaller than the gate on purpose. The gate answers a whole question about
 * a tool, kill switch and abilities and rate limit and confirmation
 * included, and it needs an encrypter to do it. An app resource asks only
 * the scope part, and making it depend on the gate to get there drags the
 * rest along for nothing.
 *
 * The rule that matters is here rather than in either caller: a guard that
 * cannot report token abilities fails closed. Two implementations of that
 * would be two chances to get it the wrong way round.
 *
 * The package's own token is asked first, through `currentMcpToken()`, a
 * name nothing else declares. Sanctum's trait declares `tokenCan()`, and a
 * trait of ours declaring it too made the two a fatal collision on the one
 * model every application adds both to. So `tokenCan()` is only ever a
 * fallback here, for a Sanctum or Passport user, and never ours.
 */
class TokenScopes
{
    /**
     * The reason these scopes are not held, or null when they are.
     *
     * `$subject` only shapes the sentence, because the same refusal reads
     * differently for a tool and for a resource.
     *
     * @param  list<string>  $scopes
     */
    public function refusalFor(array $scopes, ?Authenticatable $actor, string $subject = 'tool'): ?string
    {
        if ($scopes === []) {
            return null;
        }

        $needs = "This {$subject} needs the scope ".implode(' and ', $scopes);

        if ($actor === null) {
            return $needs.', and the caller is not authenticated.';
        }

        $token = method_exists($actor, 'currentMcpToken') ? $actor->currentMcpToken() : null;

        if ($token instanceof McpToken) {
            return $this->missing($scopes, fn (string $scope): bool => $token->can($scope), $subject);
        }

        // Sanctum and Passport both answer this; a guard that cannot report
        // token abilities fails closed rather than being assumed to allow.
        if (! method_exists($actor, 'tokenCan')) {
            // A user read from a session or a factory carries no credential,
            // and answering yes would turn a token check into no check.
            return method_exists($actor, 'currentMcpToken')
                ? $needs.', and the caller holds no token.'
                : $needs.', but the current guard cannot report token abilities.';
        }

        return $this->missing($scopes, fn (string $scope): bool => $actor->tokenCan($scope) === true, $subject);
    }

    /** @param list<string> $scopes */
    public function holds(?Authenticatable $actor, array $scopes, string $subject = 'tool'): bool
    {
        return $this->refusalFor($scopes, $actor, $subject) === null;
    }

    /**
     * @param  list<string>  $scopes
     * @param  callable(string): bool  $held
     */
    protected function missing(array $scopes, callable $held, string $subject): ?string
    {
        foreach ($scopes as $scope) {
            if (! $held($scope)) {
                return "This {$subject} needs the scope [{$scope}].";
            }
        }

        return null;
    }
}
