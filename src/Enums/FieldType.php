<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Enums;

enum FieldType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Array = 'array';
    case Object = 'object';
    case AnyOf = 'anyOf';

    /**
     * The type a declaration names, however it spells it.
     *
     * Every reader meets the same vocabulary written a different way: JSON
     * Schema says `integer`, a PHP signature says `int`, a docblock says
     * `float`. Saying what each one means belongs in one place.
     */
    public static function fromName(?string $name, self $default = self::String): self
    {
        return match (strtolower(trim((string) $name))) {
            'int', 'integer' => self::Integer,
            'number', 'float', 'double', 'decimal' => self::Number,
            'bool', 'boolean' => self::Boolean,
            'array', 'list' => self::Array,
            'object' => self::Object,
            'string' => self::String,
            default => $default,
        };
    }
}
