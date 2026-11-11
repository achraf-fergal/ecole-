<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseModule>
 */
class CourseModuleFactory extends Factory
{
    protected $model = CourseModule::class;

    public function definition(): array
    {
        return [
            // A course must be assigned through forCourse().
            'center_id' => null,
            'course_id' => null,

            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),

            'sort_order' => 0,

            'status' => CourseModule::STATUS_DRAFT,
            'published_at' => null,
        ];
    }

    /**
     * Associate the module with a course and its center.
     */
    public function forCourse(Course $course): static
    {
        return $this->state([
            'center_id' => $course->center_id,
            'course_id' => $course->id,
        ]);
    }

    /**
     * Create a published module.
     */
    public function published(): static
    {
        return $this->state([
            'status' => CourseModule::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /**
     * Create an archived module.
     */
    public function archived(): static
    {
        return $this->state([
            'status' => CourseModule::STATUS_ARCHIVED,
            'published_at' => null,
        ]);
    }
}
