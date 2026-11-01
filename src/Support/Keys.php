<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Support;

/**
 * Keys that have to be strings.
 *
 * PHP turns a numeric-looking array key into an int, so an argument named `1`
 * arrives as `int(1)`. Everything downstream here is typed on string keys, and
 * three places were coercing them back by hand.
 */
final readonly class Keys
{
    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    public static function strings(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
