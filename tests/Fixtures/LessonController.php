<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool]
class LessonController
{
    public function index(int $course): JsonResponse
    {
        return new JsonResponse(['data' => [['id' => 7, 'course_id' => $course]]]);
    }

    public function show(int $course, int $lesson): JsonResponse
    {
        return new JsonResponse(['data' => ['id' => $lesson, 'course_id' => $course]]);
    }
}
