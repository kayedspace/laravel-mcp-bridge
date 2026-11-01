<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Support;

/**
 * One hash for one set of arguments, however a client ordered its keys.
 *
 * A confirmation handle and an idempotency key are both bound to the
 * arguments they were issued for, and both have to read `{"a":1,"b":2}` and
 * `{"b":2,"a":1}` as the same call, at every depth: a client that
 * re-serialises JSON on retry owes nobody the original key order.
 */
final readonly class Fingerprint
{
    /** @param array<array-key, mixed> $arguments */
    public static function of(array $arguments): string
    {
        return hash('sha256', (string) json_encode(self::sorted($arguments)));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    protected static function sorted(array $values): array
    {
        // A list keeps its order: `[1, 2]` and `[2, 1]` are different
        // arguments. Only an object's keys are reordered.
        if (! array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::sorted($value);
            }
        }

        return $values;
    }
}
