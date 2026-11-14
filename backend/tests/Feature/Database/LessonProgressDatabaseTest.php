<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudentProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LessonProgressDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a valid student profile and lesson owned by one center.
     *
     * @return array{center: Center, profile: StudentProfile, lesson: Lesson}
     */
    private function createProgressContext(): array
    {
        $center = Center::factory()->create();

        $profile = StudentProfile::factory()->create([
            'center_id' => $center->id,
        ]);

        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create();

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create();

        return compact('center', 'profile', 'lesson');
    }

    public function test_lesson_progress_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('lesson_progresses', [
                'id',
                'center_id',
                'student_profile_id',
                'lesson_id',
                'status',
                'last_position_seconds',
                'started_at',
                'completed_at',
                'last_accessed_at',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_progress_has_center_student_and_lesson_relationships(): void
    {
        [
            'center' => $center,
            'profile' => $profile,
            'lesson' => $lesson,
        ] = $this->createProgressContext();

        $progress = LessonProgress::query()->create([
            'center_id' => $center->id,
            'student_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'status' => LessonProgress::STATUS_IN_PROGRESS,
            'last_position_seconds' => 95,
            'started_at' => now()->subMinutes(5),
            'last_accessed_at' => now(),
        ]);

        $this->assertTrue($progress->center->is($center));
        $this->assertTrue($progress->studentProfile->is($profile));
        $this->assertTrue($progress->lesson->is($lesson));
        $this->assertSame(95, $progress->last_position_seconds);
        $this->assertFalse($progress->isCompleted());
    }

    public function test_student_cannot_have_duplicate_progress_for_the_same_lesson(): void
    {
        [
            'center' => $center,
            'profile' => $profile,
            'lesson' => $lesson,
        ] = $this->createProgressContext();

        $attributes = [
            'center_id' => $center->id,
            'student_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'status' => LessonProgress::STATUS_IN_PROGRESS,
        ];

        LessonProgress::query()->create($attributes);

        $this->expectException(QueryException::class);

        LessonProgress::query()->create($attributes);
    }

    public function test_progress_cannot_combine_student_and_lesson_from_different_centers(): void
    {
        [
            'center' => $centerA,
            'profile' => $profileA,
        ] = $this->createProgressContext();

        [
            'lesson' => $lessonB,
        ] = $this->createProgressContext();

        $this->expectException(QueryException::class);

        LessonProgress::query()->create([
            'center_id' => $centerA->id,
            'student_profile_id' => $profileA->id,
            'lesson_id' => $lessonB->id,
            'status' => LessonProgress::STATUS_IN_PROGRESS,
        ]);
    }
}
