<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Tests\Fixtures\CoursePrompt;
use KayedSpace\McpBridge\Tests\Fixtures\HandbookResource;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use KayedSpace\McpBridge\Tests\Fixtures\WeatherTool;

const PROTOCOL = '2026-07-28';

beforeEach(function () {
    config(['mcp-bridge.mode' => 'opt-in']);

    defineApiRoutes();

    app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);
});

/**
 * The 2026-07-28 revision is stateless, so every request carries its own
 * protocol version, capabilities and client info rather than negotiating them
 * once in a handshake.
 *
 * @return array<string, mixed>
 */
function meta(): array
{
    return [
        'io.modelcontextprotocol/protocolVersion' => PROTOCOL,
        'io.modelcontextprotocol/clientCapabilities' => [],
        'io.modelcontextprotocol/clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
    ];
}

/** @param array<string, mixed> $params */
function rpc(string $method, array $params = [], ?string $name = null, string $path = '/mcp'): array
{
    $headers = [
        'MCP-Protocol-Version' => PROTOCOL,
        'Mcp-Method' => $method,
    ];

    if ($name !== null) {
        $headers['Mcp-Name'] = $name;
    }

    $response = test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => [...$params, '_meta' => meta()],
    ], $headers);

    $response->assertOk();

    return $response->json();
}

it('requires authentication, because the endpoint carries the configured middleware', function () {
    test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['_meta' => meta()],
    ], [
        'MCP-Protocol-Version' => PROTOCOL,
        'Mcp-Method' => 'tools/list',
    ])->assertUnauthorized();
});

it('lists the compiled tools over the protocol', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    $names = array_column(rpc('tools/list')['result']['tools'], 'name');

    expect($names)->toContain('list_courses', 'get_course', 'create_course')
        ->and($names)->not->toContain('internal_course');
});

it('advertises a usable schema and all four hints for each tool', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    $tool = collect(rpc('tools/list')['result']['tools'])->firstWhere('name', 'list_courses');

    expect($tool['description'])->toStartWith('List published courses with optional title search.')
        ->and($tool['inputSchema']['properties'])->toHaveKeys(['filter', 'per_page'])
        ->and($tool['annotations'])->toMatchArray([
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ]);
});

it('calls a tool over the protocol and returns structured content', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    $body = rpc('tools/call', ['name' => 'get_course', 'arguments' => ['course' => 21]], 'get_course');

    expect($body['result']['isError'] ?? false)->toBeFalse()
        ->and($body['result']['structuredContent']['data']['id'])->toBe(21)
        ->and($body['result']['content'][0]['text'])->toBeString();
});

it('returns a validation failure as a tool error rather than a transport error', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    $body = rpc('tools/call', ['name' => 'create_course', 'arguments' => ['status' => 'draft']], 'create_course');

    expect($body['result']['isError'])->toBeTrue()
        ->and($body['result']['content'][0]['text'])->toContain('title');
});

describe('cache hints', function () {
    it('tells a client how long it may reuse a tool list', function () {
        test()->actingAs(new TestUser, 'mcp-bridge');

        $result = rpc('tools/list')['result'];

        expect($result['ttlMs'])->toBe(300_000)
            ->and($result['cacheScope'])->toBe('private');
    });

    it('never shares a list that was filtered for the caller', function () {
        // Asked for public, but these tools declare scopes, so one caller's
        // surface must not be served to another from a shared cache.
        config()->set('mcp-bridge.protocol.cache_hints.scope', 'public');

        test()->actingAs(new TestUser, 'mcp-bridge');

        expect(rpc('tools/list')['result']['cacheScope'])->toBe('private');
    });

    it('tells a client not to cache at all when hints are off', function () {
        config()->set('mcp-bridge.protocol.cache_hints.enabled', false);

        test()->actingAs(new TestUser, 'mcp-bridge');

        // laravel/mcp answers every cacheable method either way; a zero
        // lifetime is how the revision spells "do not keep this".
        expect(rpc('tools/list')['result']['ttlMs'])->toBe(0);
    });
});

