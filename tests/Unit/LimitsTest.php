<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Data\Limits;

it('reads a rate limit as attempts and a window in seconds', function (string $rateLimit, int $attempts, int $seconds): void {
    $limit = Limits::parseRate($rateLimit);

    expect($limit?->maxAttempts)->toBe($attempts)
        ->and($limit?->decaySeconds)->toBe($seconds);
})->with([
    'per second' => ['3/second', 3, 1],
    'per minute' => ['10/minute', 10, 60],
    'per hour' => ['100/hour', 100, 3600],
    'a window of seconds' => ['5/30seconds', 5, 30],
    'a window of minutes' => ['100/60minutes', 100, 3600],
    'a plural with no count' => ['10/minutes', 10, 60],
    'a space before the unit' => ['10/5 minutes', 10, 300],
]);

it('reads nothing from a limit it cannot honour', function (string $rateLimit): void {
    expect(Limits::parseRate($rateLimit))->toBeNull();
})->with([
    'a unit it does not know' => ['1000/day'],
    'an abbreviated unit' => ['10/min'],
    'prose' => ['10 per minute'],
    'a capital letter' => ['10/Minute'],
    'surrounding space' => [' 10/minute'],
    'a zero window, where every hit expires as it is counted' => ['10/0minutes'],
    'empty' => [''],
]);

it('tells a missing limit from one that cannot be read', function (): void {
    expect((new Limits)->rateLimitIsInvalid())->toBeFalse()
        ->and((new Limits(rateLimit: '10/minute'))->rateLimitIsInvalid())->toBeFalse()
        ->and((new Limits(rateLimit: '10/day'))->rateLimitIsInvalid())->toBeTrue();
});
