<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Support\Duration;

it('reads the short spellings the configuration is written in', function (string|int|null $value, int $expected): void {
    expect(Duration::seconds($value, 7))->toBe($expected);
})->with([
    'seconds' => ['30s', 30],
    'minutes' => ['5m', 300],
    'hours' => ['2h', 7200],
    'days' => ['90d', 7_776_000],
    'bare number is seconds' => ['45', 45],
    'an int is seconds' => [120, 120],
    'spaces are tolerated' => [' 10 m ', 600],
    'nonsense falls back' => ['soon', 7],
    'null falls back' => [null, 7],
    'a negative sign is not a duration' => ['-5m', 7],
]);
