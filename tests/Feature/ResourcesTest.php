<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Resources\ConfigResource;
use KayedSpace\McpBridge\Resources\ResourceRegistry;
use KayedSpace\McpBridge\Resources\RoutesResource;
use KayedSpace\McpBridge\Resources\ScheduleResource;
use KayedSpace\McpBridge\Resources\SchemaResource;
use KayedSpace\McpBridge\Security\TokenScopes;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use Laravel\Mcp\Request as McpRequest;

/** Reads a resource the way laravel/mcp does: handle(), through the container. */
function readResource(string $resource): array
{
    $response = app()->call([app($resource), 'handle']);

    return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $result = compileFixtures();

    app(ManifestRepository::class)->write($result->definitions);

    // The shipped scope list is `*`: any authenticated caller, and nobody else.
    test()->actingAs(new TokenUser([], 1), 'mcp-bridge');
});

it('refuses an unauthenticated read even on the shipped wildcard', function (): void {
    auth()->guard('mcp-bridge')->forgetUser();

    $response = app()->call([app(RoutesResource::class), 'handle']);

    expect($response->isError())->toBeTrue();
});

describe('which resources a server offers', function (): void {
    it('offers the two that only describe what an agent can already reach', function (): void {
        expect(app(ResourceRegistry::class)->forServer(['route', 'resource']))
            ->toBe([RoutesResource::class, SchemaResource::class]);
    });

    it('offers none to a server that does not serve them', function (): void {
        expect(app(ResourceRegistry::class)->forServer(['command', 'job']))->toBe([]);
    });

    it('adds the ones that say something about the application only when asked', function (): void {
        config()->set('mcp-bridge.sources.resource.expose', ['schedule', 'config']);

        expect(app(ResourceRegistry::class)->forServer(['resource']))
            ->toBe([ScheduleResource::class, ConfigResource::class]);
    });

    it('offers nothing when the source is turned off', function (): void {
        config()->set('mcp-bridge.sources.resource.enabled', false);

        expect(app(ResourceRegistry::class)->forServer(['resource']))->toBe([]);
    });

    it('offers nothing from an empty list, and all four from a wildcard', function (): void {
        config()->set('mcp-bridge.sources.resource.expose', []);

        expect(app(ResourceRegistry::class)->forServer(['resource']))->toBe([]);

        config()->set('mcp-bridge.sources.resource.expose', ['*']);

        expect(app(ResourceRegistry::class)->forServer(['resource']))
            ->toBe([RoutesResource::class, SchemaResource::class, ScheduleResource::class, ConfigResource::class]);
    });
});

describe('the routes resource', function (): void {
    it('lists every route, saying which became a tool', function (): void {
        $routes = readResource(RoutesResource::class)['routes'];

        $byUri = [];

        foreach ($routes as $route) {
            $byUri[$route['method'].' '.$route['uri']] = $route;
        }

        expect($byUri['GET /api/v1/courses']['tool'])->toBe('list_courses')
            // Discovered, not exposed: an agent can tell the difference
            // between a missing feature and one it may not use.
            ->and($byUri['GET /api/v1/courses/internal']['tool'])->toBeNull()
            ->and($byUri['GET /api/v1/courses']['name'])->toBe('api.v1.courses.index')
            // Never the controller: an application's class layout is
            // reconnaissance an agent has no use for.
            ->and($byUri['GET /api/v1/courses'])->not->toHaveKey('action')
            ->and(json_encode($routes))->not->toContain('CourseController');
    });
});

describe('the schema resource', function (): void {
    it('describes every tool and what its inputs mean', function (): void {
        $schema = readResource(SchemaResource::class);

        $tools = [];

        foreach ($schema['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        expect($schema['server'])->toBe('default')
            ->and($tools['list_courses']['target'])->toContain('api/v1/courses')
            ->and($tools['list_courses']['safety']['read_only'])->toBeTrue()
            ->and($tools['list_courses']['input'])->toHaveKey('per_page')
            ->and($tools['purge_course']['confirm'])->toBeTrue();
    });

    it('never describes a field the agent may not send', function (): void {
        config()->set('mcp-bridge.security.fill_from_auth', ['title']);

        app(ManifestRepository::class)->write(compileFixtures()->definitions);

        $tools = [];

        foreach (readResource(SchemaResource::class)['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        expect($tools['create_course']['input'])->not->toHaveKey('title');
    });
});

describe('the config resource', function (): void {
    it('says what it will say in advance, and no more', function (): void {
        config()->set('app.key', 'base64:secret-key-material');

        $config = readResource(ConfigResource::class);

        expect(array_keys($config))->toBe([
            'name', 'environment', 'locale', 'fallback_locale', 'timezone', 'currency', 'laravel', 'php',
        ])
            ->and(json_encode($config))->not->toContain('secret-key-material');
    });
});

describe('resources answer to the same authority as tools', function (): void {
    it('hides a resource whose scope the caller does not hold, and refuses the read', function (): void {
        config(['mcp-bridge.sources.resource.scopes' => ['ops:read']]);

        $resource = app(RoutesResource::class);
        $scopes = app(TokenScopes::class);
        $config = app('config');

        // Both halves, because a client that listed a minute ago, or guessed
        // the uri, reaches the read without listing at all.
        test()->actingAs(new TokenUser([], 1), 'mcp-bridge');

        expect($resource->shouldRegister(new McpRequest([]), $config, $scopes))->toBeFalse()
            ->and($scopes->holds(new TokenUser([], 1), ['ops:read'], 'resource'))->toBeFalse()
            ->and($scopes->holds(new TokenUser(['ops:read'], 1), ['ops:read'], 'resource'))->toBeTrue();
    });

    it('leaves them open to any authenticated caller on the shipped wildcard', function (): void {
        test()->actingAs(new TokenUser([], 1), 'mcp-bridge');

        expect(app(RoutesResource::class)->shouldRegister(new McpRequest([]), app('config'), app(TokenScopes::class)))
            ->toBeTrue();
    });

    it('grants nobody from an empty scope list', function (): void {
        config(['mcp-bridge.sources.resource.scopes' => []]);

        test()->actingAs(new TokenUser(['ops:read'], 1), 'mcp-bridge');

        // The list says what it grants, never more: an empty list that
        // quietly meant everyone was the wrong way round.
        expect(app(RoutesResource::class)->shouldRegister(new McpRequest([]), app('config'), app(TokenScopes::class)))
            ->toBeFalse();
    });
});
