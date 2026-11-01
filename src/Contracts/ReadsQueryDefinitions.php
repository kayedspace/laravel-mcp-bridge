<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Contracts;

use KayedSpace\McpBridge\Query\QueryDefinition;

/**
 * Where the allowed filters, sorts, includes and fields of a list endpoint
 * come from. Implemented once per vocabulary, so supporting another query
 * package means adding a reader rather than touching the schema pipeline.
 */
interface ReadsQueryDefinitions
{
    /** @param class-string $class */
    public function read(string $class, ?string $method = null): ?QueryDefinition;
}
