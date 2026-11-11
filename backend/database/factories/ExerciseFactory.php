<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    protected $model = Exercise::class;

    public function definition(): array
    {
        return [
            // Assigned through forLesson().
            'center_id' => null,
            'lesson_id' => null,

            'title' => fake()->sentence(4),

            'instructions' => fake()->optional()->sentence(),

            // Three attempts by default.
            'max_attempts' => 3,

            // Default passing percentage.
            'passing_score' => 60.00,

            // Answer key is hidden by default.
            'show_correct_answers_after_submission' => false,

            'sort_order' => 0,

            'status' => Exercise::STATUS_DRAFT,

            'published_at' => null,
        ];
    }

    /**
     * Associate exercise with a lesson and its center.
     */
    public function forLesson(Lesson $lesson): static
    {
        return $this->state([
            'center_id' => $lesson->center_id,
            'lesson_id' => $lesson->id,
        ]);
    }

    /**
     * Create a published exercise.
     */
    public function published(): static
    {
        return $this->state([
            'status' => Exercise::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /**
     * Create an archived exercise.
     */
    public function archived(): static
    {
        return $this->state([
            'status' => Exercise::STATUS_ARCHIVED,
            'published_at' => null,
        ]);
    }

    /**
     * Create an exercise without an attempt limit.
     */
    public function unlimitedAttempts(): static
    {
        return $this->state([
            'max_attempts' => null,
        ]);
    }

    /**
     * Create an exercise with one attempt.
     */
    public function singleAttempt(): static
    {
        return $this->state([
            'max_attempts' => 1,
        ]);
    }
}
