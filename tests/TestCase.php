<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests;

use Dedoc\Scramble\ScrambleServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\McpBridgeServiceProvider;
use KayedSpace\McpBridge\Server\ServerKeyResolver;
use KayedSpace\McpBridge\Testing\InteractsWithMcp;
use KayedSpace\McpBridge\Tests\Fixtures\ParallelManifestRepository;
use KayedSpace\McpBridge\Tests\Fixtures\Policies\CoursePolicy;
use Laravel\Doctor\DoctorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    // Every test gets its own transaction, rolled back afterwards. SQLite in
    // memory is fresh per test anyway; a real database is not, and the
    // tables a test creates have to go with it. PostgreSQL rolls DDL back,
    // which is what lets the same suite run against it.
    use DatabaseTransactions;

    // The helpers this package ships for other applications, used by its own
    // suite: anything that does not survive that is not worth shipping.
    use InteractsWithMcp;

    /**
     * Testbench does not discover vendor providers for a test case, so the
     * optional packages whose provider the code under test needs are named
     * here, when installed, the way an application's auto-discovery would.
     * laravel-data reads its rule inferrers from its own config, which only
     * its provider merges.
     */
    protected function getPackageProviders($app): array
    {
        return array_filter([
            McpBridgeServiceProvider::class,
            class_exists(LaravelDataServiceProvider::class) ? LaravelDataServiceProvider::class : null,
            class_exists(DoctorServiceProvider::class) ? DoctorServiceProvider::class : null,
            class_exists(ScrambleServiceProvider::class) ? ScrambleServiceProvider::class : null,
        ]);
    }

    /**
     * The compiled manifest is a file in the test application's bootstrap
     * cache, and Testbench hands every test the same application skeleton. A
     * manifest one test wrote would otherwise still be there for the next,
     * which is how `mcp-bridge:tools` was told there was nothing to list and
     * found something.
     */
    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(ManifestRepository::class)->clear();
        }

        // Static, so a test that started a stdio server would otherwise leave
        // every later test in the process serving that server. The policy
        // switch is static for the same reason and leaks the same way.
        ServerKeyResolver::serving(null);
        CoursePolicy::$allows = true;

        parent::tearDown();
    }

    /**
     * The package's default configuration protects its endpoints with
     * `auth:mcp-bridge`, and `security.guards` also names `sanctum`, so the
     * test application defines both guards rather than the suite weakening
     * the configuration.
     */
    protected function defineEnvironment($app): void
    {
        if (getenv('TEST_TOKEN') !== false && getenv('TEST_TOKEN') !== '') {
            $app->singleton(ManifestRepository::class, ParallelManifestRepository::class);
        }

        /** @var Application $app */
        $app['config']->set('auth.guards.sanctum', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.guards.mcp-bridge', ['driver' => 'session', 'provider' => 'users']);

        // Confirmation handles are encrypted with the application key, which
        // every real Laravel application has.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
