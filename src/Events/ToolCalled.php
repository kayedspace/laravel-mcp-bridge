<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Events;

use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Enums\Outcome;

/**
 * Every call an agent made, however it ended.
 *
 * The audit trail answers "what happened" afterwards, by reading rows. This
 * answers it as it happens, which is what a metrics counter, an alert on a
 * run of refusals, or a per-tenant usage meter needs, and none of those
 * should have to poll a table to find out.
 *
 * One event carrying the outcome rather than three classes: a listener that
 * cares about refusals alone filters on it in a line, while one that counts
 * every call would otherwise have to subscribe three times and keep the list
 * up to date when a fourth outcome is added.
 */
final readonly class ToolCalled
{
    public function __construct(
        public ToolCall $call,
        public ToolResult $result,
        public Outcome $outcome,
        public int $durationMs,
    ) {}
}
