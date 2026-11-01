<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * A per-tool limit for agent traffic, separate from human API limits.
 *
 * Beats `sources.<key>.rate_limit`; a `routes.<name>.rate_limit` entry beats
 * it, because that entry exists to change a tool without touching its code.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class McpRateLimit
{
    /** @param int|null $perSeconds a window in seconds, for a limit tighter than a minute */
    public function __construct(
        public int $attempts,
        public int $perMinutes = 1,
        public ?int $perSeconds = null,
    ) {}

    public function toLimit(): string
    {
        if ($this->perSeconds !== null) {
            return "{$this->attempts}/{$this->perSeconds}seconds";
        }

        return $this->perMinutes === 1
            ? "{$this->attempts}/minute"
            : "{$this->attempts}/{$this->perMinutes}minutes";
    }
}
