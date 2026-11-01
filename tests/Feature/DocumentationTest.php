<?php

declare(strict_types=1);

use Dedoc\Scramble\Attributes\Parameter as ScrambleParameter;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Integrations\ScrambleParameters;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Server\GeneratedTool;
use KayedSpace\McpBridge\Tests\Fixtures\CatalogController;

function documented(): CompileResult
{
    return compileDocumentedFixtures();
}

describe('Scramble attributes', function (): void {
    it('reads a bracketed query parameter as a nested input', function (): void {
        $input = toolNamed('list_documented', documented())->input;

        expect($input->get('filter.author')?->description)
            ->toBe('Only what this user wrote: pass their id.')
            ->and($input->get('filter.author')?->wire?->name)->toBe('filter[author]')
            ->and($input->get('per_page')?->type->value)->toBe('integer')
            ->and($input->get('per_page')?->default)->toBe(15);
    });

    it('reads an empty pair of brackets as a list', function (): void {
        $input = toolNamed('list_documented', documented())->input;

        expect($input->get('tags.*'))->not->toBeNull()
            ->and($input->get('tags.*')?->description)->toBe('Tags to match.');
    });

    it('leaves headers out of the schema unless the application asks for them', function (): void {
        $input = toolNamed('list_documented', documented())->input;

        expect($input->get('X-Trace-Id'))->toBeNull();

        config()->set('mcp-bridge.integrations.scramble.read_headers', true);

        $input = toolNamed('list_documented', documented())->input;

        expect($input->get('X-Trace-Id')?->wire?->in->value)->toBe('header');
    });

    it('sends a documented header as a header, not as input', function (): void {
        config()->set('mcp-bridge.integrations.scramble.read_headers', true);

        $definition = toolNamed('list_documented', documented());
        $result = app(ExecutesTools::class)->execute(callTool($definition, [
            'X-Trace-Id' => 'trace-42',
            'per_page' => 5,
        ]));

        expect($result->payload['trace'])->toBe('trace-42')
            ->and($result->payload['query'])->toBe('per_page=5');
    });

    it('takes the title and description from #[Endpoint] when ours are absent', function (): void {
        $definition = toolNamed('list_documented', documented());

        expect($definition->title)->toBe('Browse courses')
            ->and($definition->description)->toStartWith('Everything in the catalogue.');
    });

    it('keeps our own description when both describe the endpoint', function (): void {
        expect(toolNamed('create_documented', documented())->description)
            ->toStartWith('Our description wins.');
    });

    it('takes the group from #[Group]', function (): void {
        expect(toolNamed('list_documented', documented())->groups)->toBe(['Catalogue']);
    });

    it('hides a parameter the documentation ignores', function (): void {
        $input = toolNamed('create_documented', documented())->input;

        expect($input->get('internal_flag')?->hidden)->toBeTrue()
            ->and($input->get('title')?->required)->toBeTrue();
    });

    it('lets #[McpParam] override a documented description', function (): void {
        $input = toolNamed('create_documented', documented())->input;

        expect($input->get('meta.source')?->description)->toBe('Our wording for the source.');
    });

    it('enriches a route parameter from #[PathParameter]', function (): void {
        $input = toolNamed('get_documented', documented())->input;

        expect($input->get('course')?->description)->toBe('The course id.')
            ->and($input->get('course')?->type->value)->toBe('integer')
            ->and($input->get('course')?->wire?->in->value)->toBe('path');
    });

    it('refuses a route the documentation excludes', function (): void {
        $result = documented();

        expect($result->names())->not->toContain('undocumented')
            ->and($result->refusals)->toHaveKey('route:api.v1.documented.internal:GET');
    });

    it('can be turned off without touching the documentation', function (): void {
        config()->set('mcp-bridge.integrations.scramble.enabled', false);

        $definition = toolNamed('list_documented', documented());

        expect($definition->input->get('filter.author'))->toBeNull()
            ->and($definition->title)->toBeNull();
    });

    it('keeps an excluded route when exclusions are not respected', function (): void {
        config()->set('mcp-bridge.discovery.respect_docs_exclusions', false);

        expect(documented()->names())->toContain('undocumented');
    });
})->skip(
    fn (): bool => ! class_exists(ScrambleParameter::class),
    'dedoc/scramble is not installed.',
);

