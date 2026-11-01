<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Output;

use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Enums\FieldType;

/**
 * The paging keys this package passes through, and what they hold.
 *
 * In one place because two things need it and they must agree: the shaper
 * decides which keys survive, and a tool's output schema promises the same
 * ones to the agent. Kept apart so neither becomes the other's source.
 */
final readonly class Pagination
{
    /** @var array<string, FieldType> */
    public const KEYS = [
        'next_cursor' => FieldType::String,
        'prev_cursor' => FieldType::String,
        'has_more' => FieldType::Boolean,
        'current_page' => FieldType::Integer,
        'last_page' => FieldType::Integer,
        'per_page' => FieldType::Integer,
        'total' => FieldType::Integer,
        'from' => FieldType::Integer,
        'to' => FieldType::Integer,
    ];

    /**
     * The inputs that make an endpoint a paged one.
     *
     * Separate from the keys above because they are a different vocabulary:
     * these are what an agent sends, those are what it reads back. Both live
     * here so neither is retyped somewhere that can drift from it.
     *
     * @var list<string>
     */
    public const INPUTS = ['page', 'per_page', 'cursor'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::KEYS);
    }

    /**
     * The same keys as schema fields, nested under `pagination`, so the
     * agent's description of them comes from the one list above.
     *
     * @return list<Field>
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::KEYS as $key => $type) {
            $fields[] = new Field(
                path: "pagination.{$key}",
                type: $type,
                origin: ['output:pagination'],
            );
        }

        return $fields;
    }
}
