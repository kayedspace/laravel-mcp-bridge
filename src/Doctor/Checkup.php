<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Models\McpKill;
use KayedSpace\McpBridge\Models\McpToken;
use KayedSpace\McpBridge\Security\ActorResolver;
use KayedSpace\McpBridge\Security\KillSwitch;
use KayedSpace\McpBridge\Server\GeneratedServer;
use ReflectionFunction;
use Throwable;

/**
 * The things that are only wrong once, in production, at the worst moment:
 * a guard the configuration names and nothing defines, a rate limiter that
 * does not exist, a manifest that was never built, a missing table.
 *
 * One place, read by `mcp-bridge:doctor` and by the diagnostics registered
 * with `laravel/doctor`, so the two never disagree about what healthy is.
 * Every check answers with what to do rather than only what is wrong.
 */
class Checkup
{
    public function __construct(
        protected Application $app,
        protected Repository $config,
        protected Router $router,
        protected RateLimiter $limiter,
        protected ManifestRepository $manifest,
        protected KillSwitch $kill,
        protected ActorResolver $actors,
    ) {}

    /** @return list<Check> */
    public function all(): array
    {
        return array_values(array_filter([
            $this->enabled(),
            ...$this->servers(),
            ...$this->guards(),
            ...$this->limiters(),
            $this->localActor(),
            $this->cache(),
            ...$this->middleware(),
            $this->manifest(),
            ...$this->tables(),
            $this->killSwitch(),
        ]));
    }

    public function enabled(): Check
    {
        $enabled = $this->config->boolean('mcp-bridge.enabled', true);

        return new Check('Package enabled', $enabled, $enabled ? '' : 'set MCP_BRIDGE_ENABLED=true');
    }

    /** @return list<Check> */
    public function servers(): array
    {
        $checks = [];

        foreach ($this->config->array('mcp-bridge.servers', []) as $key => $server) {
            $path = is_array($server) ? ($server['path'] ?? null) : null;

            if (! is_string($path)) {
                $checks[] = new Check("Server [{$key}]", false, 'has no path');

                continue;
            }

            $registered = false;
            $other = null;

            foreach ($this->router->getRoutes()->getRoutes() as $route) {
                if ('/'.ltrim($route->uri(), '/') !== $path) {
                    continue;
                }

                $registered = true;

                if (in_array('POST', $route->methods(), true)) {
                    $other = $this->foreignServer($route);
                }
            }

            $checks[] = match (true) {
                ! $registered => new Check("Server [{$key}]", false, "{$path} is not routed"),
                $other !== null => new Check("Server [{$key}]", false, "{$path} is served by [{$other}], so this server is not reachable; set servers.{$key}.path to a free path"),
                default => new Check("Server [{$key}]", true, $path),
            };
        }

        return $checks;
    }

    /**
     * The MCP server class a route starts, when it is not this package's:
     * laravel/mcp registers a web server as a closure that captures the
     * class, and an application's own server registered at the same path
     * silently replaces ours.
     */
    protected function foreignServer(Route $route): ?string
    {
        $uses = $route->getAction('uses');

        if (! $uses instanceof Closure) {
            return null;
        }

        $class = (new ReflectionFunction($uses))->getClosureUsedVariables()['serverClass'] ?? null;

        return is_string($class) && ! is_a($class, GeneratedServer::class, true) ? $class : null;
    }

    /** @return list<Check> */
    public function guards(): array
    {
        $checks = [];

        foreach ($this->middlewareWithPrefix('auth:') as $guard) {
            $defined = $this->config->get("auth.guards.{$guard}") !== null;

            $checks[] = new Check("Guard [{$guard}]", $defined, $defined ? '' : 'define it in config/auth.php');
        }

        return $checks;
    }

    /** @return list<Check> */
    public function limiters(): array
    {
        $checks = [];

        foreach ($this->middlewareWithPrefix('throttle:') as $limiter) {
            $defined = $this->limiter->limiter($limiter) !== null;

            $checks[] = new Check("Rate limiter [{$limiter}]", $defined, $defined ? '' : 'define it in a service provider');
        }

        return $checks;
    }

