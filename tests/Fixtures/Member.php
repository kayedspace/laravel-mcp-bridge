<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Model;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Concerns\HasMcpTokens;

/**
 * The shape of Laravel's default User: a password that is fillable and
 * hidden, and a token column whose name the redaction list matches. The
 * name on #[McpTool] is one a model cannot take, since it names one tool
 * per operation, so its tools stay prefixed.
 */
#[McpTool(name: 'people')]
#[McpScope('members:write')]
#[McpAbility('manage-members')]
class Member extends Model
{
    use HasMcpTokens;

    protected $table = 'members';

    protected $fillable = ['name', 'email', 'password', 'api_token'];

    protected $hidden = ['password'];
}
