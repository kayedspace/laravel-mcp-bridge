<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * Where a tool's work is queued, as plain data the manifest can store.
 *
 * @phpstan-type QueuedArray array{queue: string|null, connection: string|null, sync: bool, delay: int|null}
 */
final readonly class Queued
{
    public function __construct(
        public ?string $queue = null,
        public ?string $connection = null,
        public bool $sync = false,
        public ?int $delay = null,
    ) {}

    /** @return QueuedArray */
    public function toArray(): array
    {
        return [
            'queue' => $this->queue,
            'connection' => $this->connection,
            'sync' => $this->sync,
            'delay' => $this->delay,
        ];
    }

    /** @param QueuedArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            queue: $data['queue'],
            connection: $data['connection'],
            sync: $data['sync'],
            delay: $data['delay'],
        );
    }
}
