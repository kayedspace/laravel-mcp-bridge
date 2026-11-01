<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Exceptions\InvalidQueryInput;
use KayedSpace\McpBridge\Query\ApiQuery;
use KayedSpace\McpBridge\Query\QueryReader;
use KayedSpace\McpBridge\Tests\Fixtures\CatalogController;
use KayedSpace\McpBridge\Tests\Fixtures\Course;
use KayedSpace\McpBridge\Tests\Fixtures\SharedQueryController;
use Spatie\QueryBuilder\QueryBuilder as SpatieQueryBuilder;

/** @return list<string> */
function enumOf(InputSchema $schema, string $path): array
{
    $field = $schema->get($path);

    expect($field)->not->toBeNull("No field at [{$path}]. Got: ".implode(', ', array_keys($schema->fields)));

    return $field->enum;
}

/**
 * Builds the query the way a controller action would, from a real request:
 * Spatie reads filters and sorts from the query string, so the test has to
 * send one rather than merge input.
 */
function apiQueryFor(string $action, string $queryString, bool $spatie = false): object
{
    app()->instance('request', Request::create("/api/v1/catalog?{$queryString}"));

    $definition = app(QueryReader::class)->read(CatalogController::class, $action);
    $query = app(ApiQuery::class);

    return $spatie
        ? $query->spatieQuery($definition, Course::class)
        : $query->eloquentQuery($definition, Course::class);
}

describe('declared in attributes', function (): void {
    it('turns every allowed filter into a typed input', function (): void {
        $input = toolNamed('list_catalog', compileQueryFixtures())->input;

        expect($input->get('filter.title')?->type->value)->toBe('string')
            ->and($input->get('filter.title')?->description)->toBe('Search in course title')
            // user_id is cast to integer on the model, and the alias never leaks.
            ->and($input->get('filter.author')?->type->value)->toBe('integer')
            ->and($input->get('filter.user_id'))->toBeNull()
            ->and($input->get('filter.trashed')?->enum)->toBe(['with', 'only']);
    });

    it('types a filter from the model cast, including a backed enum', function (): void {
        $input = toolNamed('list_catalog', compileQueryFixtures())->input;

        expect(enumOf($input, 'filter.status'))->toBe(['draft', 'published', 'archived']);
    });

    it('offers both directions of every allowed sort, and names the default', function (): void {
        $input = toolNamed('list_catalog', compileQueryFixtures())->input;

        expect(enumOf($input, 'sort.*'))->toBe(['title', '-title', 'created_at', '-created_at'])
            ->and($input->get('sort')?->default)->toBe(['-created_at'])
            ->and($input->get('sort')?->description)->toContain('Defaults to -created_at.');
    });

    it('lists includes with a cap, and marks the aggregate ones', function (): void {
        $input = toolNamed('list_catalog', compileQueryFixtures())->input;

        expect(enumOf($input, 'include.*'))->toBe(['lessons', 'instructor', 'lessonsCount'])
            ->and($input->get('include')?->max)->toBe(3)
            ->and($input->get('include')?->description)->toContain('single value');
    });

    it('keys sparse fieldsets by the model table', function (): void {
        $input = toolNamed('list_catalog', compileQueryFixtures())->input;

        expect(enumOf($input, 'fields.courses.*'))->toBe(['id', 'title', 'status', 'price']);
    });

    it('caps per_page by the declaration and the output budget', function (): void {
        config()->set('mcp-bridge.output.max_items', 25);

        $input = toolNamed('list_catalog', compileQueryFixtures())->input;

        expect($input->get('per_page')?->max)->toBe(25)
            ->and($input->get('per_page')?->min)->toBe(1)
            ->and($input->get('per_page')?->default)->toBe(15)
            ->and($input->get('page')?->min)->toBe(1);
    });
});

describe('read from a fluent chain', function (): void {
    it('finds the filters, sorts, includes and fields of a chain it never ran', function (): void {
        $definition = app(QueryReader::class)->read(CatalogController::class, 'chained');

        expect($definition)->not->toBeNull()
            ->and($definition->model)->toBe(Course::class)
            ->and(array_map(fn ($filter): string => $filter->name, $definition->filters))
            ->toBe(['title', 'status', 'author', 'starts_after', 'trashed'])
            ->and($definition->filters[0]->type())->toBe('partial')
            ->and($definition->filters[1]->type())->toBe('exact')
            ->and($definition->filters[2]->column)->toBe('user_id')
            ->and($definition->filters[3]->type())->toBe('scope')
            ->and($definition->filters[4]->type())->toBe('trashed')
            ->and($definition->defaultSort())->toBe('-created_at')
            ->and($definition->includeNames())->toBe(['lessons', 'lessonsCount'])
            ->and($definition->fields)->toBe(['*' => ['id', 'title'], 'lessons' => ['title']]);
    });

    it('does not advertise per_page when the chain fixes the page size', function (): void {
        $input = toolNamed('list_catalog_chained', compileQueryFixtures())->input;

        expect($input->get('per_page'))->toBeNull()
            ->and($input->get('page')?->description)->toContain('fixed at 15');
    });

    it('keeps the chain as the source and lets an attribute enrich one filter', function (): void {
        $definition = app(QueryReader::class)->read(CatalogController::class, 'enriched');

        expect($definition->origin)->toBe('chain+attributes')
            ->and(array_map(fn ($filter): string => $filter->name, $definition->filters))
            ->toBe(['status', 'title'])
            // The attribute adds a description; it must not retype the filter.
            ->and($definition->filters[0]->type())->toBe('exact')
            ->and($definition->filters[0]->description)->toBe('Only courses in this state.');
    });

    it('advertises per_page when the chain leaves the page size to the request', function (): void {
        $input = toolNamed('list_catalog_enriched', compileQueryFixtures())->input;

        expect($input->get('per_page'))->not->toBeNull();
    });
});

