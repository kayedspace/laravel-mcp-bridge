<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Facades\McpBridge;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\McpBridgeManager;
use KayedSpace\McpBridge\McpBridgeServiceProvider;
use Laravel\Mcp\Server\Registrar;

it('merges its configuration', function () {
    expect(config('mcp-bridge.mode'))->toBe('opt-in')
        ->and(config('mcp-bridge.sources.route.enabled'))->toBeTrue();
});

it('keeps every non http source opt in, whatever the mode', function () {
    foreach (['command', 'job', 'action', 'model'] as $source) {
        expect(config("mcp-bridge.sources.{$source}.enabled"))->toBeFalse();
    }
});

it('requires a scope for non http sources by default', function () {
    expect(config('mcp-bridge.security.require_scope_for_non_http'))->toBeTrue();
});

it('serves every source that is not an http route from their own server', function () {
    // A source listed nowhere falls through to `default`, the application's
    // own API surface, so every one of them is written down.
    expect(config('mcp-bridge.servers.ops.sources'))
        ->toBe(['command', 'job', 'action', 'model', 'remote', 'openapi'])
        ->and(config('mcp-bridge.servers.default.sources'))->toBe(['route', 'resource']);
});

it('resolves the manager as a singleton', function () {
    expect(app(McpBridgeManager::class))->toBe(app(McpBridgeManager::class))
        ->and(app(ManifestRepository::class))->toBe(app(ManifestRepository::class));
});

it('reports that an ordinary request is not an mcp call', function () {
    expect(McpBridge::isMcpCall(request()))->toBeFalse()
        ->and(McpBridge::currentTool(request()))->toBeNull();
});

it('answers php artisan about', function () {
    $this->artisan('about')
        ->expectsOutputToContain('MCP Bridge')
        ->assertSuccessful();
});

it('registers the tools command', function () {
    $this->artisan('mcp-bridge:tools')
        ->expectsOutputToContain('No tools are exposed')
        ->assertSuccessful();
});

it('serves nothing over stdio until asked, because a subprocess carries no token', function () {
    $registered = app(Registrar::class);

    expect($registered->getLocalServer('default'))->toBeNull()
        ->and($registered->getLocalServer('ops'))->toBeNull();
});

it('serves every configured server over stdio as well as http when local is on', function () {
    config(['mcp-bridge.local' => true]);

    // The servers are registered at boot, so the provider is booted again
    // with the setting on, which is what an application turning it on gets.
    app()->register(McpBridgeServiceProvider::class, force: true);

    $registered = app(Registrar::class);

    // laravel/mcp runs these as `php artisan mcp:start <handle>`, so the
    // handle is the server key rather than its path.
    expect($registered->getLocalServer('default'))->not->toBeNull()
        ->and($registered->getLocalServer('ops'))->not->toBeNull();
});
