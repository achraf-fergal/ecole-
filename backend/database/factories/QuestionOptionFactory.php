<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    public function definition(): array
    {
        return [
            'center_id' => null,
            'question_id' => null,

            'option_text' => fake()->sentence(),

            'is_correct' => false,

            'sort_order' => 0,
        ];
    }

    /**
     * Associate the option with its question and tenant.
     */
    public function forQuestion(Question $question): static
    {
        return $this->state([
            'center_id' => $question->center_id,
            'question_id' => $question->id,
        ]);
    }

    /**
     * Mark the option as correct.
     */
    public function correct(): static
    {
        return $this->state([
            'is_correct' => true,
        ]);
    }
}
