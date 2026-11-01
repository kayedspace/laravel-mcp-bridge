<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Manifest;

use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\ToolDefinition;

/**
 * What one compile pass produced, including what it would not expose. The
 * refusals matter as much as the definitions: they are what lint reports.
 */
final readonly class CompileResult
{
    /**
     * @param  list<ToolDefinition>  $definitions
     * @param  array<string, list<Refusal>>  $refusals  keyed by candidate id
     */
    public function __construct(
        public array $definitions = [],
        public array $refusals = [],
    ) {}

    public function count(): int
    {
        return count($this->definitions);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (ToolDefinition $definition): string => $definition->name, $this->definitions);
    }
}
