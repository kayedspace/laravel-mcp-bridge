<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * A data object the way spatie/laravel-data applications write one: the
 * property types and attributes are the validation rules.
 */
class CourseData extends Data
{
    public function __construct(
        public string $title,
        public CourseStatus $status,
        #[Max(120)]
        public ?string $summary = null,
    ) {}
}
