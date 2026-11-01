<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;

/**
 * JSON Schema into this package's own field list: the compiler's direction
 * reversed.
 *
 * Needed wherever a schema arrives already written rather than inferred, which
 * today means a remote MCP server's advertised tools. Nesting is flattened
 * into the dotted paths everything else here speaks, and an array's member
 * type becomes a `*` leaf, so a re-exported tool is described exactly like a
 * local one.
 */
class JsonSchemaReader
{
    /**
     * @param  array<string, mixed>  $schema  a JSON Schema object
     */
    public function read(array $schema, WireIn $in = WireIn::Body): InputSchema
    {
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties)) {
            return InputSchema::empty();
        }

        $required = $this->names($schema['required'] ?? null);

        $fields = [];

        foreach ($properties as $name => $property) {
            if (! is_string($name) || ! is_array($property)) {
                continue;
            }

            $fields = [
                ...$fields,
                ...$this->fields($name, $property, in_array($name, $required, true), $in),
            ];
        }

        return (new InputSchema)->with(...$fields)->sorted();
    }

    /**
     * @param  array<array-key, mixed>  $property
     * @return list<Field>
     */
    protected function fields(string $path, array $property, bool $required, WireIn $in): array
    {
        $type = $this->type($property);

        if ($type === FieldType::Object && is_array($property['properties'] ?? null)) {
            return $this->object($path, $property, $in);
        }

        $field = new Field(
            path: $path,
            type: $type,
            required: $required,
            nullable: $this->isNullable($property),
            description: $this->string($property['description'] ?? null),
            format: $this->string($property['format'] ?? null),
            pattern: $this->string($property['pattern'] ?? null),
            enum: $this->names($property['enum'] ?? null),
            min: $this->number($property['minimum'] ?? $property['minLength'] ?? $property['minItems'] ?? null),
            max: $this->number($property['maximum'] ?? $property['maxLength'] ?? $property['maxItems'] ?? null),
            default: $property['default'] ?? null,
            wire: new Wire(in: $in, name: $path),
            origin: ['json-schema'],
        );

        if ($type !== FieldType::Array || ! is_array($property['items'] ?? null)) {
            return [$field];
        }

        // An array's member type is a leaf of its own, which is how the rest
        // of this package describes `tags.*`.
        return [$field, ...$this->fields($path.'.*', $property['items'], false, $in)];
    }

    /**
     * @param  array<array-key, mixed>  $property
     * @return list<Field>
     */
    protected function object(string $path, array $property, WireIn $in): array
    {
        $properties = $property['properties'] ?? [];
        $required = $this->names($property['required'] ?? null);

        $fields = [new Field(
            path: $path,
            type: FieldType::Object,
            description: $this->string($property['description'] ?? null),
            wire: new Wire(in: $in, name: $path),
            origin: ['json-schema'],
        )];

        foreach (is_array($properties) ? $properties : [] as $name => $child) {
            if (! is_string($name) || ! is_array($child)) {
                continue;
            }

            $fields = [
                ...$fields,
                ...$this->fields("{$path}.{$name}", $child, in_array($name, $required, true), $in),
            ];
        }

        return $fields;
    }

    /** @param array<array-key, mixed> $property */
    protected function type(array $property): FieldType
    {
        $type = $property['type'] ?? null;

        // A union is written as a list of types; the first that is not null is
        // the one an agent should send.
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    $type = $candidate;

                    break;
                }
            }
        }

        return FieldType::fromName(is_string($type) ? $type : null);
    }

    /** @param array<array-key, mixed> $property */
    protected function isNullable(array $property): bool
    {
        $type = $property['type'] ?? null;

        return is_array($type) && in_array('null', $type, true);
    }

    /** @return list<string> */
    protected function names(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $name) {
            if (is_scalar($name)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    protected function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }
}
