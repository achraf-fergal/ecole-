<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuestionDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a valid exercise with all parent relationships.
     */
    private function createExercise(Center $center): Exercise
    {
        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create();

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create();

        return Exercise::factory()
            ->forLesson($lesson)
            ->create();
    }

    public function test_questions_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('questions', [
                'id',
                'center_id',
                'exercise_id',
                'question_type',
                'prompt',
                'explanation',
                'points',
                'sort_order',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_question_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $exercise = $this->createExercise($center);

        $question = Question::factory()
            ->forExercise($exercise)
            ->create();

        $this->assertTrue(
            $question->exercise->is($exercise)
        );

        $this->assertTrue(
            $question->center->is($center)
        );

        $this->assertTrue(
            $exercise->questions()
                ->whereKey($question->id)
                ->exists()
        );

        $this->assertTrue(
            $center->questions()
                ->whereKey($question->id)
                ->exists()
        );
    }

    public function test_exercise_can_have_multiple_ordered_questions(): void
    {
        $center = Center::factory()->create();

        $exercise = $this->createExercise($center);

        Question::factory()
            ->forExercise($exercise)
            ->create([
                'prompt' => 'Third question',
                'sort_order' => 3,
            ]);

        Question::factory()
            ->forExercise($exercise)
            ->create([
                'prompt' => 'First question',
                'sort_order' => 1,
            ]);

        Question::factory()
            ->forExercise($exercise)
            ->create([
                'prompt' => 'Second question',
                'sort_order' => 2,
            ]);

        $prompts = $exercise->fresh()
            ->questions
            ->pluck('prompt')
            ->all();

        $this->assertSame([
            'First question',
            'Second question',
            'Third question',
        ], $prompts);
    }

    public function test_question_cannot_reference_exercise_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $exerciseA = $this->createExercise($centerA);

        $this->expectException(QueryException::class);

        Question::factory()
            ->forExercise($exerciseA)
            ->create([
                'center_id' => $centerB->id,
            ]);
    }

    public function test_single_choice_requires_at_least_two_options_and_one_correct_answer(): void
    {
        $center = Center::factory()->create();

        $exercise = $this->createExercise($center);

        $question = Question::factory()
            ->forExercise($exercise)
            ->create([
                'question_type' => Question::TYPE_SINGLE_CHOICE,
            ]);

        // One option is insufficient.
        QuestionOption::factory()
            ->forQuestion($question)
            ->correct()
            ->create();

        $this->assertFalse(
            $question->hasValidOptionConfiguration()
        );

        // Two options with one correct answer are valid.
        QuestionOption::factory()
            ->forQuestion($question)
            ->create();

        $this->assertTrue(
            $question->hasValidOptionConfiguration()
        );

        // More than one correct answer is invalid.
        QuestionOption::factory()
            ->forQuestion($question)
            ->correct()
            ->create();

        $this->assertFalse(
            $question->hasValidOptionConfiguration()
        );
    }

    public function test_true_false_requires_exactly_two_options_and_one_correct_answer(): void
    {
        $center = Center::factory()->create();

        $exercise = $this->createExercise($center);

        $question = Question::factory()
            ->forExercise($exercise)
            ->trueFalse()
            ->create();

        QuestionOption::factory()
            ->forQuestion($question)
            ->correct()
            ->create([
                'option_text' => 'True',
            ]);

        QuestionOption::factory()
            ->forQuestion($question)
            ->create([
                'option_text' => 'False',
            ]);

        $this->assertTrue(
            $question->hasValidOptionConfiguration()
        );

        // A third option violates true/false rules.
        QuestionOption::factory()
            ->forQuestion($question)
            ->create([
                'option_text' => 'Maybe',
            ]);

        $this->assertFalse(
            $question->hasValidOptionConfiguration()
        );
    }

    public function test_question_explanation_is_hidden_from_default_serialization(): void
    {
        $center = Center::factory()->create();

        $exercise = $this->createExercise($center);

        $question = Question::factory()
            ->forExercise($exercise)
            ->create([
                'explanation' => 'The correct answer is explained here.',
            ]);

        $serialized = $question->toArray();

        $this->assertArrayHasKey('prompt', $serialized);

        $this->assertArrayNotHasKey('explanation', $serialized);

        $this->assertSame(
            'The correct answer is explained here.',
            $question->explanation
        );
    }
}
