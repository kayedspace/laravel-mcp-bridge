<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpExample;
use KayedSpace\McpBridge\Attributes\McpGroup;
use KayedSpace\McpBridge\Attributes\McpOutput;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Attributes\McpRateLimit;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTimeout;
use KayedSpace\McpBridge\Attributes\McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[McpGroup('reports')]
class ReportController
{
    #[McpTool(description: 'Revenue by course.')]
    #[McpScope('reports:read')]
    #[McpAbility('run-reports')]
    #[McpRateLimit(2)]
    #[McpTimeout(120)]
    #[McpParam('from', description: 'Start date, inclusive.', example: '2026-01-01')]
    #[McpParam('currency', enum: ['usd', 'eur'], type: 'string')]
    #[McpParam('internal_flag', hidden: true)]
    #[McpExample(input: ['from' => '2026-01-01'], description: 'since the start of the year')]
    #[McpOutput(fields: ['id', 'total'], maxItems: 2)]
    #[IsReadOnly]
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => [
            ['id' => 1, 'total' => 100, 'internal_notes' => 'hidden from the agent'],
            ['id' => 2, 'total' => 200, 'internal_notes' => 'hidden from the agent'],
            ['id' => 3, 'total' => 300, 'internal_notes' => 'hidden from the agent'],
        ]]);
    }
}
