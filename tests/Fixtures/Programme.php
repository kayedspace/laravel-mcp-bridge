<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

/**
 * A model whose tools may load relations: declared ones, a hidden one that
 * stays out whatever is declared, and one only an ability opens.
 */
#[McpTool]
#[McpScope('courses:write')]
#[McpAbility('manage-catalogue')]
#[AllowInclude(['courses', 'courses.lessons'])]
#[AllowInclude('coursesCount', type: 'count')]
#[AllowInclude('secrets')]
#[AllowInclude('notes', ability: 'see-notes')]
class Programme extends Model
{
    protected $table = 'programmes';

    protected $fillable = ['title'];

    protected $hidden = ['secrets'];

    /** @return HasMany<Course, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'programme_id');
    }

    /** @return HasMany<Lesson, $this> */
    public function secrets(): HasMany
    {
        return $this->hasMany(Lesson::class, 'programme_id');
    }

    /** @return HasMany<Lesson, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Lesson::class, 'programme_id');
    }
}
