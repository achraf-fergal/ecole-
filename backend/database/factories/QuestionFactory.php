<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'center_id' => null,
            'exercise_id' => null,

            'question_type' => Question::TYPE_SINGLE_CHOICE,

            'prompt' => fake()->sentence(),

            'explanation' => fake()->optional()->paragraph(),

            'points' => 1.00,

            'sort_order' => 0,
        ];
    }

    /**
     * Associate the question with an exercise and its center.
     */
    public function forExercise(Exercise $exercise): static
    {
        return $this->state([
            'center_id' => $exercise->center_id,
            'exercise_id' => $exercise->id,
        ]);
    }

    /**
     * Create a true/false question.
     */
    public function trueFalse(): static
    {
        return $this->state([
            'question_type' => Question::TYPE_TRUE_FALSE,
        ]);
    }
}
