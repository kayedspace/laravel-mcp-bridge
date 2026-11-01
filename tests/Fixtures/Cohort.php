<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpOutput;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

/**
 * A model that shapes its own tools from the class: narrows its operations
 * although its policy would allow them all, fills one column from the actor,
 * keeps another in the agent's hands whatever the global list says, describes
 * a field, and caps its pages.
 */
#[McpTool(operations: ['list', 'get', 'create'])]
#[McpScope('courses:write')]
#[McpAbility('manage-catalogue')]
#[McpParam('owner_id', fromAuth: true)]
#[McpParam('code', fromAuth: false)]
#[McpParam('title', description: 'The cohort title, shown to students.')]
#[McpOutput(maxItems: 5)]
class Cohort extends Model
{
    protected $table = 'cohorts';

    protected $fillable = ['title', 'code', 'owner_id'];
}
