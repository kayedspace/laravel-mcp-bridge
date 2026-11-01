<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Integrations;

use KayedSpace\McpBridge\Contracts\ReadsDocumentation;
use KayedSpace\McpBridge\Data\Documented;

/**
 * Every documentation vocabulary the application happens to use, as one
 * reading.
 *
 * Docblock annotations are read first and attributes layer over them, because
 * an attribute is code the compiler sees while a docblock is text: when both
 * describe one parameter, the one the language checks wins.
 */
class DocumentationReader implements ReadsDocumentation
{
    public function __construct(
        protected ScribeReader $scribe,
        protected ScrambleReader $scramble,
    ) {}

    /** @param class-string $class */
    public function read(string $class, ?string $method = null): ?Documented
    {
        $readings = array_values(array_filter([
            $this->scribe->read($class, $method),
            $this->scramble->read($class, $method),
        ]));

        if ($readings === []) {
            return null;
        }

        $documented = array_shift($readings);

        foreach ($readings as $reading) {
            $documented = $documented->mergedWith($reading);
        }

        return $documented;
    }
}
