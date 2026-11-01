<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Course;
use Workbench\App\Policies\CoursePolicy;

/**
 * The application side of the demo: the abilities and the policy this
 * package's authority model asks for. An application adopting the package
 * writes exactly this much.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('manage-courses', fn (): bool => true);

        Gate::policy(Course::class, CoursePolicy::class);
    }
}
