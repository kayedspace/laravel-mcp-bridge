<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Support\Fingerprint;

it('reads the same arguments in any key order as one call, at every depth', function (): void {
    $one = Fingerprint::of(['title' => 'x', 'meta' => ['a' => 1, 'b' => ['d' => 4, 'c' => 3]]]);
    $other = Fingerprint::of(['meta' => ['b' => ['c' => 3, 'd' => 4], 'a' => 1], 'title' => 'x']);

    expect($one)->toBe($other);
});

it('keeps a list in order, because a reordered list is a different argument', function (): void {
    expect(Fingerprint::of(['tags' => ['a', 'b']]))->not->toBe(Fingerprint::of(['tags' => ['b', 'a']]));
});

it('tells different values apart', function (): void {
    expect(Fingerprint::of(['id' => 1]))->not->toBe(Fingerprint::of(['id' => '1']))
        ->and(Fingerprint::of([]))->not->toBe(Fingerprint::of(['id' => null]));
});
