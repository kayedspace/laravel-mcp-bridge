<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

/** Opted in and fully authorised, but with no policy to decide per record. */
#[McpTool]
#[McpScope('courses:write')]
#[McpAbility('manage-catalogue')]
class Enrolment extends Model
{
    protected $table = 'enrolments';

    protected $fillable = ['course_id', 'user_id'];
}
