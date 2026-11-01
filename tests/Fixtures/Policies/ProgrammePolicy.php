<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use KayedSpace\McpBridge\Tests\Fixtures\Programme;

/** Reads only, so a programme has list and get tools and nothing that writes. */
class ProgrammePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Programme $programme): bool
    {
        return true;
    }
}
