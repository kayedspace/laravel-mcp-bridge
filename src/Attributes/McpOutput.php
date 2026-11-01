<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Shape and trim what the agent receives. The record's types come from
 * `integrations.scramble.responses`, not from here.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class McpOutput
{
    /** @param list<string> $fields keep only these keys of each record */
    public function __construct(
        public array $fields = [],
        public ?int $maxItems = null,
    ) {}
}
