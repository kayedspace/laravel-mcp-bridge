<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Keep a tool exposed but tell the agent to stop reaching for it, and what to
 * use instead.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class McpDeprecated
{
    public function __construct(
        public ?string $message = null,
        public ?string $until = null,
    ) {}
}
