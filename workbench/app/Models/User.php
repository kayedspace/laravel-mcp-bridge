<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use KayedSpace\McpBridge\Concerns\HasMcpTokens;
use Workbench\Database\Factories\UserFactory;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    // What every application adopting this package adds to its own user.
    use HasMcpTokens;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}