    /**
     * A stdio server with nobody to run as refuses every call, and the
     * refusal arrives in a client's log rather than on this screen. Asked
     * here, before anyone connects. Null when stdio is off.
     */
    public function localActor(): ?Check
    {
        if (! $this->config->boolean('mcp-bridge.local', false)) {
            return null;
        }

        if ($this->config->string('mcp-bridge.security.auth', 'passthrough') === 'service-account') {
            return new Check('Stdio actor', true, 'the service account');
        }

        $problem = $this->actors->localProblem();

        return new Check(
            'Stdio actor',
            $problem === null,
            $problem === null ? 'security.local_actor' : 'set security.local_actor to a user that exists',
        );
    }

    /**
     * Confirmation handles are spent, idempotency keys claimed and job
     * statuses kept in the cache, and each of those is a promise only a
     * store every worker shares can keep. The array store keeps it for one
     * request and the null store for nobody.
     */
    public function cache(): Check
    {
        $store = $this->config->string('cache.default', 'array');
        $driver = $this->config->string("cache.stores.{$store}.driver", $store);
        $shared = ! in_array($driver, ['array', 'null'], true);

        return new Check(
            "Cache store [{$store}]",
            $shared,
            $shared ? $driver : 'confirmation handles and idempotency keys need a store every worker shares',
        );
    }

    /**
     * A middleware named in the configuration and missing from the code
     * stops every call, so it is the kind of thing to hear about before a
     * client does.
     *
     * @return list<Check>
     */
    public function middleware(): array
    {
        $checks = [];

        foreach ($this->config->array('mcp-bridge.execution.middleware', []) as $class) {
            $name = is_scalar($class) ? (string) $class : gettype($class);
            $exists = is_string($class) && class_exists($class) && method_exists($class, 'handle');

            $checks[] = new Check("Call middleware [{$name}]", $exists, $exists ? '' : 'no such class with a handle() method');
        }

        return $checks;
    }

    /**
     * Not cached is not broken: the manifest is compiled on demand, and
     * outside production that is the state a developer editing declarations
     * wants. It is a discovery pass on every process start, which is what
     * the file is there to avoid, so production is told to write it. A file
     * another version of the package wrote is read as absent at run time,
     * so it is reported that way here too.
     */
    public function manifest(): Check
    {
        $cached = $this->manifest->isCurrent();
        $stale = ! $cached && $this->manifest->isCached();
        $wanted = $this->config->boolean('mcp-bridge.discovery.cache', true);
        $production = $this->app->isProduction();

        return new Check(
            'Compiled manifest',
            $cached || (! $stale && (! $wanted || ! $production)),
            match (true) {
                $cached => count($this->manifest->all()).' tools',
                $stale => 'written by another version of the package; run mcp-bridge:cache',
                ! $wanted => 'discovery.cache is off, so it is compiled on every process start',
                $production => 'compiled on demand until you run mcp-bridge:cache',
                default => 'compiled on demand; run mcp-bridge:cache in your deploy',
            },
        );
    }

    /** @return list<Check> */
    public function tables(): array
    {
        $checks = [];

        foreach (['tokens' => McpToken::class, 'audit' => McpAuditEntry::class, 'kills' => McpKill::class] as $name => $model) {
            try {
                $model::query()->count();
                $checks[] = new Check("Table for {$name}", true, '');
            } catch (Throwable) {
                $checks[] = new Check("Table for {$name}", false, 'run migrate');
            }
        }

        return $checks;
    }

    public function killSwitch(): Check
    {
        $killed = $this->kill->killed();

        return new Check(
            'Kill switch',
            $killed === [],
            $killed === [] ? 'nothing switched off' : 'off: '.implode(', ', $killed),
        );
    }

    /**
     * What every configured server's middleware names after a prefix, which
     * is how `auth:mcp-bridge` and `throttle:mcp-ops` say which guard and
     * which limiter have to exist.
     *
     * @return list<string>
     */
    protected function middlewareWithPrefix(string $prefix): array
    {
        $names = [];

        foreach ($this->config->array('mcp-bridge.servers', []) as $server) {
            $middleware = is_array($server) ? ($server['middleware'] ?? []) : [];

            foreach (is_array($middleware) ? $middleware : [] as $entry) {
                if (is_string($entry) && str_starts_with($entry, $prefix)) {
                    $names[] = substr($entry, strlen($prefix));
                }
            }
        }

        return array_values(array_unique($names));
    }
}
