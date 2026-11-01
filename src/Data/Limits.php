<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

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
