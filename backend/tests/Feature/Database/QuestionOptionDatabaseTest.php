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

class QuestionOptionDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a valid question with all parent relationships.
     */
    private function createQuestion(Center $center): Question
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

        $exercise = Exercise::factory()
            ->forLesson($lesson)
            ->create();

        return Question::factory()
            ->forExercise($exercise)
            ->create();
    }

    public function test_question_options_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('question_options', [
                'id',
                'center_id',
                'question_id',
                'option_text',
                'is_correct',
                'sort_order',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_option_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $question = $this->createQuestion($center);

        $option = QuestionOption::factory()
            ->forQuestion($question)
            ->create();

        $this->assertTrue($option->question->is($question));

        $this->assertTrue($option->center->is($center));

        $this->assertTrue(
            $question->options()
                ->whereKey($option->id)
                ->exists()
        );

        $this->assertTrue(
            $center->questionOptions()
                ->whereKey($option->id)
                ->exists()
        );
    }

    public function test_question_can_have_multiple_ordered_options(): void
    {
        $center = Center::factory()->create();

        $question = $this->createQuestion($center);

        QuestionOption::factory()
            ->forQuestion($question)
            ->create([
                'option_text' => 'Third option',
                'sort_order' => 3,
            ]);

        QuestionOption::factory()
            ->forQuestion($question)
            ->create([
                'option_text' => 'First option',
                'sort_order' => 1,
            ]);

        QuestionOption::factory()
            ->forQuestion($question)
            ->create([
                'option_text' => 'Second option',
                'sort_order' => 2,
            ]);

        $options = $question->fresh()
            ->options
            ->pluck('option_text')
            ->all();

        $this->assertSame([
            'First option',
            'Second option',
            'Third option',
        ], $options);
    }

    public function test_option_cannot_reference_question_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $questionA = $this->createQuestion($centerA);

        $this->expectException(QueryException::class);

        QuestionOption::factory()
            ->forQuestion($questionA)
            ->create([
                'center_id' => $centerB->id,
            ]);
    }

    public function test_correct_answer_flag_is_hidden_from_default_serialization(): void
    {
        $center = Center::factory()->create();

        $question = $this->createQuestion($center);

        QuestionOption::factory()
            ->forQuestion($question)
            ->correct()
            ->create();

        $question->load('options');

        $serialized = $question->toArray();

        $this->assertArrayNotHasKey(
            'explanation',
            $serialized
        );

        $this->assertArrayNotHasKey(
            'is_correct',
            $serialized['options'][0]
        );
    }

    public function test_correct_option_state_is_stored(): void
    {
        $center = Center::factory()->create();

        $question = $this->createQuestion($center);

        $correct = QuestionOption::factory()
            ->forQuestion($question)
            ->correct()
            ->create();

        $incorrect = QuestionOption::factory()
            ->forQuestion($question)
            ->create();

        $this->assertTrue($correct->fresh()->is_correct);

        $this->assertFalse($incorrect->fresh()->is_correct);

        $this->assertTrue(
            $question->hasValidOptionConfiguration()
        );

        $this->assertDatabaseHas('question_options', [
            'id' => $correct->id,
            'question_id' => $question->id,
            'is_correct' => true,
        ]);
    }
}
