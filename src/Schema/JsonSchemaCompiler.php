<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\NumberType;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Enums\FieldType;

/**
 * The last step of the schema pipeline: the intermediate representation into
 * Illuminate\JsonSchema types, which is the only shape laravel/mcp accepts.
 *
 * The subset has no oneOf, const, exclusiveMinimum or additionalProperties
 * schema, so anything a field could not express stays in its description
 * rather than being dropped.
 */
class JsonSchemaCompiler
{
    /** @return array<string, Type> */
    public function compile(InputSchema $schema, JsonSchema $factory): array
    {
        $root = new SchemaNode;

        foreach ($schema->sorted()->fields as $field) {
            // A hidden field is the application's to fill, so the agent never
            // sees it in the schema.
            if ($field->hidden) {
                continue;
            }

            $this->insert($root, explode('.', $field->path), $field);
        }

        $properties = [];

        foreach ($root->children as $name => $node) {
            $properties[$name] = $this->type($node, $factory);
        }

        return $properties;
    }

    /** @param list<string> $segments */
    protected function insert(SchemaNode $node, array $segments, Field $field): void
    {
        foreach ($segments as $segment) {
            $node = $node->child($segment);
        }

        $node->field = $field;
    }

    protected function type(SchemaNode $node, JsonSchema $factory): Type
    {
        $type = match (true) {
            $node->isLeaf() => $this->scalar($node->field, $factory),
            $node->isCollection() => $factory->array()->items($this->type($node->child('*'), $factory)),
            default => $factory->object($this->properties($node, $factory)),
        };

        return $node->field === null ? $type : $this->decorate($type, $node->field);
    }

    /** @return array<string, Type> */
    protected function properties(SchemaNode $node, JsonSchema $factory): array
    {
        $properties = [];

        foreach ($node->children as $name => $child) {
            $properties[$name] = $this->type($child, $factory);
        }

        return $properties;
    }

    protected function scalar(?Field $field, JsonSchema $factory): Type
    {
        return match ($field?->type) {
            FieldType::Integer => $factory->integer(),
            FieldType::Number => $factory->number(),
            FieldType::Boolean => $factory->boolean(),
            FieldType::Array => $factory->array(),
            FieldType::Object => $factory->object(),
            default => $factory->string(),
        };
    }

    protected function decorate(Type $type, Field $field): Type
    {
        $description = $this->description($field);

        if ($description !== null) {
            $type = $type->description($description);
        }

        if ($field->enum !== []) {
            $type = $type->enum($field->enum);
        }

        $type = $this->constrain($type, $field);

        if ($field->required) {
            $type = $type->required();
        }

        return $field->nullable ? $type->nullable() : $type;
    }

    protected function constrain(Type $type, Field $field): Type
    {
        if ($type instanceof StringType) {
            if ($field->format !== null) {
                $type = $type->format($field->format);
            }

            if ($field->pattern !== null) {
                $type = $type->pattern($field->pattern);
            }

            if (is_int($field->min)) {
                $type = $type->min($field->min);
            }

            if (is_int($field->max)) {
                $type = $type->max($field->max);
            }

            return is_string($field->default) ? $type->default($field->default) : $type;
        }

        if ($type instanceof IntegerType) {
            if (is_int($field->min)) {
                $type = $type->min($field->min);
            }

            if (is_int($field->max)) {
                $type = $type->max($field->max);
            }

            return is_int($field->default) ? $type->default($field->default) : $type;
        }

        if ($type instanceof NumberType) {
            if ($field->min !== null) {
                $type = $type->min($field->min);
            }

            if ($field->max !== null) {
                $type = $type->max($field->max);
            }

            return is_int($field->default) || is_float($field->default)
                ? $type->default($field->default)
                : $type;
        }

        if ($type instanceof ArrayType) {
            if (is_int($field->min)) {
                $type = $type->min($field->min);
            }

            if (is_int($field->max)) {
                $type = $type->max($field->max);
            }

            return is_array($field->default) && array_is_list($field->default)
                ? $type->default($field->default)
                : $type;
        }

        return $type;
    }

    /** Constraints the subset cannot carry are stated in plain words instead. */
    protected function description(Field $field): ?string
    {
        $parts = array_filter([
            $field->description,
            $field->unexpressible === []
                ? null
                : 'Also validated: '.implode(', ', $field->unexpressible).'.',
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }
}
