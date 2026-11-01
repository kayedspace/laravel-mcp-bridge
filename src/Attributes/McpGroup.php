<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Assign a tool to a group, which becomes its tool-search category and can
 * route it to a server of its own.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class McpGroup
{
    public function __construct(public string $group) {}
}
