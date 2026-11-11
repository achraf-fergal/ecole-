<?php

namespace Database\Factories;

use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    public function definition(): array
    {
        return [
            'center_id' => null,
            'course_module_id' => null,

            'title' => fake()->sentence(4),

            'description' => fake()->sentence(),

            'content_type' => Lesson::TYPE_TEXT,

            'body' => fake()->paragraphs(3, true),

            'content_locale' => 'en',

            'duration_minutes' => fake()->numberBetween(5, 90),

            'sort_order' => 0,

            'status' => Lesson::STATUS_DRAFT,

            'published_at' => null,
        ];
    }

    public function forModule(CourseModule $module): static
    {
        return $this->state([
            'center_id' => $module->center_id,
            'course_module_id' => $module->id,
        ]);
    }

    public function published(): static
    {
        return $this->state([
            'status' => Lesson::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state([
            'status' => Lesson::STATUS_ARCHIVED,
        ]);
    }
}