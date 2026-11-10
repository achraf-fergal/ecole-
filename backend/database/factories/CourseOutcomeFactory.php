<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseOutcome>
 */
class CourseOutcomeFactory extends Factory
{
    protected $model = CourseOutcome::class;

    public function definition(): array
    {
        return [
            'center_id' => null,
            'course_id' => null,

            'locale' => 'en',

            'content' => fake()->sentence(),

            'sort_order' => 0,
            'is_highlight' => false,

            'status' => 'draft',
            'published_at' => null,
        ];
    }

    public function forCourse(Course $course): static
    {
        return $this->state([
            'center_id' => $course->center_id,
            'course_id' => $course->id,
        ]);
    }

    public function inLocale(string $locale): static
    {
        return $this->state([
            'locale' => $locale,
        ]);
    }

    public function highlight(): static
    {
        return $this->state([
            'is_highlight' => true,
        ]);
    }

    public function published(): static
    {
        return $this->state([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
