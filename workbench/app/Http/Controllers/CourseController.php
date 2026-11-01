<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use KayedSpace\McpBridge\Attributes\McpConfirm;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Attributes\QueryModel;
use KayedSpace\McpBridge\Query\ApiQuery;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Workbench\App\Http\Requests\CourseStoreRequest;
use Workbench\App\Models\Course;

/**
 * An ordinary API controller. Nothing here is written for agents except the
 * attributes, and nothing here changes shape because of them.
 */
class CourseController
{
    #[McpTool(description: 'List courses. Filter by title or status, sort, and page through them.')]
    #[QueryModel(Course::class)]
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => ApiQuery::fromAttributes()->paginate()->items()]);
    }

    #[McpTool(description: 'Read one course by its id.')]
    public function show(Course $course): JsonResponse
    {
        return new JsonResponse(['data' => $course]);
    }

    #[McpTool(description: 'Create a draft course.')]
    public function store(CourseStoreRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => Course::query()->create($request->validated())], 201);
    }

    #[McpTool(description: 'Delete a course for good.')]
    #[IsDestructive]
    #[McpConfirm('Permanently delete this course?')]
    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return new JsonResponse(null, 204);
    }
}
