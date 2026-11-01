<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Output\OutputShaper;
use KayedSpace\McpBridge\Tests\Fixtures\Course;

/**
 * What a controller returns is the framework's business, not this package's:
 * the executor reads the rendered response, so an API Resource, a collection,
 * a paginator and a helper's nested array all arrive as JSON without the
 * package knowing which it was.
 *
 * Worth testing anyway, because the shaper's unwrapping is written for the
 * exact shapes resources produce and every other fixture imitates them by
 * hand. A test against an imitation proves the imitation.
 */
function shapeResource(string $tool, array $arguments = []): array
{
    config(['mcp-bridge.mode' => 'opt-in']);

    defineResourceRoutes();

    $definitions = app(ManifestCompiler::class)->compile()->definitions;

    foreach ($definitions as $definition) {
        if ($definition->name === $tool) {
            $result = app(ExecutesTools::class)->execute(callTool($definition, $arguments));

            return [$result, app(OutputShaper::class)->shape($result, $definition)];
        }
    }

    throw new RuntimeException("No tool named [{$tool}].");
}

beforeEach(function (): void {
    Schema::create('courses', function ($table): void {
        $table->id();
        $table->string('title');
        $table->string('status')->default('draft');
        $table->softDeletes();
        $table->timestamps();
    });

    Course::query()->insert([
        ['id' => 1, 'title' => 'Cardiology', 'status' => 'published'],
        ['id' => 2, 'title' => 'Neurology', 'status' => 'published'],
        ['id' => 3, 'title' => 'Oncology', 'status' => 'draft'],
    ]);
});

it('unwraps a single resource', function (): void {
    [$result, $shaped] = shapeResource('get_catalogue', ['course' => 1]);

    expect($result->status)->toBe(200)
        // The resource wrapped it in `data`; the agent gets the record.
        ->and($shaped->structured['data'])->toBe(['id' => 1, 'title' => 'Cardiology', 'status' => 'published']);
});

it('unwraps a resource collection into a list', function (): void {
    [, $shaped] = shapeResource('list_catalogues');

    expect($shaped->structured['data'])->toHaveCount(3)
        ->and($shaped->structured['data'][0]['title'])->toBe('Cardiology')
        ->and($shaped->text)->toContain('Returned 3 of 3 items');
});

it('carries the data in the text as well, for a client that reads only text', function (): void {
    [, $shaped] = shapeResource('list_catalogues');

    // The summary line, then the structured content as JSON: the protocol
    // asks for both, and a client that never reads structuredContent was
    // being handed the summary alone.
    [$summary, $json] = explode("\n", $shaped->text, 2);

    expect($summary)->toBe('Returned 3 of 3 items.')
        ->and(json_decode($json, true))->toBe($shaped->structured);
});

it('keeps the data in the text when structured content is switched off', function (): void {
    config(['mcp-bridge.output.structured' => false]);

    [, $shaped] = shapeResource('get_catalogue', ['course' => 1]);

    expect($shaped->structured)->toBeNull()
        ->and($shaped->text)->toContain('"title":"Cardiology"');
});

it('keeps the paging of a paginated resource collection and drops its noise', function (): void {
    [, $shaped] = shapeResource('paged_catalogue');

    expect($shaped->structured['data'])->toHaveCount(2)
        ->and($shaped->structured['pagination'])->toMatchArray([
            'current_page' => 1,
            'per_page' => 2,
            'total' => 3,
            'last_page' => 2,
        ])
        // links is a page of URLs an agent cannot use and should not read.
        ->and($shaped->structured)->not->toHaveKey('links');
});

it('renders resources nested inside a helper return value', function (): void {
    [, $shaped] = shapeResource('summary_catalogue');

    $data = $shaped->structured['data'];

    expect($data['newest'])->toBe(['id' => 1, 'title' => 'Cardiology', 'status' => 'published'])
        ->and($data['all'])->toHaveCount(3)
        ->and($data['count'])->toBe(3);
});

it('keeps metadata a resource attached on purpose', function (): void {
    [, $shaped] = shapeResource('annotated_catalogue');

    expect($shaped->structured['data']['title'])->toBe('Cardiology')
        ->and($shaped->structured['meta'])->toBe(['generated_by' => 'the catalogue team']);
});
