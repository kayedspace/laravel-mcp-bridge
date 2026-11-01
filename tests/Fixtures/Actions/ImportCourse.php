<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Actions;

use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Tests\Fixtures\CourseData;

/**
 * An action that takes a data object rather than scalars or rules: the
 * object's own rules are the schema, and the executor builds it.
 */
#[McpTool(description: 'Import a course from a data object.')]
#[McpScope('courses:write')]
#[McpAbility('publish-courses')]
class ImportCourse
{
    /** @return array<string, mixed> */
    public function handle(CourseData $data): array
    {
        return [
            'title' => $data->title,
            'status' => $data->status->value,
            'summary' => $data->summary,
        ];
    }
}
