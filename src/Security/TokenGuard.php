<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Security;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use KayedSpace\McpBridge\Models\McpToken;

/**
 * The guard behind `auth:mcp-bridge`.
 *
 * It answers one question: which user is this bearer token, and is the token
 * still good. Scopes are not checked here, because the answer depends on which
 * tool is being called; the guard's job is to put the token on the user so the
 * authorization layer can ask.
 *
 * The shipped configuration points the operations server at this guard, so the
 * package provides it. An application that would rather use Sanctum points its
 * own configuration there instead and nothing here is in the way.
 */
class TokenGuard implements Guard
{
    protected ?Authenticatable $user = null;

    protected bool $resolved = false;

    public function __construct(
        protected UserProvider $provider,
        protected Request $request,
        protected string $prefix = 'mcpb_',
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The provider this guard was built with, so a caller that needs a user
     * by id or email, the stdio actor and the service account, can ask the
     * same provider the guard would.
     */
    public function getProvider(): UserProvider
    {
        return $this->provider;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        return $this->user = $this->resolve();
    }

    public function id(): int|string|null
    {
        $id = $this->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials = []): bool
    {
        $token = $credentials['token'] ?? null;

        return is_string($token) && $this->userFor($token) !== null;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->resolved = true;

        return $this;
    }

    protected function resolve(): ?Authenticatable
    {
        $plain = $this->presented();

        return $plain === null ? null : $this->userFor($plain);
    }

    protected function userFor(string $plain): ?Authenticatable
    {
        // A token that is not ours is not an error: another guard in the stack
        // may well recognise it.
        if ($this->prefix !== '' && ! str_starts_with($plain, $this->prefix)) {
            return null;
        }

        $token = McpToken::findByPlainText($plain);

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        // The token's own owner, by the morph it was issued to, never the
        // provider's model by that id: an application with a second user
        // model would otherwise hand a token for one record to another.
        $user = $token->tokenable;

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $token->markUsed();

        // The token travels with the user, because the scope check needs both
        // and only the guard sees them together. The trait sets it on the
        // user it is called on, so the same object goes back either way.
        if (method_exists($user, 'withMcpToken')) {
            $user->withMcpToken($token);
        }

        return $user;
    }

    protected function presented(): ?string
    {
        $bearer = $this->request->bearerToken();

        return is_string($bearer) && $bearer !== '' ? $bearer : null;
    }
}
