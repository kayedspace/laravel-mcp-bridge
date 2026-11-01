<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use KayedSpace\McpBridge\Server\GeneratedTool;
use KayedSpace\McpBridge\Server\ServerKeyResolver;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

beforeEach(fn () => config(['mcp-bridge.mode' => 'opt-in']));

function tool(string $name): GeneratedTool
{
    return app(GeneratedTool::class, ['definition' => toolNamed($name)]);
}

it('presents a compiled definition as a laravel mcp tool', function () {
    $array = tool('list_courses')->toArray();

    expect($array['name'])->toBe('list_courses')
        ->and($array['description'])->toBe('List published courses with optional title search. Read-only.')
        ->and($array['inputSchema']['type'])->toBe('object')
        ->and(array_keys($array['inputSchema']['properties']))->toContain('filter', 'per_page');
});

it('always sets all four safety hints explicitly', function () {
    $read = tool('list_courses')->toArray()['annotations'];
    $delete = tool('delete_course')->toArray()['annotations'];

    expect($read)->toMatchArray([
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ])->and($delete)->toMatchArray([
        'readOnlyHint' => false,
        'destructiveHint' => true,
    ]);
});

it('refuses to run without a resolved actor', function () {
    $response = tool('get_course')->handle(new Request(['course' => 5]));

    expect($response->isError())->toBeTrue()
        ->and($response->content()->toTool(tool('get_course')))
        ->toHaveKey('text');
});

it('runs and returns both text and structured content', function () {
    auth()->guard()->setUser(new TestUser);

    $response = tool('get_course')->handle(new Request(['course' => 9]));

    expect($response)->toBeInstanceOf(ResponseFactory::class)
        ->and($response->getStructuredContent()['data']['id'])->toBe(9)
        ->and($response->responses()->first()->isError())->toBeFalse();
});

it('registers an endpoint for every configured server', function () {
    $paths = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->unique()
        ->values()
        ->all();

    expect($paths)->toContain('mcp', 'mcp/ops');
});

it('resolves which configured server a path belongs to', function () {
    $resolver = app(ServerKeyResolver::class);

    expect($resolver->forPath('/mcp'))->toBe('default')
        ->and($resolver->forPath('mcp/ops'))->toBe('ops')
        ->and($resolver->forPath('/somewhere-else'))->toBe('default');
});
