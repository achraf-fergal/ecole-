<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\Lesson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExerciseDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a lesson with valid tenant and course relationships.
     */
    private function createLesson(Center $center): Lesson
    {
        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create();

        return Lesson::factory()
            ->forModule($module)
            ->create();
    }

    public function test_exercises_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('exercises', [
                'id',
                'center_id',
                'lesson_id',
                'title',
                'instructions',
                'max_attempts',
                'passing_score',
                'show_correct_answers_after_submission',
                'sort_order',
                'status',
                'published_at',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_exercise_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        $exercise = Exercise::factory()
            ->forLesson($lesson)
            ->create();

        $this->assertTrue(
            $exercise->lesson->is($lesson)
        );

        $this->assertTrue(
            $exercise->center->is($center)
        );

        $this->assertTrue(
            $lesson->exercises()
                ->whereKey($exercise->id)
                ->exists()
        );

        $this->assertTrue(
            $center->exercises()
                ->whereKey($exercise->id)
                ->exists()
        );
    }

    public function test_lesson_can_have_multiple_ordered_exercises(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        Exercise::factory()
            ->forLesson($lesson)
            ->create([
                'title' => 'Third Exercise',
                'sort_order' => 3,
            ]);

        Exercise::factory()
            ->forLesson($lesson)
            ->create([
                'title' => 'First Exercise',
                'sort_order' => 1,
            ]);

        Exercise::factory()
            ->forLesson($lesson)
            ->create([
                'title' => 'Second Exercise',
                'sort_order' => 2,
            ]);

        $titles = $lesson->fresh()
            ->exercises
            ->pluck('title')
            ->all();

        $this->assertSame([
            'First Exercise',
            'Second Exercise',
            'Third Exercise',
        ], $titles);
    }

    public function test_exercise_cannot_reference_lesson_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $lessonA = $this->createLesson($centerA);

        $this->expectException(QueryException::class);

        Exercise::factory()
            ->forLesson($lessonA)
            ->create([
                'center_id' => $centerB->id,
            ]);
    }

    public function test_published_scope_excludes_draft_and_future_exercises(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        $published = Exercise::factory()
            ->forLesson($lesson)
            ->published()
            ->create();

        $draft = Exercise::factory()
            ->forLesson($lesson)
            ->create();

        $future = Exercise::factory()
            ->forLesson($lesson)
            ->published()
            ->create([
                'published_at' => now()->addDay(),
            ]);

        $publishedIds = Exercise::query()
            ->forCenter($center->id)
            ->published()
            ->pluck('id')
            ->all();

        $this->assertContains($published->id, $publishedIds);

        $this->assertNotContains($draft->id, $publishedIds);

        $this->assertNotContains($future->id, $publishedIds);

        $this->assertTrue($published->fresh()->isPublished());
    }

    public function test_exercise_attempt_and_scoring_settings_are_stored(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        $unlimited = Exercise::factory()
            ->forLesson($lesson)
            ->unlimitedAttempts()
            ->create([
                'passing_score' => 75.00,
                'show_correct_answers_after_submission' => true,
            ]);

        $this->assertTrue(
            $unlimited->hasUnlimitedAttempts()
        );

        $this->assertSame(75.0, (float) $unlimited->passing_score);

        $this->assertTrue(
            $unlimited->show_correct_answers_after_submission
        );

        $singleAttempt = Exercise::factory()
            ->forLesson($lesson)
            ->singleAttempt()
            ->create();

        $this->assertSame(1, $singleAttempt->max_attempts);

        $this->assertFalse(
            $singleAttempt->hasUnlimitedAttempts()
        );
    }

    public function test_archiving_exercise_preserves_its_record(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        $exercise = Exercise::factory()
            ->forLesson($lesson)
            ->published()
            ->create();

        $exercise->update([
            'status' => Exercise::STATUS_ARCHIVED,
        ]);

        $this->assertTrue(
            $exercise->fresh()->isArchived()
        );

        $this->assertFalse(
            $exercise->fresh()->isPublished()
        );

        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'status' => Exercise::STATUS_ARCHIVED,
        ]);
    }
}
