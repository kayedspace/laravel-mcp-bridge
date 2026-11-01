<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class CourseIndexRequest extends FormRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'filter.title' => 'string|max:100',
            'filter.status' => 'in:draft,published,archived',
            'per_page' => 'integer|min:1|max:50',
        ];
    }
}
