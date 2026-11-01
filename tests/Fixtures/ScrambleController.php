<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Attributes\QueryModel;

/**
 * An API already documented with dedoc/scramble, where the attributes are the
 * declaration and this package adds nothing.
 */
#[Group('Catalogue')]
class ScrambleController
{
    #[McpTool(name: 'list_documented')]
    #[Endpoint(title: 'Browse courses', description: 'Everything in the catalogue.')]
    #[QueryParameter(name: 'filter[author]', description: 'Only what this user wrote: pass their id.', type: 'string')]
    #[QueryParameter(name: 'per_page', description: 'Results per page.', type: 'integer', default: 15)]
    #[QueryParameter(name: 'tags[]', description: 'Tags to match.', type: 'string')]
    #[HeaderParameter(name: 'X-Trace-Id', description: 'A trace id to correlate logs.')]
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => [],
            'query' => urldecode((string) $request->getQueryString()),
            'trace' => $request->header('X-Trace-Id'),
        ]);
    }

    /**
     * Both packages describing one field: Scramble says what it means in a
     * human's words, and this package's own query attribute says what the
     * endpoint will accept.
     */
    #[McpTool(name: 'list_doubly_declared')]
    #[QueryParameter(name: 'filter[author]', description: 'Only what this user wrote: pass their id.', type: 'string')]
    #[AllowFilter('author')]
    #[QueryModel(Course::class)]
    public function doubly(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    #[McpTool(name: 'create_documented', description: 'Our description wins.')]
    #[Endpoint(title: 'Add a course', description: 'Scramble says this instead.')]
    #[BodyParameter(name: 'title', description: 'The course title.', required: true, type: 'string')]
    #[BodyParameter(name: 'meta[source]', description: 'Where the course came from.', type: 'string')]
    #[BodyParameter(name: 'internal_flag', description: 'Not for agents.', type: 'boolean')]
    #[IgnoreParam('internal_flag')]
    #[McpParam('meta.source', description: 'Our wording for the source.')]
    public function store(): JsonResponse
    {
        return new JsonResponse(['data' => ['id' => 1]], 201);
    }

    #[McpTool(name: 'get_documented')]
    #[PathParameter(name: 'course', description: 'The course id.', type: 'integer')]
    public function show(int $course): JsonResponse
    {
        return new JsonResponse(['data' => ['id' => $course]]);
    }

    /** Deliberately kept out of the public docs, so out of the tool list too. */
    #[McpTool(name: 'undocumented')]
    #[ExcludeRouteFromDocs]
    public function internal(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
