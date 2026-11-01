<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * An example call, folded into the tool description. Agents pick a tool and
 * fill its arguments from the description, so one worked example is often
 * worth more than another sentence of prose.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class McpExample
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public array $input,
        public ?string $description = null,
    ) {}
}
