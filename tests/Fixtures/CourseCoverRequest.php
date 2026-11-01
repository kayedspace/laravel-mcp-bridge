<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class CourseCoverRequest extends FormRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'cover' => 'required|file|mimes:txt,png|max:64',
            'caption' => 'nullable|string|max:100',
        ];
    }
}
