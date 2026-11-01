<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use KayedSpace\McpBridge\Contracts\MapsRules;
use KayedSpace\McpBridge\Data\Field;

/** Says what PostcodeRule means, so the agent gets a pattern and not a note. */
class PostcodeMapper implements MapsRules
{
    public function handles(mixed $rule): bool
    {
        return $rule instanceof PostcodeRule;
    }

    public function map(Field $field, mixed $rule): Field
    {
        return $field->with(
            description: 'A UK postcode, such as SW1A 1AA.',
            pattern: '^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$',
        );
    }
}
