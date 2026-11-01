<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Tests\Fixtures\Course;
use KayedSpace\McpBridge\Tests\Fixtures\CourseStatus;

#[McpTool]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class RecalculateCourse implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public Course $course,
        public CourseStatus $status = CourseStatus::Draft,
        public ?string $reason = null,
        public bool $force = false,
    ) {}

    public function handle(): void
    {
        //
    }
}
