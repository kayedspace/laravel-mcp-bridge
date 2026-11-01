<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use KayedSpace\McpBridge\Tests\Fixtures\Member;

class MemberPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Member $member): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, Member $member): bool
    {
        return true;
    }
}
