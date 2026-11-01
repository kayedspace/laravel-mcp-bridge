<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * The model a list endpoint queries, which is what Spatie's QueryBuilder::for()
 * is given. Declared when the package should read filters, sorts, includes and
 * fields from attributes rather than from a fluent chain.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class QueryModel
{
    /** @param class-string $model */
    public function __construct(
        public string $model,
        public ?string $resource = null,
    ) {}
}
