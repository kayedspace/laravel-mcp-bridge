<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Query\QueryDefinition;

/**
 * One definition the controller and this package both read, for a list that
 * several endpoints share.
 */
class SharedQueryController
{
    public static function mcpQuery(): QueryDefinition
    {
        return new QueryDefinition(
            model: Course::class,
            filters: [new AllowFilter('title'), new AllowFilter('status', type: 'exact')],
            sorts: [new AllowSort('created_at', default: 'desc')],
            origin: 'definition',
        );
    }

    #[McpTool(name: 'list_shared', description: 'A list built from the shared definition.')]
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
