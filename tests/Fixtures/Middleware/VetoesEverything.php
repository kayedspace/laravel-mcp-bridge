<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Middleware;

use Closure;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;

/** Answers before the source runs, the way a tenancy check would. */
class VetoesEverything
{
    public function handle(ToolCall $call, Closure $next): ToolResult
    {
        return ToolResult::error('MCP_BRIDGE_FORBIDDEN', 'Not for this tenant.');
    }
}
