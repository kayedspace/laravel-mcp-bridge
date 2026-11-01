<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Resources\RecordResource;
use KayedSpace\McpBridge\Resources\ResourceRegistry;
use KayedSpace\McpBridge\Tests\Fixtures\Course;
use KayedSpace\McpBridge\Tests\Fixtures\Policies\CoursePolicy;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;

function readRecord(string $tool, mixed $id): Response
{
    app()->instance(McpRequest::class, new McpRequest(['tool' => $tool, 'id' => $id]));

    return app()->call([app(RecordResource::class), 'handle']);
}

beforeEach(function (): void {
    CoursePolicy::$allows = true;
    Gate::policy(Course::class, CoursePolicy::class);
    Gate::define('manage-catalogue', fn (): bool => true);

    Schema::create('courses', function ($table): void {
        $table->id();
        $table->string('title');
        $table->string('status')->default('draft');
        $table->softDeletes();
        $table->timestamps();
    });

    Course::query()->insert([
        ['id' => 1, 'title' => 'Cardiology', 'status' => 'published'],
        ['id' => 2, 'title' => 'Paediatrics', 'status' => 'draft'],
    ]);

    config()->set('mcp-bridge.sources.model.enabled', true);
    config()->set('mcp-bridge.sources.model.paths', [__DIR__.'/../Fixtures']);
    config()->set('mcp-bridge.sources.model.operations', ['list', 'get']);
    config()->set('mcp-bridge.sources.model.server', 'default');
    config()->set('mcp-bridge.sources.resource.expose', ['routes', 'schema', 'records']);

    app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);

    test()->actingAs(new TokenUser(['courses:write'], 3), 'mcp-bridge');
});

/** Through laravel/mcp's own template matching, the way a client reaches it. */
function recordRpc(string $method, array $params = [], ?string $name = null): array
{
    $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];

    if ($name !== null) {
        $headers['Mcp-Name'] = $name;
    }

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => [...$params, '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
            'io.modelcontextprotocol/clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ]],
    ], $headers)->assertOk()->json();
}

it('is listed as a template and read through it over the protocol', function (): void {
    $templates = recordRpc('resources/templates/list')['result']['resourceTemplates'];
    $read = recordRpc('resources/read', ['uri' => 'app://records/model_get_course/2'], 'app://records/model_get_course/2');

    expect(array_column($templates, 'uriTemplate'))->toBe(['app://records/{tool}/{id}'])
        ->and(json_decode($read['result']['contents'][0]['text'], true))->toMatchArray(['id' => 2, 'title' => 'Paediatrics']);
});

it('is a template, served only where a get tool is', function (): void {
    expect((string) app(RecordResource::class)->uriTemplate())->toBe('app://records/{tool}/{id}')
        ->and(app(ResourceRegistry::class)->readsRecords())->toBeTrue();

    config()->set('mcp-bridge.sources.resource.expose', ['routes', 'schema']);

    expect(app(ResourceRegistry::class)->readsRecords())->toBeFalse();
});

it('reads a record through the get tool, shaped the way the tool answers', function (): void {
    $response = readRecord('model_get_course', 2);

    expect($response->isError())->toBeFalse()
        ->and(json_decode((string) $response->content(), true))->toMatchArray(['id' => 2, 'title' => 'Paediatrics']);
});

it('asks the policy, because it is the tool that runs', function (): void {
    CoursePolicy::$allows = false;

    expect(readRecord('model_get_course', 2)->isError())->toBeTrue();
});

it('refuses a tool that is not a get tool, and a record that is not there', function (): void {
    expect(readRecord('model_list_courses', 2)->isError())->toBeTrue()
        ->and(readRecord('no_such_tool', 2)->isError())->toBeTrue()
        ->and(readRecord('model_get_course', 99)->isError())->toBeTrue();
});

it('refuses a caller without the tool\'s scope', function (): void {
    test()->actingAs(new TokenUser([], 3), 'mcp-bridge');

    expect(readRecord('model_get_course', 2)->isError())->toBeTrue();
});
