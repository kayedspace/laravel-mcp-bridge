<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KayedSpace\McpBridge\Attributes\McpConfirm;
use KayedSpace\McpBridge\Attributes\McpDeprecated;
use KayedSpace\McpBridge\Attributes\McpIgnore;
use KayedSpace\McpBridge\Attributes\McpInput;
use KayedSpace\McpBridge\Attributes\McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

class CourseController
{
    #[McpTool(description: 'List published courses with optional title search.')]
    public function index(CourseIndexRequest $request): JsonResponse
    {
        $request->validate($request->rules());

        return new JsonResponse([
            'data' => [
                ['id' => 1, 'title' => 'Cardiology Basics', 'password' => 'leaked', 'password_hint' => 'also leaked', 'summary' => null],
                ['id' => 2, 'title' => 'Paediatrics', 'password' => 'leaked', 'password_hint' => 'also leaked', 'summary' => null],
            ],
            'meta' => ['current_page' => 1, 'per_page' => (int) $request->query('per_page', '15'), 'total' => 2],
            'links' => ['first' => 'http://localhost/api/courses'],
        ]);
    }

    #[McpTool]
    public function show(int $course): JsonResponse
    {
        if ($course === 404) {
            abort(404, 'No course found.');
        }

        return new JsonResponse(['data' => ['id' => $course, 'title' => 'Cardiology Basics']]);
    }

    #[McpTool(description: 'Create a draft course.')]
    public function store(CourseStoreRequest $request): JsonResponse
    {
        $validated = $request->validate($request->rules());

        return new JsonResponse(['data' => ['id' => 42, ...$validated]], 201);
    }

    #[McpTool]
    public function destroy(int $course): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    #[McpTool(name: 'publish_course', description: 'Publish a course.')]
    public function publish(int $course): JsonResponse
    {
        return new JsonResponse(['data' => ['id' => $course, 'status' => 'published']]);
    }

    #[McpTool(description: 'Purge archived courses.')]
    #[IsDestructive]
    #[McpConfirm('Permanently purge every archived course?')]
    public function purge(): JsonResponse
    {
        return new JsonResponse(['data' => ['purged' => 3]]);
    }

    #[McpTool(description: 'List courses, the old way.')]
    #[McpDeprecated('Use list_courses instead.', until: '2.0')]
    public function legacy(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /** Validates inline, so the rules source is named explicitly. */
    #[McpTool(description: 'Create a course from an inline validated request.')]
    #[McpInput(CourseStoreRequest::class)]
    public function storeInline(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => ['id' => 43]], 201);
    }

    /** Takes a real file, which an agent sends as a staged upload id. */
    #[McpTool(description: 'Attach a cover image to a course.')]
    public function cover(CourseCoverRequest $request): JsonResponse
    {
        $request->validate($request->rules());

        $file = $request->file('cover');

        return new JsonResponse(['data' => [
            'name' => $file?->getClientOriginalName(),
            'size' => $file?->getSize(),
            'caption' => $request->input('caption'),
        ]]);
    }

    #[McpIgnore]
    public function export(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /** Deliberately not opted in. */
    public function internal(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    public function html(): string
    {
        return '<h1>Not JSON</h1>';
    }
}
