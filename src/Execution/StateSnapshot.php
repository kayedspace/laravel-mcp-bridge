<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * Captures the state a sub-request is about to overwrite, and puts it back
 * afterwards.
 *
 * Without this, one tool call leaks its request, its resolved user and its
 * URL generator state into the next one, which is how a long-lived worker
 * under Octane starts answering with the wrong user's data.
 */
final class StateSnapshot
{
    private function __construct(
        private readonly Application $app,
        private readonly ?Request $request,
        private readonly ?Authenticatable $user,
    ) {}

    public static function capture(Application $app): self
    {
        return new self($app, self::currentRequest($app), self::currentUser($app));
    }

    public function restore(): void
    {
        if ($this->request === null) {
            $this->app->forgetInstance('request');
        } else {
            $this->app->instance('request', $this->request);
            $this->setUrlRequest($this->request);
        }

        // The kernel clears the facade's cached instance on the way into a
        // sub-request and the facade then caches that sub-request, so the
        // outer one has to be put back here as well as in the container.
        Facade::clearResolvedInstance('request');

        try {
            $guard = $this->app->make('auth')->guard();

            $this->user instanceof Authenticatable
                ? $guard->setUser($this->user)
                : $guard->forgetUser();
        } catch (Throwable) {
            // An application without an auth guard has no user state to put back.
        }
    }

    private function setUrlRequest(Request $request): void
    {
        try {
            $this->app->make('url')->setRequest($request);
        } catch (Throwable) {
            // The URL generator is optional in a bare container.
        }
    }

    private static function currentRequest(Application $app): ?Request
    {
        return $app->bound('request') ? $app->make('request') : null;
    }

    private static function currentUser(Application $app): ?Authenticatable
    {
        try {
            // hasUser() avoids triggering an authentication attempt just to
            // find out whether one has already happened.
            $guard = $app->make('auth')->guard();

            return $guard->hasUser() ? $guard->user() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
