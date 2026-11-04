<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

use Illuminate\Cache\RateLimiting\Limit;

/**
 * What a tool is allowed to cost: how long it may take, and how often it may
 * be called.
 *
 * @phpstan-type LimitsArray array{timeout: int|null, rate_limit: string|null}
 */
final readonly class Limits
{
    public function __construct(
        public ?int $timeout = null,
        public ?string $rateLimit = null,
    ) {}

    /**
     * A rate limit as written in configuration, `10/minute` or `5/30seconds`,
     * as the framework's own limit, or null when it is not one.
     *
     * One reader, because the compiler refusing what the gate would have
     * read, or the gate ignoring what the compiler let through, is how a
     * typo became a tool with no limit at all. A zero window is not a limit
     * either: every hit would expire as it was counted.
     */
    public static function parseRate(string $rateLimit): ?Limit
    {
        if (preg_match('/^(\d+)\/(\d*)\s*(second|minute|hour)s?$/', $rateLimit, $matches) !== 1) {
            return null;
        }

        $every = $matches[2] === '' ? 1 : (int) $matches[2];

        if ($every === 0) {
            return null;
        }

        $unit = match ($matches[3]) {
            'second' => 1,
            'hour' => 3600,
            default => 60,
        };

        return new Limit(maxAttempts: (int) $matches[1], decaySeconds: $every * $unit);
    }

    /** The tool's rate limit, or null when it has none or it cannot be read. */
    public function rate(): ?Limit
    {
        return $this->rateLimit === null ? null : self::parseRate($this->rateLimit);
    }

    /** Whether the tool names a rate limit that cannot be read. */
    public function rateLimitIsInvalid(): bool
    {
        return $this->rateLimit !== null && $this->rate() === null;
    }

    /** @return LimitsArray */
    public function toArray(): array
    {
        return [
            'timeout' => $this->timeout,
            'rate_limit' => $this->rateLimit,
        ];
    }

    /** @param LimitsArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            timeout: $data['timeout'],
            rateLimit: $data['rate_limit'],
        );
    }
}
