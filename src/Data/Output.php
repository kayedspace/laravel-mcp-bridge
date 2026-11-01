<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * Per-tool response shaping, overriding the global output settings, and the
 * record's typed shape where something reliable inferred it.
 *
 * @phpstan-import-type InputSchemaArray from InputSchema
 *
 * @phpstan-type OutputArray array{fields: list<string>, max_items: int|null, schema: InputSchemaArray|null}
 */
final readonly class Output
{
    /**
     * @param  list<string>  $fields  keep only these keys of each record
     * @param  InputSchema|null  $schema  the record's fields with their types, read from API documentation
     */
    public function __construct(
        public array $fields = [],
        public ?int $maxItems = null,
        public ?InputSchema $schema = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->fields === [] && $this->maxItems === null && $this->schema === null;
    }

    /** @return OutputArray */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'max_items' => $this->maxItems,
            'schema' => $this->schema?->toArray(),
        ];
    }

    /** @param OutputArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            fields: $data['fields'],
            maxItems: $data['max_items'],
            schema: $data['schema'] === null ? null : InputSchema::fromArray($data['schema']),
        );
    }
}
