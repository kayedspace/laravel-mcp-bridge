<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use KayedSpace\McpBridge\Concerns\HasMcpTokens;
use Laravel\Sanctum\HasApiTokens;

/**
 * The user model most applications actually have: Sanctum's trait already on
 * it, and this package's added beside it. Both declare token methods, and
 * the one name they must not share is `tokenCan()`.
 */
class SanctumAgentUser extends User
{
    use HasApiTokens;
    use HasMcpTokens;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];
}
