<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Data\ToolResult;

interface AuthorizesTools
{
    /** Whether this actor may see the tool at all when listing. */
    public function visible(ToolDefinition $definition, ?Authenticatable $actor): bool;

    /**
     * Null when the call may proceed. Otherwise the result to return instead
     * of running it, which may be a refusal or a confirmation request.
     */
    public function check(ToolCall $call): ?ToolResult;
}
