<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Route;
use KayedSpace\McpBridge\Contracts\LocatesModules;
use KayedSpace\McpBridge\Data\Module;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Modules\DirectoryLocator;
use KayedSpace\McpBridge\Modules\ModuleRegistry;
use KayedSpace\McpBridge\Modules\NwidartLocator;
use KayedSpace\McpBridge\Tests\Fixtures\Modules\Billing\app\Http\Controllers\InvoiceController;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Laravel\LaravelFileRepository;
use Nwidart\Modules\Laravel\Module as NwidartModule;
use Nwidart\Modules\LaravelModulesServiceProvider;

/**
 * A locator standing in for whatever module package an application uses: the
 * registry only ever asks where the modules are.
 */
function billing(): LocatesModules
{
    return new class implements LocatesModules
    {
        /** @return list<Module> */
        public function modules(): array
        {
            return [new Module(name: 'Billing', path: __DIR__.'/../Fixtures/Modules/Billing')];
        }
    };
}

/**
 * The nwidart locator, with the package's own provider registered and its
 * repository answering with one module, which is how an application that
 * uses the package presents them.
 */
function withNwidartModule(string $name, string $path): void
{
    app()->register(LaravelModulesServiceProvider::class);

    app()->instance(RepositoryInterface::class, new class(app(), $name, $path) extends LaravelFileRepository
    {
        public function __construct(Container $app, private string $moduleName, private string $modulePath)
        {
            parent::__construct($app);
        }

        public function all(): array
        {
            return [new NwidartModule($this->app, $this->moduleName, $this->modulePath)];
        }
    });
}

function compileModular(): CompileResult
{
    app()->singleton(ModuleRegistry::class, fn (): ModuleRegistry => new ModuleRegistry(
        locators: ['test' => billing()],
        config: app('config'),
    ));

    config()->set('mcp-bridge.modules.locators', ['test']);

    Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    });

    config()->set('mcp-bridge.sources.job.enabled', true);
    config()->set('mcp-bridge.sources.job.paths', [__DIR__.'/../Fixtures/Modules/*/app/Jobs']);

    return app(ManifestCompiler::class)->compile();
}

it('says which module a tool came from, by where its code lives', function (): void {
    $result = compileModular();

    expect(toolNamed('list_invoices', $result)->module)->toBe('Billing')
        ->and(toolNamed('send_invoice', $result)->module)->toBe('Billing');
});

it('groups a module tools by the module', function (): void {
    expect(toolNamed('list_invoices', compileModular())->groups)->toBe(['Billing']);
});

it('leaves grouping alone when the application asked for none', function (): void {
    config()->set('mcp-bridge.modules.grouping', 'none');

    expect(toolNamed('list_invoices', compileModular())->groups)->toBe([]);
});

it('prefixes tool names only when asked', function (): void {
    expect(compileModular()->names())->toContain('list_invoices');

    config()->set('mcp-bridge.modules.always_prefix_module', true);

    expect(compileModular()->names())->toContain('billing_list_invoices');
});

it('gives each module its own server when asked', function (): void {
    config()->set('mcp-bridge.modules.per_module_servers', true);

    expect(toolNamed('list_invoices', compileModular())->server)->toBe('billing');
});

it('ignores a module the application excluded', function (): void {
    config()->set('mcp-bridge.modules.except', ['Billing']);

    expect(toolNamed('list_invoices', compileModular())->module)->toBeNull();
});

it('finds no module from an empty only list', function (): void {
    config()->set('mcp-bridge.modules.only', []);

    expect(toolNamed('list_invoices', compileModular())->module)->toBeNull();
});

it('finds classes through a glob across every module', function (): void {
    expect(compileModular()->names())->toContain('send_invoice');
});

it('says nothing about modules when the application has none', function (): void {
    defineApiRoutes();

    expect(app(ModuleRegistry::class)->modules())->toBe([])
        ->and(app(ModuleRegistry::class)->enabled())->toBeFalse()
        ->and(toolNamed('list_courses')->module)->toBeNull();
});

describe('modules that are a folder convention, not a package', function (): void {
    beforeEach(function (): void {
        // The real locator, not the fake the other tests use: the point is
        // that a directory glob is enough and no module package is involved.
        config([
            'mcp-bridge.modules.locators' => ['directories'],
            'mcp-bridge.modules.directories' => [__DIR__.'/../Fixtures/Modules/*'],
        ]);

        app()->singleton(ModuleRegistry::class, fn (): ModuleRegistry => new ModuleRegistry(
            locators: ['directories' => app(DirectoryLocator::class)],
            config: app('config'),
        ));
    });

    it('names a module after its directory', function (): void {
        $names = array_map(
            fn (Module $module): string => $module->name,
            app(ModuleRegistry::class)->modules(),
        );

        expect($names)->toBe(['Billing']);
    });

    it('attributes a class to that module, which is all a locator is for', function (): void {
        // Ownership is decided by the file a class was declared in, so it
        // holds whatever the module system is called, or whether there is one.
        expect(app(ModuleRegistry::class)->forClass(InvoiceController::class))->toBe('Billing');
    });
});

describe('the nwidart locator', function (): void {
    it('finds nothing when the package is not booted', function (): void {
        expect(app(NwidartLocator::class)->modules())->toBe([]);
    });

    it('reads the modules the package repository reports, by name and path', function (): void {
        withNwidartModule('Billing', __DIR__.'/../Fixtures/Modules/Billing');

        $modules = app(NwidartLocator::class)->modules();

        expect($modules)->toHaveCount(1)
            ->and($modules[0]->name)->toBe('Billing')
            ->and($modules[0]->path)->toBe(realpath(__DIR__.'/../Fixtures/Modules/Billing'));
    });
})->skip(
    fn (): bool => ! interface_exists(RepositoryInterface::class),
    'nwidart/laravel-modules is not installed.',
);
