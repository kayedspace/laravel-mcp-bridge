<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * Jobs, action classes and model operations.
 *
 * @phpstan-type ClassTargetArray array{type: 'class', class: class-string, method: string|null}
 */
final readonly class ClassTarget implements Target
{
    /** @param class-string $class */
    public function __construct(
        public string $class,
        public ?string $method = null,
    ) {}

    public function type(): string
    {
        return 'class';
    }

    public function describe(): string
    {
        return $this->method === null ? $this->class : "{$this->class}::{$this->method}";
    }

    /** @return ClassTargetArray */
    public function toArray(): array
    {
        return [
            'type' => 'class',
            'class' => $this->class,
            'method' => $this->method,
        ];
    }

    /** @param ClassTargetArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            class: $data['class'],
            method: $data['method'],
        );
    }
}
