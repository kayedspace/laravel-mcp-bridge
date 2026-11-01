<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use KayedSpace\McpBridge\Filament\McpBridgePlugin;

/**
 * The test application with a Filament panel in it.
 *
 * Filament is a dozen service providers, so rather than list them this case
 * lets Testbench discover every vendor provider, which is what an application
 * with Filament installed looks like anyway. Kept apart from the main test
 * case so the rest of the suite keeps its deliberately small application.
 */
abstract class FilamentTestCase extends TestCase
{
    /** @var bool Untyped in Testbench, so untyped here, or PHP refuses the override. */
    protected $enablesPackageDiscoveries = true;

    protected function setUp(): void
    {
        parent::setUp();

        // On the registry itself rather than through the facade: the facade
        // defers registration to a resolving callback, which never fires for
        // a registry the provider already resolved at boot.
        $this->app->make(PanelRegistry::class)->register(
            Panel::make()
                ->id('admin')
                ->path('admin')
                ->default()
                ->plugin(McpBridgePlugin::make()),
        );

        Filament::setCurrentPanel('admin');
    }
}
