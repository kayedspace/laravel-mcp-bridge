<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use KayedSpace\McpBridge\Attributes\McpTool;

/**
 * The ways a controller actually returns data, as opposed to the one way the
 * other fixtures do. Nothing here builds a response by hand: these return
 * resources, collections, paginated collections and a plain array with
 * resources inside it, and let the framework render them.
 */
#[McpTool]
class ResourceController
{
    public function show(Course $course): CourseResource
    {
        return CourseResource::make($course);
    }

    public function index(): AnonymousResourceCollection
    {
        return CourseResource::collection(Course::query()->get());
    }

    public function paginated(): AnonymousResourceCollection
    {
        return CourseResource::collection(Course::query()->paginate(2));
    }

    /**
     * A helper's return value: resources nested inside an array alongside
     * other things, which is what most real endpoints look like once they
     * have more than one thing to say.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'data' => [
                'newest' => CourseResource::make(Course::query()->first()),
                'all' => CourseResource::collection(Course::query()->get()),
                'count' => Course::query()->count(),
            ],
        ];
    }

    /** A resource carrying metadata the developer attached on purpose. */
    public function withMeta(): CourseResource
    {
        return CourseResource::make(Course::query()->first())
            ->additional(['meta' => ['generated_by' => 'the catalogue team']]);
    }
}
