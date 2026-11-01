<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Doctor\Diagnostics\CacheStoreIsShared;
use KayedSpace\McpBridge\Doctor\Diagnostics\GuardsAreDefined;
use KayedSpace\McpBridge\Doctor\Diagnostics\KillSwitchIsClear;
use KayedSpace\McpBridge\Doctor\Diagnostics\ManifestIsCurrent;
use KayedSpace\McpBridge\Doctor\Diagnostics\StdioActorResolves;
use KayedSpace\McpBridge\Doctor\Diagnostics\TablesExist;
use KayedSpace\McpBridge\Security\KillSwitch;
use Laravel\Doctor\Doctor;
use Laravel\Doctor\Results\Status;

it('registers its checks with laravel/doctor, in one group, under the package name', function (): void {
    $registered = app(Doctor::class)->registered();

    expect($registered)->toContain(GuardsAreDefined::class, TablesExist::class, KillSwitchIsClear::class)
        ->and(app(GuardsAreDefined::class)->group)->toBe('mcp-bridge')
        ->and(app(GuardsAreDefined::class)->package())->toBe('kayedspace/laravel-mcp-bridge');
});

it('passes and fails on the same facts mcp-bridge:doctor prints', function (): void {
    expect(app(GuardsAreDefined::class)->check()->status)->toBe(Status::Pass);

    config()->set('auth.guards.mcp-bridge', null);

    $result = app(GuardsAreDefined::class)->check();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->summary)->toContain('Guard [mcp-bridge]')
        ->and($result->remediation)->toContain('config/auth.php');
});

it('fails on a cache store workers do not share, and on a missing table', function (): void {
    config()->set('cache.default', 'array');

    expect(app(CacheStoreIsShared::class)->check()->status)->toBe(Status::Fail);

    $tables = app(TablesExist::class)->check();

    expect($tables->status)->toBe(Status::Fail)
        ->and($tables->remediation)->toBe('run migrate');
});

it('reports a switched-off tool as a notice and stdio off as skipped', function (): void {
    createKillsTable();

    expect(app(KillSwitchIsClear::class)->check()->status)->toBe(Status::Pass);

    app(KillSwitch::class)->kill('tool:purge_courses');

    $result = app(KillSwitchIsClear::class)->check();

    expect($result->status)->toBe(Status::Notice)
        ->and($result->summary)->toContain('purge_courses')
        ->and(app(StdioActorResolves::class)->check()->status)->toBe(Status::Skip);
});

it('reads the manifest the way the runtime does', function (): void {
    expect(app(ManifestIsCurrent::class)->check()->status)->toBe(Status::Pass);
});

it('appears in php artisan doctor beside the framework checks', function (): void {
    Schema::create('mcp_bridge_tokens', fn ($table) => $table->id());
    createAuditTable();
    createKillsTable();
    config()->set('cache.default', 'file');

    $this->artisan('doctor', ['--only' => 'kayedspace/laravel-mcp-bridge', '--format' => 'json'])
        ->expectsOutputToContain('MCP server guards are defined')
        ->assertSuccessful();
});
