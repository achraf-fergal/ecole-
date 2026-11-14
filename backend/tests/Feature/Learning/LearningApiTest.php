<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Create a published course and an enrolled student.
     *
     * @return array<string, mixed>
     */
    private function createLearningContext(
        bool $withEnrollment = true
    ): array {
        $center = Center::factory()->create();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $membership = CenterUser::query()->create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $studentRole = Role::query()
            ->where('key', 'student')
            ->firstOrFail();

        $membership->roles()->attach($studentRole->id);

        $studentProfile = StudentProfile::factory()
            ->forMembership($membership)
            ->create();

        $course = Course::factory()
            ->forCenter($center)
            ->published()
            ->create();

        $cohort = Cohort::factory()
            ->forCourse($course)
            ->inProgress()
            ->create([
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addMonths(2),
            ]);

        $enrollment = null;

        if ($withEnrollment) {
            $enrollment = Enrollment::factory()
                ->forStudentProfile($studentProfile)
                ->forCohort($cohort)
                ->create([
                    'start_at' => now()->subDay(),
                    'end_at' => now()->addMonth(),
                ]);
        }

        $module = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create();

        $lesson = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create();

        return compact(
            'center',
            'user',
            'membership',
            'studentProfile',
            'course',
            'cohort',
            'enrollment',
            'module',
            'lesson'
        );
    }

    /**
     * Issue a JWT using the existing API guard.
     */
    private function tokenFor(User $user): string
    {
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = auth('api');

        return $guard->login($user);
    }

    public function test_enrolled_student_can_retrieve_learning_overview(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.overview', [
                'center' => $center->slug,
                'course' => $course->slug,
            ])
        );

        $response->assertOk();

        $response->assertJsonPath(
            'data.course.slug',
            $course->slug
        );

        $response->assertJsonPath(
            'data.progress_summary.total_lessons',
            1
        );

        $response->assertJsonPath(
            'data.modules.0.lessons.0.id',
            $lesson->id
        );
    }

    public function test_enrolled_student_can_retrieve_lesson_and_safe_exercise_data(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $exercise = Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Basic Grammar',
            'instructions' => 'Choose the correct answer.',
            'max_attempts' => 3,
            'passing_score' => 60,
            'show_correct_answers_after_submission' => false,
            'sort_order' => 1,
            'status' => Exercise::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $question = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => 'What is the correct greeting?',
            'explanation' => 'This is the private explanation.',
            'points' => 1,
            'sort_order' => 1,
        ]);

        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $question->id,
            'option_text' => 'Good morning',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $question->id,
            'option_text' => 'Good night',
            'is_correct' => false,
            'sort_order' => 2,
        ]);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );

        $response->assertOk();

        $response->assertJsonPath(
            'data.lesson.id',
            $lesson->id
        );

        $response->assertJsonPath(
            'data.exercises.0.title',
            'Basic Grammar'
        );

        $response->assertJsonPath(
            'data.exercises.0.questions.0.options.0.option_text',
            'Good morning'
        );

        $response->assertJsonMissingPath(
            'data.exercises.0.questions.0.explanation'
        );

        $response->assertJsonMissingPath(
            'data.exercises.0.questions.0.options.0.is_correct'
        );
    }

    public function test_student_can_update_their_own_lesson_progress(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'studentProfile' => $studentProfile,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $token = $this->tokenFor($user);

        $url = route(
            'api.centers.courses.learn.lessons.progress.update',
            [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ]
        );

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson($url, [
            'last_position_seconds' => 45,
        ]);

        $response->assertOk();

        $response->assertJsonPath(
            'data.status',
            LessonProgress::STATUS_IN_PROGRESS
        );

        $this->assertDatabaseHas('lesson_progresses', [
            'center_id' => $center->id,
            'student_profile_id' => $studentProfile->id,
            'lesson_id' => $lesson->id,
            'last_position_seconds' => 45,
        ]);

        // Explicit learner completion must not reset the saved video position.
        $completionResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson($url, [
            'completed' => true,
        ]);

        $completionResponse->assertOk();

        $completionResponse->assertJsonPath(
            'data.status',
            LessonProgress::STATUS_COMPLETED
        );

        $this->assertDatabaseHas('lesson_progresses', [
            'center_id' => $center->id,
            'student_profile_id' => $studentProfile->id,
            'lesson_id' => $lesson->id,
            'last_position_seconds' => 45,
            'status' => LessonProgress::STATUS_COMPLETED,
        ]);
    }

    public function test_student_without_enrollment_cannot_retrieve_overview(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
        ] = $this->createLearningContext(false);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.overview', [
                'center' => $center->slug,
                'course' => $course->slug,
            ])
        );

        $response->assertForbidden();
    }

    public function test_lesson_from_another_course_is_not_accessible(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
        ] = $this->createLearningContext();

        // Reuse language and level to avoid duplicate tenant-scoped records.
        $otherCourse = Course::factory()
            ->forCenter($center)
            ->withLanguage($course->language)
            ->withLevel($course->level)
            ->published()
            ->create();

        $otherModule = CourseModule::factory()
            ->forCourse($otherCourse)
            ->published()
            ->create();

        $otherLesson = Lesson::factory()
            ->forModule($otherModule)
            ->published()
            ->create();

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $otherLesson->id,
            ])
        );

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_retrieve_lesson(): void
    {
        [
            'center' => $center,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $response = $this->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );

        $response->assertUnauthorized();
    }
}
