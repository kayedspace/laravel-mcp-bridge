<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class CourseStoreRequest extends FormRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'price' => 'numeric|min:0',
            'status' => 'required|in:draft,published',
            'summary' => 'nullable|string',
            'user_id' => 'integer',
            'tags.*' => 'string',
            'starts_at' => 'date|required_if:status,published',
            // The agent should not be asked to send this value twice.
            'access_code' => 'nullable|string|confirmed',
        ];
    }
}
