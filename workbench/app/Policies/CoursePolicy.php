<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Workbench\App\Models\Course;
use Workbench\App\Models\User;

/**
 * Silent about delete on purpose: a policy that does not answer for an
 * operation did not mean to allow it, so no delete_course model tool is
 * generated, and `mcp-bridge:tools --refused` says why.
 */
class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Course $course): bool
    {
        return true;
    }
}