it('does not serve route tools from the operations endpoint', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    expect(rpc('tools/list', path: '/mcp/ops')['result']['tools'])->toBe([]);
});

it('offers job_status on a server that carries a job tool, and nowhere else', function () {
    app(Gate::class)->define('run-reports', fn (): bool => true);

    config()->set('mcp-bridge.sources.job.enabled', true);
    config()->set('mcp-bridge.sources.job.paths', [__DIR__.'/../Fixtures/Jobs']);

    app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);

    test()->actingAs(new TokenUser(['ops:write']), 'mcp-bridge');

    expect(array_column(rpc('tools/list', path: '/mcp/ops')['result']['tools'], 'name'))
        ->toContain('recalculate_course', 'job_status');

    test()->actingAs(new TokenUser(['courses:read']), 'mcp-bridge');

    // The default server carries no job tool, so there is nothing to ask about.
    expect(array_column(rpc('tools/list')['result']['tools'], 'name'))->not->toContain('job_status');
});

it('replaces a long tool list with search and execute tools', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    // One tool is already more than none, so the whole surface is nested.
    config(['mcp-bridge.tool_search_threshold' => 1]);

    $names = array_column(rpc('tools/list')['result']['tools'], 'name');

    expect($names)->toContain('search_tools', 'execute_tools')
        ->and($names)->not->toContain('list_courses');
});

it('lists the tools flat when there are fewer than the threshold', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    config(['mcp-bridge.tool_search_threshold' => 500]);

    expect(array_column(rpc('tools/list')['result']['tools'], 'name'))
        ->toContain('list_courses')
        ->not->toContain('search_tools');
});

it('advertises the response envelope for a list tool', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    $tool = collect(rpc('tools/list')['result']['tools'])->firstWhere('name', 'list_courses');

    expect($tool['outputSchema']['properties'])->toHaveKeys(['data', 'pagination'])
        ->and($tool['outputSchema']['properties']['data']['type'])->toBe('array')
        ->and($tool['outputSchema']['properties']['pagination']['properties']['per_page']['type'])->toBe('integer')
        ->and($tool['outputSchema']['properties']['pagination']['properties']['has_more']['type'])->toBe('boolean');
});

it('says nothing about the response when nothing is certain', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    // A single-record tool with no declared fields: the shape is whatever the
    // controller returns, so promising a schema would be inventing one.
    $tool = collect(rpc('tools/list')['result']['tools'])->firstWhere('name', 'get_course');

    expect($tool)->not->toHaveKey('outputSchema');
});

it('serves prompts an application registered, and ignores what is not one', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    config(['mcp-bridge.servers.default.prompts' => [CoursePrompt::class, TestUser::class, 'App\\Gone']]);

    // Named by laravel/mcp, not by this package: a passed-through prompt keeps
    // the naming of whatever registers it.
    $names = array_column(rpc('prompts/list')['result']['prompts'], 'name');

    expect($names)->toBe(['course-prompt']);
});

it('serves the application\'s own laravel/mcp tools and resources beside the generated ones', function () {
    test()->actingAs(new TestUser, 'mcp-bridge');

    config([
        'mcp-bridge.servers.default.tools' => [WeatherTool::class, TestUser::class],
        'mcp-bridge.servers.default.resources' => [HandbookResource::class, 'App\\Gone'],
    ]);

    $tools = array_column(rpc('tools/list')['result']['tools'], 'name');
    $resources = array_column(rpc('resources/list')['result']['resources'], 'uri');

    expect($tools)->toContain('weather-tool', 'list_courses')
        ->and($resources)->toContain('file://handbook');

    expect(rpc('tools/call', ['name' => 'weather-tool', 'arguments' => []], 'weather-tool')['result']['content'][0]['text'])->toBe('Sunny.');
});
