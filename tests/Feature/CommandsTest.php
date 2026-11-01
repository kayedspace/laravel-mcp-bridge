<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Tests\Fixtures\CourseController;

beforeEach(function () {
    config(['mcp-bridge.mode' => 'opt-in']);
    defineApiRoutes();
    app(ManifestRepository::class)->clear();
});

it('compiles the manifest and reports what it refused', function () {
    $this->artisan('mcp-bridge:cache')
        ->expectsOutputToContain('Compiled')
        ->expectsOutputToContain('were not exposed')
        ->assertSuccessful();

    expect(app(ManifestRepository::class)->find('list_courses'))->not->toBeNull();
});

it('lists the compiled tools with their source and safety', function () {
    $this->artisan('mcp-bridge:cache')->assertSuccessful();

    $this->artisan('mcp-bridge:tools')
        ->expectsOutputToContain('list_courses')
        ->expectsOutputToContain('route')
        ->assertSuccessful();
});

it('filters the listing by source', function () {
    $this->artisan('mcp-bridge:cache')->assertSuccessful();

    $this->artisan('mcp-bridge:tools --source=command')
        ->expectsOutputToContain('No tools matched')
        ->assertSuccessful();
});

it('explains every refusal on request', function () {
    $this->artisan('mcp-bridge:tools --refused')
        ->expectsOutputToContain('MCP_BRIDGE_IGNORED')
        ->expectsOutputToContain('MCP_BRIDGE_NOT_EXPOSED')
        ->assertSuccessful();
});

it('clears the manifest', function () {
    $this->artisan('mcp-bridge:cache')->assertSuccessful();
    $this->artisan('mcp-bridge:clear')->assertSuccessful();

    expect(app(ManifestRepository::class)->isCached())->toBeFalse();
});

it('refuses the later claim on a tool name collision instead of shadowing one or failing the build', function () {
    // Two routes that compile to the same name: the fixture publish route is
    // named explicitly, so point a second route at the same name.
    app('router')->post('api/v1/courses/{course}/release', [
        CourseController::class, 'publish',
    ])->name('api.v1.courses.release');

    $this->artisan('mcp-bridge:cache')
        ->expectsOutputToContain('were not exposed')
        ->assertSuccessful();

    $this->artisan('mcp-bridge:tools --refused')
        ->expectsOutputToContain('MCP_BRIDGE_NAME_TAKEN')
        ->assertSuccessful();
});
