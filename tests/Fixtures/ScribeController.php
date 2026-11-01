<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use KayedSpace\McpBridge\Attributes\McpTool;

/**
 * An API documented with Scribe's annotations, where the docblock is the
 * declaration.
 *
 * @group Reporting
 */
class ScribeController
{
    /**
     * List the reports of a team.
     *
     * @queryParam per_page integer Results per page. Example: 15
     * @queryParam filter[status] string The status to match. Example: open
     * @queryParam tags[] string Tags to match.
     *
     * @urlParam team integer required The team id.
     */
    #[McpTool(name: 'list_team_reports')]
    public function index(int $team): JsonResponse
    {
        return new JsonResponse(['data' => [], 'team' => $team]);
    }

    /**
     * @bodyParam name string required The report name.
     * @bodyParam threshold number The cut-off. Example: 0.75
     */
    #[McpTool(name: 'create_report')]
    public function store(): JsonResponse
    {
        return new JsonResponse(['data' => ['id' => 1]], 201);
    }

    /**
     * @hideFromAPIDocumentation
     */
    #[McpTool(name: 'secret_report')]
    public function secret(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
