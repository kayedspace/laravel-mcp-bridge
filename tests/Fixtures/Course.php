<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool]
#[McpScope('courses:write')]
#[McpAbility('manage-catalogue')]
class Course extends Model
{
    use SoftDeletes;

    protected $table = 'courses';

    protected $fillable = ['title', 'status', 'user_id', 'price', 'free', 'published_at', 'starts_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'user_id' => 'integer',
            'price' => 'decimal:2',
            'free' => 'boolean',
            'published_at' => 'datetime',
            'starts_on' => 'date',
        ];
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** @param Builder<$this> $query */
    public function scopeStartsAfter(Builder $query, string $date): void
    {
        $query->where('starts_on', '>', $date);
    }
}
