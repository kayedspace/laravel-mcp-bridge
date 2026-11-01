<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Require a Gate ability of the resolved actor, so the application's own Gate
 * answers "who may", not this package.
 *
 * Mandatory together with #[McpScope] on every source that is not an HTTP
 * route, because those inherit no protection of their own.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class McpAbility
{
    public function __construct(public string $ability) {}
}
