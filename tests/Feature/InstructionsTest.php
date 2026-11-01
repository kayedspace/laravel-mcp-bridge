<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Server\Instructions;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['mcp-bridge.mode' => 'opt-in']);
    defineApiRoutes();
});

it('tells the agent only about what the server serves', function (): void {
    $text = app(Instructions::class)->for('default');

    expect($text)->toContain('own API')
        ->toContain('refused, and the refusal names the ones it accepts')
        ->toContain('Lists take filter, sort, include')
        ->toContain('confirmation handle')
        ->toContain('idempotency_key')
        ->toContain('MCP_BRIDGE_SCOPE')
        ->toContain('app://routes')
        ->toContain('upload_file')
        ->not->toContain('job_status')
        ->not->toContain('app://records');
});

it('yields to configured text, and sends nothing when told to', function (): void {
    config(['mcp-bridge.servers.default.instructions' => 'Use these tools for the catalogue only.']);

    expect(app(Instructions::class)->for('default'))->toBe('Use these tools for the catalogue only.');

    config(['mcp-bridge.servers.default.instructions' => false]);

    expect(app(Instructions::class)->for('default'))->toBe('');
});

it('reaches the client at initialize', function (): void {
    test()->actingAs(new TestUser, 'mcp-bridge');

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2026-07-28',
            'capabilities' => [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
                'io.modelcontextprotocol/clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
            ],
        ],
    ], [
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'initialize',
    ])->assertOk();

    expect($response->json('result.instructions'))->toContain('refused, and the refusal names the ones it accepts');
});

it('describes the service account when every call runs as one', function (): void {
    config(['mcp-bridge.security.auth' => 'service-account']);

    expect(app(Instructions::class)->for('default'))->toContain('runs as a service account')
        ->not->toContain('owner of the token');
});
