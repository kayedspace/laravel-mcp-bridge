<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use KayedSpace\McpBridge\Data\Authority;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Limits;
use KayedSpace\McpBridge\Data\RouteTarget;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\Target;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Tests\FilamentTestCase;
use KayedSpace\McpBridge\Tests\Fixtures\CatalogController;
use KayedSpace\McpBridge\Tests\Fixtures\Commands\FailingReport;
use KayedSpace\McpBridge\Tests\Fixtures\Commands\PromptingReport;
use KayedSpace\McpBridge\Tests\Fixtures\Commands\ReadStats;
use KayedSpace\McpBridge\Tests\Fixtures\Commands\RecalculateStats;
use KayedSpace\McpBridge\Tests\Fixtures\Commands\UnprotectedStats;
use KayedSpace\McpBridge\Tests\Fixtures\CourseController;
use KayedSpace\McpBridge\Tests\Fixtures\LessonController;
use KayedSpace\McpBridge\Tests\Fixtures\ReportController;
use KayedSpace\McpBridge\Tests\Fixtures\ResourceController;
use KayedSpace\McpBridge\Tests\Fixtures\ScrambleController;
use KayedSpace\McpBridge\Tests\Fixtures\ScribeController;
use KayedSpace\McpBridge\Tests\Fixtures\SharedQueryController;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use KayedSpace\McpBridge\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// A panel in the application, for the page the package adds to one. Without
// Filament installed, which the CI matrix's `integrations: absent` row is,
// the plain case carries the directory and every test in it skips itself.
pest()->extend(class_exists(Panel::class) ? FilamentTestCase::class : TestCase::class)->in('Filament');

function definition(
    string $name = 'list_courses',
    string $source = 'route',
    ?Target $target = null,
    ?InputSchema $input = null,
    ?Safety $safety = null,
    ?Authority $authority = null,
    string $server = 'default',
    ?string $module = null,
): ToolDefinition {
    return new ToolDefinition(
        name: $name,
        candidateId: "{$source}:{$name}",
        source: $source,
        description: 'List published courses.',
        target: $target ?? new RouteTarget('GET', 'api/courses', 'api.courses.index'),
        input: $input ?? InputSchema::empty(),
        safety: $safety ?? Safety::readOnly(),
        authority: $authority ?? new Authority(scopes: ['courses:read']),
        limits: new Limits(timeout: 30),
        server: $server,
        module: $module,
    );
}

/**
 * Routes whose controllers return resources rather than hand-built responses.
 * Separate, so no existing test's tool surface changes.
 */
function defineResourceRoutes(): void
{
    // With SubstituteBindings, because these bind a model and a real
    // application's api routes resolve bindings before the controller runs.
    Route::prefix('api/v1')->name('api.v1.')->middleware(SubstituteBindings::class)->group(function (): void {
        Route::get('catalogue/{course}', [ResourceController::class, 'show'])->name('catalogue.show')->whereNumber('course');
        Route::get('catalogue', [ResourceController::class, 'index'])->name('catalogue.index');
        Route::get('catalogue/paged', [ResourceController::class, 'paginated'])->name('catalogue.paged');
        Route::get('catalogue/summary', [ResourceController::class, 'summary'])->name('catalogue.summary');
        Route::get('catalogue/annotated', [ResourceController::class, 'withMeta'])->name('catalogue.annotated');
    });
}

/**
 * The audit table as it actually ships.
 *
 * Five tests hand-copied the fifteen columns, which is five chances for the
 * copy to drift from the migration a consumer runs and for a test to pass
 * against a table nobody has.
 */
function createAuditTable(): void
{
    (require __DIR__.'/../database/migrations/2026_01_01_000001_create_mcp_bridge_audit_table.php')->up();
}

function createKillsTable(): void
{
    (require __DIR__.'/../database/migrations/2026_01_01_000003_create_mcp_bridge_kills_table.php')->up();
}

