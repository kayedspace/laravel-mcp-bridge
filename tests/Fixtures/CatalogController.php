<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KayedSpace\McpBridge\Attributes\AllowFields;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Attributes\Paginate;
use KayedSpace\McpBridge\Attributes\QueryModel;
use KayedSpace\McpBridge\Query\ApiQuery;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The three ways a list endpoint can declare its query: all attributes, all
 * fluent chain, or a chain the attributes only enrich.
 */
class CatalogController
{
    #[McpTool(name: 'list_catalog', description: 'Search the course catalogue.')]
    #[QueryModel(Course::class)]
    #[AllowFilter('title', description: 'Search in course title')]
    #[AllowFilter('status', type: 'exact')]
    #[AllowFilter('author', type: 'exact', column: 'user_id')]
    #[AllowFilter('starts_after', type: 'scope')]
    #[AllowFilter('trashed', type: 'trashed')]
    #[AllowSort('title')]
    #[AllowSort('created_at', default: 'desc')]
    #[AllowInclude(['lessons', 'instructor'])]
    #[AllowInclude('lessonsCount', type: 'count')]
    #[AllowFields(['id', 'title', 'status', 'price'])]
    #[Paginate(perPage: 15, max: 50)]
    public function declared(Request $request): JsonResponse
    {
        // Echoes the query string it was given, so a test can assert that what
        // the agent sent is what Spatie would have parsed.
        return new JsonResponse([
            'data' => [],
            'query' => urldecode((string) $request->getQueryString()),
        ]);
    }

    /** No attributes at all: the chain is the declaration. */
    #[McpTool(name: 'list_catalog_chained', description: 'Search the catalogue, declared fluently.')]
    public function chained(): JsonResponse
    {
        $courses = QueryBuilder::for(Course::class)
            ->allowedFilters([
                'title',
                AllowedFilter::exact('status'),
                AllowedFilter::exact('author', 'user_id'),
                AllowedFilter::scope('starts_after'),
                AllowedFilter::trashed(),
            ])
            ->allowedSorts(['title', AllowedSort::field('created_at')])
            ->allowedIncludes(['lessons', AllowedInclude::count('lessonsCount')])
            ->allowedFields('id', 'title', 'lessons.title')
            ->defaultSort('-created_at')
            ->paginate(15);

        return new JsonResponse(['data' => $courses->items()]);
    }

    /**
     * The chain stays as it is, and one attribute adds what it cannot say.
     */
    #[McpTool(name: 'list_catalog_enriched', description: 'Search the catalogue, with the chain enriched.')]
    #[AllowFilter('status', enum: CourseStatus::class, description: 'Only courses in this state.')]
    public function enriched(): JsonResponse
    {
        $courses = QueryBuilder::for(Course::class)
            ->allowedFilters([AllowedFilter::exact('status'), 'title'])
            ->allowedSorts('title')
            ->paginate();

        return new JsonResponse(['data' => $courses->items()]);
    }

    /** Builds the query from the declaration, rather than repeating it. */
    #[McpTool(name: 'list_catalog_applied', description: 'Search the catalogue through ApiQuery.')]
    #[QueryModel(Course::class)]
    #[AllowFilter('title')]
    #[AllowSort('title')]
    #[Paginate(perPage: 5, max: 20)]
    public function applied(): JsonResponse
    {
        return new JsonResponse(['data' => ApiQuery::fromAttributes()->paginate()->items()]);
    }
}
