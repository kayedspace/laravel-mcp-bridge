<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Security;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Auth\UserProvider;
use InvalidArgumentException;
use KayedSpace\McpBridge\Server\ServerKeyResolver;

/**
 * Who a call runs as.
 *
 * Two strategies, because two things connect. A person's AI client carries
 * that person's token, and the call should be theirs: that is `passthrough`,
 * and it is the default. A server-to-server agent, an n8n flow or a nightly
 * recalculation is nobody's personal action, and pretending it belongs to
 * whoever issued the token misattributes everything it does: that is
 * `service-account`.
 *
 * A service account is a real user record with real policies, never a
 * convention-based superuser. The whole authority model answers questions
 * through the application's own Gate, and a fabricated actor has nothing for
 * the Gate to answer about. So a configured account that does not resolve
 * fails the call rather than falling back to the token's user: silently
 * reverting to passthrough would run an automation as a person.
 *
 * A stdio server is the third case, and it is `passthrough` with nobody to
 * pass through: a subprocess carries no token, so under the default settings
 * every local call was refused as unauthenticated. `security.local_actor`
 * names who those calls run as, read only when serving locally and only when
 * nothing authenticated the caller, so it can never widen an HTTP surface.
 */
class ActorResolver
{
    public function __construct(
        protected Repository $config,
        protected Auth $auth,
    ) {}

    /**
     * The actor for this call, or null when there is none to be had, which is
     * the one case a tool refuses rather than guesses.
     */
    public function resolve(?Authenticatable $authenticated): ?Authenticatable
    {
        if ($this->usesServiceAccount()) {
            return $this->user($this->serviceAccountIdentifier());
        }

        if ($authenticated === null && ServerKeyResolver::servingLocally()) {
            return $this->user($this->localIdentifier());
        }

        return $authenticated;
    }

    /**
     * Why no actor could be resolved, for the message a developer reads
     * instead of a silent refusal.
     */
    public function problem(): ?string
    {
        if ($this->usesServiceAccount()) {
            return $this->serviceAccountProblem();
        }

        return ServerKeyResolver::servingLocally() ? $this->localProblem() : null;
    }

    /**
     * What is wrong with the stdio actor, whether or not this process is a
     * stdio server: `mcp-bridge:doctor` asks before anyone connects.
     */
    public function localProblem(): ?string
    {
        $identifier = $this->localIdentifier();

        if ($identifier === null) {
            return 'This server is served over stdio, where there is no token to name a caller. '
                .'Set [mcp-bridge.security.local_actor] to the id or email of the user it runs as.';
        }

        return $this->user($identifier) === null
            ? "This stdio server runs as [{$identifier}], and no such user exists."
            : null;
    }

    protected function serviceAccountProblem(): ?string
    {
        $identifier = $this->serviceAccountIdentifier();

        if ($identifier === null) {
            return 'This server runs as a service account, but [mcp-bridge.security.service_account] names nobody.';
        }

        return $this->user($identifier) === null
            ? "This server runs as the service account [{$identifier}], and no such user exists."
            : null;
    }

    protected function usesServiceAccount(): bool
    {
        return $this->config->string('mcp-bridge.security.auth', 'passthrough') === 'service-account';
    }

    /**
     * Resolved through the guard's own user provider, so an application with
     * a custom provider is not required to be using Eloquent for this to
     * work. An email is looked up as a credential and anything else as the
     * id, decided before the query rather than by trying the id first:
     * PostgreSQL refuses an email compared against an integer key instead of
     * answering that no row matched.
     */
    protected function user(?string $identifier): ?Authenticatable
    {
        if ($identifier === null) {
            return null;
        }

        $provider = $this->provider();

        if ($provider === null) {
            return null;
        }

        return str_contains($identifier, '@')
            ? $provider->retrieveByCredentials(['email' => $identifier])
            : $provider->retrieveById($identifier);
    }

    protected function provider(): ?UserProvider
    {
        foreach ($this->guards() as $guard) {
            try {
                $instance = $this->auth->guard($guard);
            } catch (InvalidArgumentException) {
                // A guard the configuration names and the application never
                // defined is doctor's finding, not a reason to stop looking.
                continue;
            }

            // A guard that cannot name its provider cannot be asked for a
            // user by id, which is the whole operation here. Sanctum's
            // request guard answers with no provider at all, so the next
            // guard is asked rather than the lookup given up.
            $provider = method_exists($instance, 'getProvider') ? $instance->getProvider() : null;

            if ($provider instanceof UserProvider) {
                return $provider;
            }
        }

        // Every guard declined. The application's default guard fronts its
        // users, whatever the package's guards were given; a configured
        // default provider is not something a Laravel 11 application has.
        $default = $this->auth->guard();
        $provider = method_exists($default, 'getProvider') ? $default->getProvider() : null;

        return $provider instanceof UserProvider ? $provider : null;
    }

    /** @return list<string> */
    protected function guards(): array
    {
        $guards = array_values(array_filter(
            $this->config->array('mcp-bridge.security.guards', []),
            'is_string',
        ));

        return $guards === [] ? [$this->config->string('auth.defaults.guard', 'web')] : $guards;
    }

    protected function serviceAccountIdentifier(): ?string
    {
        return $this->identifier('mcp-bridge.security.service_account');
    }

    protected function localIdentifier(): ?string
    {
        return $this->identifier('mcp-bridge.security.local_actor');
    }

    protected function identifier(string $key): ?string
    {
        $identifier = $this->config->get($key);

        return is_scalar($identifier) && (string) $identifier !== '' ? (string) $identifier : null;
    }
}
