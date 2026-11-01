<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Require a token scope. Checked twice: when listing, so the tool is hidden,
 * and when calling, because hiding a tool is not enforcement.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class McpScope
{
    public function __construct(public string $scope) {}
}