/** The fixture API that discovery runs against. */
function defineApiRoutes(): void
{
    Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
        Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
        Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
        Route::get('courses/export', [CourseController::class, 'export'])->name('courses.export');
        Route::get('courses/internal', [CourseController::class, 'internal'])->name('courses.internal');
        Route::get('courses/html', [CourseController::class, 'html'])->name('courses.html');
        Route::get('courses/{course}', [CourseController::class, 'show'])->name('courses.show')->whereNumber('course');
        Route::delete('courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy')->whereNumber('course');
        Route::post('courses/{course}/publish', [CourseController::class, 'publish'])->name('courses.publish')->whereNumber('course');
        Route::get('courses/{course}/lessons', [LessonController::class, 'index'])->name('courses.lessons.index')->whereNumber('course');
        Route::get('courses/{course}/lessons/{lesson}', [LessonController::class, 'show'])->name('courses.lessons.show');

        Route::post('courses/purge', [CourseController::class, 'purge'])->name('courses.purge');
        Route::get('courses/legacy', [CourseController::class, 'legacy'])->name('courses.legacy');
        Route::post('courses/inline', [CourseController::class, 'storeInline'])->name('courses.inline');
        Route::post('courses/cover', [CourseController::class, 'cover'])->name('courses.cover');
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    });
}

/**
 * The list endpoints that declare an allowed query, kept out of
 * defineApiRoutes() so the discovery expectations there stay about discovery.
 */
function defineQueryRoutes(): void
{
    Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
        Route::get('catalog', [CatalogController::class, 'declared'])->name('catalog.declared');
        Route::get('catalog/chained', [CatalogController::class, 'chained'])->name('catalog.chained');
        Route::get('catalog/enriched', [CatalogController::class, 'enriched'])->name('catalog.enriched');
        Route::get('catalog/applied', [CatalogController::class, 'applied'])->name('catalog.applied');
        Route::get('shared', [SharedQueryController::class, 'index'])->name('shared.index');
    });
}

/** The endpoints whose schema comes from an API documentation package. */
function defineDocumentedRoutes(): void
{
    Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
        Route::get('documented', [ScrambleController::class, 'index'])->name('documented.index');
        Route::post('documented', [ScrambleController::class, 'store'])->name('documented.store');
        Route::get('doubly', [ScrambleController::class, 'doubly'])->name('doubly.index');
        Route::get('documented/{course}', [ScrambleController::class, 'show'])->name('documented.show');
        Route::get('documented/internal', [ScrambleController::class, 'internal'])->name('documented.internal');

        Route::get('teams/{team}/reports', [ScribeController::class, 'index'])->name('teams.reports.index');
        Route::post('reports', [ScribeController::class, 'store'])->name('reports.store');
        Route::get('reports/secret', [ScribeController::class, 'secret'])->name('reports.secret');
    });
}

/**
 * The fixture Artisan commands, registered with the console kernel the way an
 * application's own commands are.
 */
function defineCommands(): void
{
    $kernel = app(ConsoleKernel::class);

    $commands = [
        RecalculateStats::class,
        FailingReport::class,
        UnprotectedStats::class,
        ReadStats::class,
        PromptingReport::class,
    ];

    foreach ($commands as $command) {
        $kernel->registerCommand(app($command));
    }

    config()->set('mcp-bridge.sources.command.enabled', true);
}

function compileCommandFixtures(): CompileResult
{
    defineCommands();

    return app(ManifestCompiler::class)->compile();
}

function compileFixtures(): CompileResult
{
    defineApiRoutes();

    return app(ManifestCompiler::class)->compile();
}

function compileDocumentedFixtures(): CompileResult
{
    defineDocumentedRoutes();

    return app(ManifestCompiler::class)->compile();
}

function compileQueryFixtures(): CompileResult
{
    defineQueryRoutes();

    return app(ManifestCompiler::class)->compile();
}

function toolNamed(string $name, ?CompileResult $result = null): ToolDefinition
{
    $result ??= compileFixtures();

    foreach ($result->definitions as $definition) {
        if ($definition->name === $name) {
            return $definition;
        }
    }

    throw new RuntimeException("No tool named [{$name}] was compiled. Got: ".implode(', ', $result->names()));
}

/** @param array<string, mixed> $arguments */
function callTool(
    ToolDefinition $definition,
    array $arguments = [],
    ?Authenticatable $actor = null,
    ?string $confirm = null,
): ToolCall {
    return new ToolCall(
        definition: $definition,
        arguments: $arguments,
        actor: $actor ?? new TestUser,
        requestId: 'test-request',
        confirm: $confirm,
    );
}
