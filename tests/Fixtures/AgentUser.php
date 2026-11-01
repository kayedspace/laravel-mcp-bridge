<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use KayedSpace\McpBridge\Concerns\HasMcpTokens;

/**
 * The user model an agent connects as: a real record, with the token trait an
 * application adds to its own User.
 */
class AgentUser extends User
{
    use HasMcpTokens;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];
}
