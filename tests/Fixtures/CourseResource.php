<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A real API Resource, because the shaper's unwrapping is written for the
 * shapes these produce and was only ever tested against hand-built arrays
 * that imitated them.
 *
 * @property Course $resource
 */
class CourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'title' => $this->resource->title,
            'status' => $this->resource->status,
        ];
    }
}