describe('a shared definition', function (): void {
    it('reads a static mcpQuery() the controller also uses', function (): void {
        $definition = app(QueryReader::class)->read(SharedQueryController::class, 'index');

        expect($definition?->origin)->toBe('definition')
            ->and($definition->model)->toBe(Course::class)
            ->and($definition->defaultSort())->toBe('-created_at');
    });
});

describe('the query string', function (): void {
    it('serialises lists the way Spatie parses them', function (): void {
        $definition = toolNamed('list_catalog', compileQueryFixtures());

        $result = app(ExecutesTools::class)->execute(callTool($definition, [
            'filter' => ['status' => 'published'],
            'sort' => ['-created_at'],
            'include' => ['lessons', 'lessonsCount'],
            'fields' => ['courses' => ['id', 'title']],
            'per_page' => 10,
        ]));

        expect($result->status)->toBe(200)
            ->and($result->payload['query'])
            ->toContain('filter[status]=published')
            ->toContain('sort=-created_at')
            ->toContain('include=lessons,lessonsCount')
            ->toContain('fields[courses]=id,title')
            ->toContain('per_page=10');
    });

    it('honours a custom parameter name from the Spatie config', function (): void {
        config()->set('query-builder.parameters.filter', 'f');

        $input = toolNamed('list_catalog', compileQueryFixtures())->input;

        expect($input->get('f.title'))->not->toBeNull()
            ->and($input->get('filter.title'))->toBeNull();
    });
});

describe('ApiQuery', function (): void {
    beforeEach(function (): void {
        Schema::create('courses', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('starts_on')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    });

    it('applies filters, sorts and the page cap without Spatie', function (): void {
        Course::query()->insert([
            ['title' => 'Cardiology', 'status' => 'published', 'user_id' => 1],
            ['title' => 'Paediatrics', 'status' => 'draft', 'user_id' => 2],
            ['title' => 'Cardiac Surgery', 'status' => 'published', 'user_id' => 1],
        ]);

        $query = apiQueryFor('declared', 'filter[title]=Cardi&sort=-title&per_page=99');

        expect($query->pluck('title')->all())->toBe(['Cardiology', 'Cardiac Surgery'])
            // per_page asked for 99, the declaration allows 50.
            ->and($query->getModel()->getPerPage())->toBe(50);
    });

    it('refuses a filter the endpoint does not allow, and says what is allowed', function (): void {
        expect(fn () => apiQueryFor('declared', 'filter[secret]=x'))
            ->toThrow(InvalidQueryInput::class, 'Requested filter(s) `secret` are not allowed.');
    });

    it('refuses a sort the endpoint does not allow', function (): void {
        expect(fn () => apiQueryFor('declared', 'sort=-secret'))
            ->toThrow(InvalidQueryInput::class, 'Allowed sort(s) are `title, created_at`.');
    });

    // Calls the Spatie branch directly rather than through ApiQuery's own
    // class_exists check, which is the point of it: the other ApiQuery tests
    // prove the Eloquent branch, and this one proves the branch that needs
    // the package. Skipped rather than failed when it is absent, so the suite
    // can be run against an application that never installed it.
    it('hands the same declaration to Spatie when it is installed', function (): void {
        $query = apiQueryFor('declared', 'filter[status]=published', spatie: true);

        expect($query->toSql())->toContain('"status" = ?')
            ->and($query->getEloquentBuilder()->getModel()->getPerPage())->toBe(15);
    })->skip(
        fn (): bool => ! class_exists(SpatieQueryBuilder::class),
        'spatie/laravel-query-builder is not installed.',
    );

    it('runs end to end from the agent call to the paginated result', function (): void {
        Course::query()->insert([
            ['title' => 'Cardiology', 'status' => 'published'],
            ['title' => 'Paediatrics', 'status' => 'draft'],
        ]);

        $definition = toolNamed('list_catalog_applied', compileQueryFixtures());
        $result = app(ExecutesTools::class)->execute(callTool($definition, [
            'filter' => ['title' => 'Cardi'],
        ]));

        expect($result->status)->toBe(200)
            ->and($result->payload['data'])->toHaveCount(1)
            ->and($result->payload['data'][0]['title'])->toBe('Cardiology');
    });
});
