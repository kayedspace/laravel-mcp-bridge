<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Support;

/**
 * The short durations this package's configuration is written in: `30s`,
 * `5m`, `2h`, `90d`.
 *
 * Neither PHP nor Carbon parses that spelling, and two places needing it is
 * one more than enough to write it twice.
 */
final readonly class Duration
{
    protected const UNITS = [
        's' => 1,
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
    ];

    /**
     * Seconds, or the default when the value is not a duration at all. A bare
     * number is already seconds, which is what `ttl` settings usually hold.
     */
    public static function seconds(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^(\d+)\s*([smhd]?)$/', trim($value), $matches) !== 1) {
            return $default;
        }

        return (int) $matches[1] * (self::UNITS[$matches[2]] ?? 1);
    }
}
