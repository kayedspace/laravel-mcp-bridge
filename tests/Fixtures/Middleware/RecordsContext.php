<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Middleware;

use Closure;
use Illuminate\Support\Facades\Context;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;

/** Writes down what the log context said while the call ran. */
class RecordsContext
{
    /** @var array<string, mixed> */
    public static array $seen = [];

    public function handle(ToolCall $call, Closure $next): ToolResult
    {
        self::$seen = [
            'tool' => Context::get('mcp.tool'),
            'request_id' => Context::get('mcp.request_id'),
            'actor' => Context::get('mcp.actor'),
            'arguments' => $call->arguments,
        ];

        return $next($call);
    }
}
