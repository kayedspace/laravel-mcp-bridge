<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Actions;

use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

/**
 * The shape lorisleiva/laravel-actions uses: rules() says what is accepted,
 * authorize() says who may do it, handle() does the work.
 */
#[McpTool(description: 'Publish a course.')]
#[McpScope('courses:write')]
#[McpAbility('publish-courses')]
class PublishCourse
{
    public static bool $authorized = true;

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'course_id' => 'required|integer|min:1',
            'announce' => 'boolean',
            'note' => 'nullable|string|max:120',
        ];
    }

    public function authorize(): bool
    {
        return self::$authorized;
    }

    /** @return array<string, mixed> */
    public function handle(int $course_id, bool $announce = false, ?string $note = null): array
    {
        return [
            'id' => $course_id,
            'announced' => $announce,
            'note' => $note,
            'actor' => auth()->id(),
        ];
    }
}
