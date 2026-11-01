<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Course;
use Workbench\App\Models\CourseStatus;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'status' => fake()->randomElement(CourseStatus::cases())->value,
            'price' => fake()->randomFloat(2, 0, 500),
        ];
    }
}
