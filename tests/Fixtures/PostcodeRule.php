<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An application's own rule, which no schema can read by itself. */
class PostcodeRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$/', $value) !== 1) {
            $fail('The :attribute is not a postcode.');
        }
    }
}
