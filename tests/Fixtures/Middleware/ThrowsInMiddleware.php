<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Middleware;

use Closure;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;
use RuntimeException;

/** Application code that breaks, the way a tenant lookup against a missing table would. */
class ThrowsInMiddleware
{
    public function handle(ToolCall $call, Closure $next): ToolResult
    {
        throw new RuntimeException('tenant lookup exploded');
    }
}
