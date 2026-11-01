<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

use Throwable;

/** What a source returns, before the output pipeline shapes it for an agent. */
final readonly class SourceResult
{
    /** @param array<string, mixed> $meta pagination cursors, exit code, queue id, duration */
    public function __construct(
        public int $status,
        public mixed $payload = null,
        public array $meta = [],
        public ?Throwable $exception = null,
    ) {}

    /** @param array<string, mixed> $meta */
    public static function ok(mixed $payload, array $meta = []): self
    {
        return new self(status: 200, payload: $payload, meta: $meta);
    }

    /** @param array<string, mixed> $meta */
    public static function failed(
        int $status,
        mixed $payload = null,
        ?Throwable $exception = null,
        array $meta = [],
    ): self {
        return new self(status: $status, payload: $payload, meta: $meta, exception: $exception);
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