describe('feeding Scramble', function (): void {
    it('turns our query declaration into OpenAPI query parameters', function (): void {
        defineQueryRoutes();

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (IlluminateRoute $route): bool => $route->getName() === 'api.v1.catalog.declared');

        $results = app(ScrambleParameters::class)->handle(new RouteInfo($route, 'GET'), []);

        $names = array_map(
            fn (Parameter $parameter): string => $parameter->name,
            $results[0]->parameters,
        );

        expect($names)->toContain('filter[status]', 'filter[title]', 'sort', 'include', 'fields[courses]', 'per_page', 'page')
            ->and($results[0]->sourceClass)->toBe(CatalogController::class);
    });

    it('leaves a route with no query declaration alone', function (): void {
        defineApiRoutes();

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (IlluminateRoute $route): bool => $route->getName() === 'api.v1.courses.show');

        expect(app(ScrambleParameters::class)->handle(new RouteInfo($route, 'GET'), []))->toBe([]);
    });
})->skip(
    fn (): bool => ! class_exists(ScrambleParameter::class),
    'dedoc/scramble is not installed.',
);

describe('Scribe annotations', function (): void {
    it('reads @queryParam with its type, description and example', function (): void {
        $input = toolNamed('list_team_reports', documented())->input;

        expect($input->get('per_page')?->type->value)->toBe('integer')
            ->and($input->get('per_page')?->description)->toBe('Results per page.')
            ->and($input->get('per_page')?->examples)->toBe(['15']);
    });

    it('nests a bracketed name and makes a list of an empty pair', function (): void {
        $input = toolNamed('list_team_reports', documented())->input;

        expect($input->get('filter.status')?->description)->toBe('The status to match.')
            ->and($input->get('filter.status')?->wire?->name)->toBe('filter[status]')
            ->and($input->get('tags.*'))->not->toBeNull();
    });

    it('reads @urlParam as a required path input', function (): void {
        $input = toolNamed('list_team_reports', documented())->input;

        expect($input->get('team')?->required)->toBeTrue()
            ->and($input->get('team')?->description)->toBe('The team id.');
    });

    it('reads @bodyParam, including required and the example', function (): void {
        $input = toolNamed('create_report', documented())->input;

        expect($input->get('name')?->required)->toBeTrue()
            ->and($input->get('threshold')?->type->value)->toBe('number')
            ->and($input->get('threshold')?->examples)->toBe(['0.75']);
    });

    it('takes the group from the controller @group', function (): void {
        expect(toolNamed('list_team_reports', documented())->groups)->toBe(['Reporting']);
    });

    it('refuses a route hidden from the documentation', function (): void {
        expect(documented()->names())->not->toContain('secret_report');
    });
});

it('keeps a description someone wrote over one this package generated', function (): void {
    $input = toolNamed('list_doubly_declared', compileDocumentedFixtures())->input;

    // #[AllowFilter] is a later layer than Scramble's attribute and wins on
    // what the endpoint accepts. It should not win on the prose: the
    // generated line is a template, and the other is someone's sentence.
    expect($input->get('filter.author')?->description)
        ->toBe('Only what this user wrote: pass their id.');
})->skip(
    fn (): bool => ! class_exists(ScrambleParameter::class),
    'dedoc/scramble is not installed.',
);

it('still lets a query attribute that says something win', function (): void {
    // Two developers describing one field is a real disagreement, and the
    // later layer settles it the way it settles everything else.
    $input = toolNamed('list_documented', compileDocumentedFixtures())->input;

    expect($input->get('per_page')?->description)->toBe('Results per page.');
})->skip(
    fn (): bool => ! class_exists(ScrambleParameter::class),
    'dedoc/scramble is not installed.',
);

describe('Scramble responses', function (): void {
    beforeEach(function (): void {
        config()->set('mcp-bridge.integrations.scramble.responses', true);

        Schema::create('courses', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->softDeletes();
            $table->timestamps();
        });

        defineResourceRoutes();
    });

    it('types a record from the response Scramble infers', function (): void {
        $output = toolNamed('get_catalogue', app(ManifestCompiler::class)->compile())->output;

        // `id` comes from an untyped `getKey()`. Scramble can say nothing
        // about it, and a key with no type is left out rather than guessed.
        expect($output?->schema?->get('id'))->toBeNull()
            ->and($output?->schema?->get('title')?->type->value)->toBe('string')
            ->and($output?->schema?->get('status')?->enum)->toBe(['draft', 'published', 'archived']);
    });

    it('reduces a collection to its member and advertises typed records', function (): void {
        $definition = toolNamed('list_catalogues', app(ManifestCompiler::class)->compile());
        $tool = app(GeneratedTool::class, ['definition' => $definition]);
        $schema = $tool->outputSchema(new JsonSchemaTypeFactory);

        expect($definition->output?->schema?->get('title')?->type->value)->toBe('string')
            ->and($schema['data']->toArray()['properties']['title']['type'])->toBe('string');
    });

    it('is off unless asked for', function (): void {
        config()->set('mcp-bridge.integrations.scramble.responses', false);

        expect(toolNamed('get_catalogue', app(ManifestCompiler::class)->compile())->output)->toBeNull();
    });
})->skip(
    fn (): bool => ! class_exists(ScrambleParameter::class),
    'dedoc/scramble is not installed.',
);
