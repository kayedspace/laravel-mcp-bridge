<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * How a tool's work is queued.
 *
 * On a job, this is where it is dispatched to. Agent-dispatched jobs go to a
 * dedicated queue by default so they cannot starve or poison the
 * application's own, and so they are separable in Horizon.
 *
 * `sync: true` runs the job in the request instead, which is the exception: an
 * agent waiting on a queue worker it cannot see is worse than a short wait,
 * but only for work short enough to finish inside the timeout.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class McpQueue
{
    public function __construct(
        public ?string $queue = null,
        public ?string $connection = null,
        public bool $sync = false,
        public ?int $delay = null,
    ) {}
}
