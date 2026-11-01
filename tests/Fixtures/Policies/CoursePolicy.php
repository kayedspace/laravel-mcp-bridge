<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use KayedSpace\McpBridge\Tests\Fixtures\Course;

/**
 * Deliberately silent about delete: a policy that does not answer for an
 * operation is a policy that did not mean to allow it.
 */
class CoursePolicy
{
    public static bool $allows = true;

    public function viewAny(Authenticatable $user): bool
    {
        return self::$allows;
    }

    public function view(Authenticatable $user, Course $course): bool
    {
        return self::$allows;
    }

    public function create(Authenticatable $user): bool
    {
        return self::$allows;
    }

    public function update(Authenticatable $user, Course $course): bool
    {
        return self::$allows;
    }
}
