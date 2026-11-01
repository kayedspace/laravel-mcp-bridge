<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;
use Workbench\Database\Factories\CourseFactory;

/**
 * Opted in as a model source as well, so `mcp-bridge:tools` shows both a
 * route tool and a model tool for the same records, and the difference
 * between inherited and granted authority is visible in one app.
 */
#[McpTool]
#[McpScope('courses:write')]
#[McpAbility('manage-courses')]
#[AllowFilter('title')]
#[AllowFilter('status', type: 'exact')]
#[AllowSort('created_at', default: 'desc')]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected $fillable = ['title', 'status', 'price'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'price' => 'decimal:2',
        ];
    }
}
