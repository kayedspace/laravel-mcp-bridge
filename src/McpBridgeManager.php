<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge;

use Composer\InstalledVersions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use Throwable;

class McpBridgeManager
{
    public const PACKAGE = 'kayedspace/laravel-mcp-bridge';

    /**
     * The installed version, as Composer records it, for the server's
     * `serverInfo` and `php artisan about`. A hard-coded string was wrong the
     * moment after the first tag.
     */
    public static function version(): string
    {
        if (! InstalledVersions::isInstalled(self::PACKAGE)) {
            return '0.0.0';
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? '0.0.0';
    }

    /**
     * Whether this package's own migrations run from inside the package.
     *
     * They do by default, so `php artisan migrate` works with no publishing
     * step. An application that publishes them to own them calls
     * `McpBridgeManager::ignoreMigrations()` in a service provider, because
     * the published copies carry fresh timestamps and both sets would
     * otherwise try to create the same tables.
     */
    protected static bool $runsMigrations = true;

    protected ?Authenticatable $actor = null;

    public function __construct(
        protected ManifestRepository $manifest,
        protected AuthFactory $auth,
        protected Container $container,
    ) {}

    public function manifest(): ManifestRepository
    {
        return $this->manifest;
    }

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    public static function runsMigrations(): bool
    {
        return self::$runsMigrations;
    }

    /**
     * Run a callback with the agent's own user resolved as the authenticated
     * user, including on the default guard.
     *
     * This is what keeps a command tool from running as nobody. Inside a
     * command, `auth()->id()` is normally empty, and code that reads "no user"
     * as "system, allow everything" is common, so an agent-triggered command
     * would quietly get more authority than the agent has. Here any policy
     * check, authorize() call or observer inside the command sees the real,
     * non-privileged caller.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function actingAs(Authenticatable $actor, callable $callback): mixed
    {
        $previous = $this->actor;
        $this->actor = $actor;

        try {
            $this->auth->guard()->setUser($actor);

            return $callback();
        } finally {
            $this->actor = $previous;
            $this->restore($previous);
        }
    }

    /**
     * Put the guard back as it was. Leaving the agent's user set on it would
     * outlive the call, so a guard that cannot be reset is still cleared.
     */
    protected function restore(?Authenticatable $previous): void
    {
        try {
            $guard = $this->auth->guard();

            if ($previous !== null) {
                $guard->setUser($previous);

                return;
            }

            if (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        } catch (Throwable) {
            // An application whose default guard cannot hold a user at all.
        }
    }

    /** Who the current tool call is running as, if a call is in flight. */
    public function actor(): ?Authenticatable
    {
        return $this->actor;
    }

    /**
     * Whether the current request is an MCP tool call, so application code can
     * vary behaviour without forking a controller.
     */
    public function isMcpCall(?Request $request = null): bool
    {
        return $this->currentTool($request) !== null;
    }

    /**
     * The agent a route tool call came from, as its client named itself. The
     * header is only believed on a tool call: any REST client can send it.
     */
    public function currentClient(?Request $request = null): ?string
    {
        $request ??= $this->container->make(Request::class);

        if (! $this->isMcpCall($request)) {
            return null;
        }

        $client = $request->headers->get('X-Mcp-Client');

        return is_string($client) && $client !== '' ? $client : null;
    }

    public function currentTool(?Request $request = null): ?string
    {
        $request ??= $this->container->make(Request::class);

        $tool = $request->attributes->get('mcp.tool');

        return is_string($tool) ? $tool : null;
    }
}
