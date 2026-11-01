<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Uploads;

use KayedSpace\McpBridge\Data\InputSchema;

/**
 * How a file input is marked in a schema.
 *
 * A JSON Schema `format` rather than a flag on the field, because it is
 * exactly what format is for: the string is an upload id, and saying so in
 * the schema tells the agent and the executor the same thing in one place.
 */
final readonly class Uploads
{
    public const FORMAT = 'mcp-upload';

    /** Whether anything in this schema takes a staged file. */
    public static function wanted(InputSchema $schema): bool
    {
        foreach ($schema->fields as $field) {
            if ($field->format === self::FORMAT) {
                return true;
            }
        }

        return false;
    }
}
