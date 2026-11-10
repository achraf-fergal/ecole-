<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseTranslation>
 */
class CourseTranslationFactory extends Factory
{
    protected $model = CourseTranslation::class;

    public function definition(): array
    {
        return [
            'center_id' => null,
            'course_id' => null,

            'locale' => 'fr',

            'title' => fake()->sentence(3),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraphs(2, true),

            'prerequisites' => null,
            'target_audience' => null,

            'meta_title' => null,
            'meta_description' => null,
            'cover_image_alt' => null,

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

    public function published(): static
    {
        return $this->state([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
