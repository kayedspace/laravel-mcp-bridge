<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Config\Repository;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use KayedSpace\McpBridge\Audit\DatabaseAuditLogger;
use KayedSpace\McpBridge\Audit\LogAuditLogger;
use KayedSpace\McpBridge\Audit\NullAuditLogger;
use KayedSpace\McpBridge\Console\CacheCommand;
use KayedSpace\McpBridge\Console\CallCommand;
use KayedSpace\McpBridge\Console\ClearCommand;
use KayedSpace\McpBridge\Console\ConnectCommand;
use KayedSpace\McpBridge\Console\DescribeCommand;
use KayedSpace\McpBridge\Console\DoctorCommand;
use KayedSpace\McpBridge\Console\ExportCommand;
use KayedSpace\McpBridge\Console\InstallCommand;
use KayedSpace\McpBridge\Console\KillCommand;
use KayedSpace\McpBridge\Console\LintCommand;
use KayedSpace\McpBridge\Console\PruneAuditCommand;
use KayedSpace\McpBridge\Console\PruneTokensCommand;
use KayedSpace\McpBridge\Console\PruneUploadsCommand;
use KayedSpace\McpBridge\Console\TokenCommand;
use KayedSpace\McpBridge\Console\TokensCommand;
use KayedSpace\McpBridge\Console\ToolsCommand;
use KayedSpace\McpBridge\Contracts\AuthorizesTools;
use KayedSpace\McpBridge\Contracts\ConnectsRemoteServers;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Contracts\ReadsDocumentation;
use KayedSpace\McpBridge\Contracts\ReadsQueryDefinitions;
use KayedSpace\McpBridge\Contracts\StoresAudit;
use KayedSpace\McpBridge\Doctor\Diagnostics\CacheStoreIsShared;
use KayedSpace\McpBridge\Doctor\Diagnostics\CallMiddlewareExists;
use KayedSpace\McpBridge\Doctor\Diagnostics\GuardsAreDefined;
use KayedSpace\McpBridge\Doctor\Diagnostics\KillSwitchIsClear;
use KayedSpace\McpBridge\Doctor\Diagnostics\ManifestIsCurrent;
use KayedSpace\McpBridge\Doctor\Diagnostics\PackageIsEnabled;
use KayedSpace\McpBridge\Doctor\Diagnostics\RateLimitersAreDefined;
use KayedSpace\McpBridge\Doctor\Diagnostics\ServersAreRouted;
use KayedSpace\McpBridge\Doctor\Diagnostics\StdioActorResolves;
use KayedSpace\McpBridge\Doctor\Diagnostics\TablesExist;
use KayedSpace\McpBridge\Execution\RouteExecutor;
use KayedSpace\McpBridge\Http\Controllers\PanelActivityController;
use KayedSpace\McpBridge\Http\Controllers\PanelTokensController;
use KayedSpace\McpBridge\Http\Middleware\Authorize;
use KayedSpace\McpBridge\Integrations\DocumentationReader;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Modules\DirectoryLocator;
use KayedSpace\McpBridge\Modules\ModuleRegistry;
use KayedSpace\McpBridge\Modules\NwidartLocator;
use KayedSpace\McpBridge\Modules\PathRepositoryLocator;
use KayedSpace\McpBridge\Query\QueryReader;
use KayedSpace\McpBridge\Remote\RemoteServers;
use KayedSpace\McpBridge\Security\ScopeGate;
use KayedSpace\McpBridge\Security\TokenGuard;
use KayedSpace\McpBridge\Server\GeneratedServer;
use KayedSpace\McpBridge\Server\ServerKeyResolver;
use KayedSpace\McpBridge\Sources\ActionSource;
use KayedSpace\McpBridge\Sources\CommandSource;
use KayedSpace\McpBridge\Sources\JobSource;
use KayedSpace\McpBridge\Sources\ModelSource;
use KayedSpace\McpBridge\Sources\OpenApiSource;
use KayedSpace\McpBridge\Sources\RemoteSource;
use KayedSpace\McpBridge\Sources\RouteSource;
use KayedSpace\McpBridge\Sources\SourceRegistry;
use Laravel\Doctor\Facades\Doctor;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider;
use RuntimeException;

class McpBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // This package is a layer on laravel/mcp, including its container
        // callbacks that carry tool arguments into a handler. Registering it
        // explicitly is idempotent, and means discovery order cannot leave
        // generated tools receiving empty arguments.
        $this->app->register(McpServiceProvider::class);

        $this->mergeConfigFrom(__DIR__.'/../config/mcp-bridge.php', 'mcp-bridge');

        $this->app->singleton(ManifestRepository::class);
        $this->app->singleton(McpBridgeManager::class);
        $this->app->singleton(SourceRegistry::class);

        $this->app->singleton(ModuleRegistry::class, fn (): ModuleRegistry => new ModuleRegistry(
            locators: [
                'nwidart' => $this->app->make(NwidartLocator::class),
                'path-repositories' => $this->app->make(PathRepositoryLocator::class),
                'directories' => $this->app->make(DirectoryLocator::class),
            ],
            config: $this->app->make(Repository::class),
        ));

        $this->app->bind(ExecutesTools::class, RouteExecutor::class);
        $this->app->bind(AuthorizesTools::class, ScopeGate::class);
        $this->app->bind(ReadsQueryDefinitions::class, QueryReader::class);
        $this->app->bind(ReadsDocumentation::class, DocumentationReader::class);
        $this->app->bind(ConnectsRemoteServers::class, RemoteServers::class);

        $this->app->bind(StoresAudit::class, function (): StoresAudit {
            $driver = $this->app->make(Repository::class)->string('mcp-bridge.audit.driver', 'database');

            return match ($driver) {
                'log' => $this->app->make(LogAuditLogger::class),
                'none' => $this->app->make(NullAuditLogger::class),
                default => $this->app->make(DatabaseAuditLogger::class),
            };
        });

        $this->app->afterResolving(SourceRegistry::class, function (SourceRegistry $registry): void {
            $registry->register($this->app->make(RouteSource::class));
            $registry->register($this->app->make(CommandSource::class));
            $registry->register($this->app->make(JobSource::class));
            $registry->register($this->app->make(ActionSource::class));
            $registry->register($this->app->make(ModelSource::class));
            $registry->register($this->app->make(RemoteSource::class));
            $registry->register($this->app->make(OpenApiSource::class));
        });
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerGuard();
        $this->registerServers();
        $this->rememberLocalServer();

        if (McpBridgeManager::runsMigrations()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // The Filament page's view and the token panel's. Registering a view
        // namespace costs nothing when nothing renders it.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mcp-bridge');
        $this->registerPanel();
        $this->registerDiagnostics();

        if ($this->app->runningInConsole()) {
            $this->registerAbout();

            // `php artisan optimize` is the deploy step most images already
            // run, so the manifest is written there too, and cleared with
            // the rest, instead of depending on a line in a deploy script.
            $this->optimizes(optimize: 'mcp-bridge:cache', clear: 'mcp-bridge:clear', key: 'mcp-bridge');

            $this->publishes([
                __DIR__.'/../config/mcp-bridge.php' => $this->app->configPath('mcp-bridge.php'),
            ], ['mcp-bridge', 'mcp-bridge-config']);

            // Published copies get fresh timestamps, so an application that
            // takes them must also call McpBridgeManager::ignoreMigrations().
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], ['mcp-bridge', 'mcp-bridge-migrations']);

            $this->commands([
                CacheCommand::class,
                CallCommand::class,
                ClearCommand::class,
                ConnectCommand::class,
                DoctorCommand::class,
                ExportCommand::class,
                InstallCommand::class,
                KillCommand::class,
                LintCommand::class,
                PruneAuditCommand::class,
                PruneUploadsCommand::class,
                PruneTokensCommand::class,
                TokenCommand::class,
                TokensCommand::class,
                ToolsCommand::class,
                DescribeCommand::class,
            ]);
        }
    }

    /**
     * The token panel's three routes, under the configured path and
     * middleware. Always registered: the enabled switch is read by the
     * middleware on each request, so it holds with a cached route table and
     * can be flipped without rebuilding it.
     */
    protected function registerPanel(): void
    {
        $config = $this->app->make(Repository::class);

        Route::middleware($config->array('mcp-bridge.panel.middleware', ['web', Authorize::class]))
            ->prefix($config->string('mcp-bridge.panel.path', 'mcp-bridge'))
            ->name('mcp-bridge.panel.')
            ->group(function (): void {
                Route::get('/', [PanelTokensController::class, 'index'])->name('tokens.index');
                Route::post('tokens', [PanelTokensController::class, 'store'])->name('tokens.store');
                Route::delete('tokens/{token}', [PanelTokensController::class, 'destroy'])->name('tokens.destroy');
                Route::get('activity', [PanelActivityController::class, 'index'])->name('activity');
            });
    }

    /**
     * This package's checks beside the framework's in `php artisan doctor`,
     * when `laravel/doctor` is installed. The checks are `Checkup`'s, the
     * same ones `mcp-bridge:doctor` prints, so an operator who already runs
     * doctor has nothing new to learn.
     */
    protected function registerDiagnostics(): void
    {
        if (! class_exists(Doctor::class)) {
            return;
        }

        Doctor::diagnostics([
            PackageIsEnabled::class,
            ServersAreRouted::class,
            GuardsAreDefined::class,
            RateLimitersAreDefined::class,
            StdioActorResolves::class,
            CacheStoreIsShared::class,
            CallMiddlewareExists::class,
            ManifestIsCurrent::class,
            TablesExist::class,
            KillSwitchIsClear::class,
        ]);
    }

    /**
     * The section `php artisan about` prints, which is where an operator
     * looks first on a box they did not set up.
     */
    protected function registerAbout(): void
    {
        AboutCommand::add('MCP Bridge', function (): array {
            $config = $this->app->make(Repository::class);
            $manifest = $this->app->make(ManifestRepository::class);
            $servers = array_keys($config->array('mcp-bridge.servers', []));

            return [
                'Version' => McpBridgeManager::version(),
                'Mode' => $config->string('mcp-bridge.mode', 'opt-in'),
                'Servers' => implode(', ', array_map('strval', $servers)),
                'Manifest' => match (true) {
                    $manifest->isCurrent() => count($manifest->all()).' tools, cached',
                    $manifest->isCached() => 'written by another version; run mcp-bridge:cache',
                    default => 'compiled on demand',
                },
                'Audit' => $config->string('mcp-bridge.audit.driver', 'database'),
                'Stdio' => $config->boolean('mcp-bridge.local', false) ? 'on' : 'off',
            ];
        });
    }

    /**
     * The shipped configuration protects the operations server with
     * `auth:mcp-bridge`, so the package provides that guard: both the driver
     * and, unless the application defined one itself, the guard entry that
     * names it. A guard the configuration points at and nothing defines is a
     * 500 on the first connection.
     */
    protected function registerGuard(): void
    {
        Auth::extend('mcp-bridge', function (Application $app, string $name, array $config): TokenGuard {
            $name = $config['provider'] ?? null;
            $provider = Auth::createUserProvider(is_string($name) ? $name : null);

            if ($provider === null) {
                throw new RuntimeException(
                    'The [mcp-bridge] guard needs a user provider. Name one in auth.guards.mcp-bridge.provider.',
                );
            }

            return new TokenGuard(
                provider: $provider,
                request: $app->make('request'),
                prefix: $app->make(Repository::class)->string('mcp-bridge.tokens.prefix', 'mcpb_'),
            );
        });

        $config = $this->app->make(Repository::class);

        if ($config->get('auth.guards.mcp-bridge') === null) {
            $config->set('auth.guards.mcp-bridge', [
                'driver' => 'mcp-bridge',
                'provider' => $config->get('auth.defaults.provider', 'users'),
            ]);
        }
    }

    /**
     * The shipped configuration points at `throttle:mcp` and
     * `throttle:mcp-ops`, so the package defines them. An application that
     * declares either name itself keeps its own definition, because its
     * providers boot after this one.
     */
    protected function registerRateLimiters(): void
    {
        foreach (['mcp' => 60, 'mcp-ops' => 20] as $name => $perMinute) {
            if (RateLimiter::limiter($name) !== null) {
                continue;
            }

            RateLimiter::for($name, function (Request $request) use ($perMinute): Limit {
                $user = $request->user();
                $identifier = $user === null ? $request->ip() : $user->getAuthIdentifier();

                return Limit::perMinute($perMinute)->by(
                    is_scalar($identifier) ? (string) $identifier : 'unknown',
                );
            });
        }
    }

    /**
     * Which server a stdio process is serving.
     *
     * An HTTP connection names its server by the path it arrived on. A stdio
     * one has no request at all, only the handle `mcp:start` was given, so
     * the handle is read off that command as it starts. Without this, every
     * local server served the default one's tools.
     *
     * Read from the command rather than set when a server is resolved: a
     * callback per registered handle would fire for all of them and leave
     * whichever ran last, which is how the first attempt at this made every
     * HTTP request serve the operations surface.
     */
    protected function rememberLocalServer(): void
    {
        Event::listen(function (CommandStarting $event): void {
            if ($event->command !== 'mcp:start') {
                return;
            }

            $handle = $event->input->getArgument('handle');

            if (is_string($handle) && $handle !== '') {
                ServerKeyResolver::serving($handle);
            }
        });
    }

    /**
     * Every configured server becomes a laravel/mcp endpoint, each with its
     * own middleware, so an operations token and an API token reach different
     * surfaces rather than the same one with different filtering.
     */
    protected function registerServers(): void
    {
        $config = $this->app->make(Repository::class);

        if (! $config->boolean('mcp-bridge.enabled', true)) {
            return;
        }

        foreach ($config->array('mcp-bridge.servers', []) as $key => $server) {
            if (! is_array($server)) {
                continue;
            }

            $path = $server['path'] ?? null;

            if (is_string($path) && $path !== '') {
                $middleware = is_array($server['middleware'] ?? null)
                    ? array_values(array_filter($server['middleware'], 'is_string'))
                    : [];

                Mcp::web($path, GeneratedServer::class)->middleware($middleware);
            }

            // The same server over stdio, under the server's own key as the
            // handle, which is what `rememberLocalServer()` reads back. There
            // is no middleware on this transport: whoever can run `php
            // artisan` is already inside the application, so the authority
            // that matters is the actor, which `security.auth` and
            // `security.local_actor` decide. Off unless asked for, like the
            // shipped file says.
            if ($config->boolean('mcp-bridge.local', false)) {
                Mcp::local((string) $key, GeneratedServer::class);
            }
        }
    }
}
