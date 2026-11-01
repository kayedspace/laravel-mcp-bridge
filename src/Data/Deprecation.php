<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * A tool that still works but should not be reached for.
 *
 * @phpstan-type DeprecationArray array{message: string|null, until: string|null}
 */
final readonly class Deprecation
{
    public function __construct(
        public ?string $message = null,
        public ?string $until = null,
    ) {}

    /** The sentence appended to the tool description an agent reads. */
    public function notice(): string
    {
        return trim(implode(' ', array_filter([
            'Deprecated.',
            $this->message,
            $this->until === null ? null : "Scheduled for removal in {$this->until}.",
        ])));
    }

    /** @return DeprecationArray */
    public function toArray(): array
    {
        return ['message' => $this->message, 'until' => $this->until];
    }

    /** @param DeprecationArray $data */
    public static function fromArray(array $data): self
    {
        return new self(message: $data['message'], until: $data['until']);
    }
}
