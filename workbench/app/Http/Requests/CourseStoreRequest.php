<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * These rules are the tool's input schema. There is no second place that
 * describes this endpoint.
 */
class CourseStoreRequest extends FormRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'status' => 'required|in:draft,published,archived',
            'price' => 'nullable|numeric|min:0',
        ];
    }
}
