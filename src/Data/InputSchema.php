<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * The merged input description for one tool, keyed by dot path. Compiled into
 * Illuminate\JsonSchema types as the last step, never before.
 *
 * @phpstan-import-type FieldArray from Field
 *
 * @phpstan-type InputSchemaArray array<string, FieldArray>
 */
final readonly class InputSchema
{
    /** @param array<string, Field> $fields */
    public function __construct(public array $fields = []) {}

    public static function empty(): self
    {
        return new self;
    }

    public function with(Field ...$fields): self
    {
        $merged = $this->fields;

        foreach ($fields as $field) {
            $merged[$field->path] = isset($merged[$field->path])
                ? $merged[$field->path]->mergedWith($field)
                : $field;
        }

        return new self($merged);
    }

    /**
     * Layer another schema on top of this one. Used for the five-layer merge:
     * route parameters, rules, query definitions, documentation attributes,
     * then this package's own attributes and config.
     */
    public function merge(self $other): self
    {
        return $this->with(...array_values($other->fields));
    }

    public function get(string $path): ?Field
    {
        return $this->fields[$path] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * The named fields present but invisible: the executor still knows their
     * wire, and the agent is never shown them and never taken at its word
     * about them.
     *
     * @param  list<string>  $paths
     */
    public function hiding(array $paths, string $reason, ?string $filledFrom = null): self
    {
        if ($paths === []) {
            return $this;
        }

        $fields = [];

        foreach ($this->fields as $path => $field) {
            $fields[$path] = in_array($path, $paths, true)
                ? $field->hidden($reason, $filledFrom)
                : $field;
        }

        return new self($fields);
    }

    /** Deterministic ordering, so an identical app produces an identical manifest. */
    public function sorted(): self
    {
        $fields = $this->fields;
        ksort($fields);

        return new self($fields);
    }

    /** @return InputSchemaArray */
    public function toArray(): array
    {
        return array_map(fn (Field $field): array => $field->toArray(), $this->sorted()->fields);
    }

    /** @param InputSchemaArray $data */
    public static function fromArray(array $data): self
    {
        $fields = [];

        foreach ($data as $path => $field) {
            $fields[$path] = Field::fromArray($field);
        }

        return new self($fields);
    }
}
