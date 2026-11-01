<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Contracts;

use KayedSpace\McpBridge\Data\Documented;

/**
 * Reads an API documentation vocabulary as declared schema rather than as
 * hints. Implemented once per documentation package, so supporting another one
 * means adding a reader.
 */
interface ReadsDocumentation
{
    /** @param class-string $class */
    public function read(string $class, ?string $method = null): ?Documented;
}
