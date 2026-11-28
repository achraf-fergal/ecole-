<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\ExerciseAttemptAnswer;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Level;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class ExercisePreviewAndReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard->login($user);
    }

    private function createAdminContext(): array
    {
        $center = Center::factory()->create();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $membership = CenterUser::query()->create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $role = Role::query()->where('key', 'center_admin')->firstOrFail();
        $membership->roles()->attach($role->id);

        $language = Language::factory()->for($center)->create();
        $level = Level::factory()->for($center)->create();

        $course = Course::factory()->forCenter($center)->published()->create([
            'language_id' => $language->id,
            'level_id' => $level->id,
        ]);

        $module = CourseModule::factory()->forCourse($course)->published()->create();

        $lesson = Lesson::factory()->forModule($module)->create([
            'center_id' => $center->id,
            'status' => Lesson::STATUS_DRAFT,
        ]);

        return [$center, $user, $course, $module, $lesson];
    }

    public function test_rejects_after_final_attempt_with_unlimited_attempts_on_create_update_and_publish(): void
    {
        [$center, $admin, $course, , $lesson] = $this->createAdminContext();
        $token = $this->tokenFor($admin);

        // 1. Create with max_attempts = null and release_timing = after_final_attempt -> 422
        $createResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises", [
                'title' => 'Invalid Release Policy',
                'max_attempts' => null,
                'result_settings' => [
                    'release_timing' => Exercise::RELEASE_TIMING_AFTER_FINAL_ATTEMPT,
                ],
            ]);

        $createResponse->assertStatus(422)
            ->assertJsonValidationErrors(['result_settings.release_timing']);

        // 2. Create valid exercise (max_attempts = null, release_timing = immediately) -> 201
        $validCreate = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises", [
                'title' => 'Valid Unlimited Exercise',
                'max_attempts' => null,
                'result_settings' => [
                    'release_timing' => Exercise::RELEASE_TIMING_IMMEDIATELY,
                ],
                'questions' => [
                    [
                        'question_type' => Question::TYPE_TRUE_FALSE,
                        'prompt' => 'Sky is blue',
                        'points' => 1,
                        'options' => [
                            ['option_text' => 'True', 'is_correct' => true],
                            ['option_text' => 'False', 'is_correct' => false],
                        ],
                    ],
                ],
            ]);

        $validCreate->assertStatus(201);
        $exerciseId = $validCreate->json('data.id');

        // 3. Update to after_final_attempt while max_attempts = null -> 422
        $invalidUpdate = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$exerciseId}", [
                'result_settings' => [
                    'release_timing' => Exercise::RELEASE_TIMING_AFTER_FINAL_ATTEMPT,
                ],
            ]);

        $invalidUpdate->assertStatus(422)
            ->assertJsonValidationErrors(['result_settings.release_timing']);

        // 4. Legacy incompatible record in DB cannot be published without fixing it
        $legacyExercise = Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Legacy Incompatible',
            'max_attempts' => null,
            'passing_score' => 70,
            'result_settings' => Exercise::normalizeResultSettings([
                'release_timing' => Exercise::RELEASE_TIMING_AFTER_FINAL_ATTEMPT,
            ]),
            'status' => Exercise::STATUS_DRAFT,
        ]);

        $q = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $legacyExercise->id,
            'question_type' => Question::TYPE_TRUE_FALSE,
            'prompt' => 'Legacy Q',
            'points' => 1,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q->id,
            'option_text' => 'True',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q->id,
            'option_text' => 'False',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $publishLegacy = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$legacyExercise->id}/publish");

        $publishLegacy->assertStatus(422)
            ->assertJsonValidationErrors(['result_settings.release_timing']);

        // 5. Show endpoint flags incompatible configuration and affected attempts count
        $showLegacy = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$legacyExercise->id}");

        $showLegacy->assertOk()
            ->assertJsonPath('data.has_incompatible_release_configuration', true)
            ->assertJsonPath('data.incompatible_release_affected_attempts', 0);
    }

    public function test_simulate_grade_grades_all_question_types_statelessly_with_zero_persistence(): void
    {
        [$center, $admin, $course, , $lesson] = $this->createAdminContext();
        $token = $this->tokenFor($admin);

        $exercise = Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Simulation Test',
            'max_attempts' => 2,
            'passing_score' => 50,
            'result_settings' => Exercise::normalizeResultSettings([
                'release_timing' => Exercise::RELEASE_TIMING_IMMEDIATELY,
                'show_score' => true,
                'show_percentage' => true,
                'show_pass_fail' => true,
                'show_question_results' => true,
                'show_student_answers' => true,
                'show_correct_answers' => false,
                'show_explanations' => false,
                'show_points_per_question' => true,
            ]),
            'status' => Exercise::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $q1 = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => 'Pick A',
            'explanation' => 'Secret explanation',
            'points' => 2,
            'sort_order' => 0,
        ]);
        $q1Correct = QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q1->id,
            'option_text' => 'A',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q1->id,
            'option_text' => 'B',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $q2 = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_SHORT_ANSWER,
            'prompt' => 'Capital of France',
            'answer_config' => [
                'accepted_answers' => ['Paris'],
                'normalization' => ['case_sensitive' => false, 'trim_whitespace' => true],
            ],
            'points' => 2,
            'sort_order' => 1,
        ]);

        $q3 = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_FILL_IN_THE_BLANKS,
            'prompt' => 'I [[b1]] here.',
            'answer_config' => [
                'blanks' => [
                    [
                        'id' => 'b1',
                        'points' => 2,
                        'accepted_answers' => ['live'],
                        'normalization' => ['case_sensitive' => false, 'trim_whitespace' => true],
                    ],
                ],
            ],
            'points' => 2,
            'sort_order' => 2,
        ]);

        $q4 = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_TRUE_FALSE,
            'prompt' => 'Earth is round',
            'points' => 1,
            'sort_order' => 3,
        ]);
        $q4True = QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q4->id,
            'option_text' => 'True',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q4->id,
            'option_text' => 'False',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $q5 = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_MULTIPLE_SELECT,
            'prompt' => 'Pick vowels',
            'points' => 2,
            'sort_order' => 4,
        ]);
        $q5A = QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q5->id,
            'option_text' => 'A',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q5->id,
            'option_text' => 'B',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $attemptsBefore = ExerciseAttempt::query()->count();
        $answersBefore = ExerciseAttemptAnswer::query()->count();
        $progressBefore = LessonProgress::query()->count();

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$exercise->id}/simulate-grade", [
                'answers' => [
                    ['question_id' => $q1->id, 'selected_option_id' => $q1Correct->id],
                    ['question_id' => $q2->id, 'text_answer' => 'paris'],
                    ['question_id' => $q3->id, 'blank_answers' => ['b1' => 'wrong']],
                    ['question_id' => $q4->id, 'selected_option_id' => $q4True->id],
                    ['question_id' => $q5->id, 'selected_option_ids' => [$q5A->id]],
                ],
            ]);

        $res->assertOk()
            ->assertJsonPath('data.score', 7)
            ->assertJsonPath('data.max_score', 9)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.correct_count', 4)
            ->assertJsonPath('data.incorrect_count', 1)
            ->assertJsonPath('data.result_visibility.show_correct_answers', false)
            ->assertJsonMissingPath('data.questions.0.correct_option_id')
            ->assertJsonMissingPath('data.questions.0.explanation');

        // Zero DB persistence on success
        $this->assertSame($attemptsBefore, ExerciseAttempt::query()->count());
        $this->assertSame($answersBefore, ExerciseAttemptAnswer::query()->count());
        $this->assertSame($progressBefore, LessonProgress::query()->count());

        // Failed validation request also produces zero persistence
        $badRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$exercise->id}/simulate-grade", [
                'invalid' => true,
            ]);
        $badRes->assertStatus(422);

        $this->assertSame($attemptsBefore, ExerciseAttempt::query()->count());
        $this->assertSame($answersBefore, ExerciseAttemptAnswer::query()->count());
        $this->assertSame($progressBefore, LessonProgress::query()->count());
    }

    public function test_simulate_grade_rejects_student_and_cross_tenant_admin(): void
    {
        [$center, , $course, , $lesson] = $this->createAdminContext();
        $exercise = Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Auth Check',
            'status' => Exercise::STATUS_DRAFT,
        ]);

        // Student in same center -> 403
        $student = User::factory()->create(['email_verified_at' => now()]);
        $studentMembership = CenterUser::query()->create([
            'center_id' => $center->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        $studentRole = Role::query()->where('key', 'student')->firstOrFail();
        $studentMembership->roles()->attach($studentRole->id);
        StudentProfile::query()->create([
            'center_id' => $center->id,
            'user_id' => $student->id,
            'student_code' => 'STU-001',
            'status' => 'active',
        ]);

        $studentRes = $this->withHeader('Authorization', "Bearer {$this->tokenFor($student)}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$exercise->id}/simulate-grade", [
                'answers' => [],
            ]);
        $studentRes->assertStatus(403);

        // Admin from another center -> 403
        [$otherCenter, $otherAdmin] = $this->createAdminContext();
        $crossRes = $this->withHeader('Authorization', "Bearer {$this->tokenFor($otherAdmin)}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$exercise->id}/simulate-grade", [
                'answers' => [],
            ]);
        $crossRes->assertStatus(403);
    }

    public function test_question_option_configuration_errors_avoids_n_plus_1_when_options_loaded(): void
    {
        [$center, , , , $lesson] = $this->createAdminContext();
        $exercise = Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'N+1 Check',
            'status' => Exercise::STATUS_DRAFT,
        ]);

        $q = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_TRUE_FALSE,
            'prompt' => 'Q',
            'points' => 1,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q->id,
            'option_text' => 'True',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q->id,
            'option_text' => 'False',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $loaded = Question::query()->with('options')->findOrFail($q->id);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $errors = $loaded->optionConfigurationErrors();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $errors);
        $this->assertCount(0, $queries);
    }

    public function test_simulate_grade_supports_first_and_final_attempt_scenarios_for_after_final_attempt(): void
    {
        [$center, $admin, $course, , $lesson] = $this->createAdminContext();
        $token = $this->tokenFor($admin);

        $exercise = Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Scenario Policy Test',
            'max_attempts' => 3,
            'passing_score' => 50,
            'result_settings' => Exercise::normalizeResultSettings([
                'release_timing' => Exercise::RELEASE_TIMING_AFTER_FINAL_ATTEMPT,
                'show_score' => true,
                'show_percentage' => true,
                'show_pass_fail' => true,
                'show_question_results' => true,
                'show_student_answers' => true,
                'show_correct_answers' => true,
                'show_explanations' => true,
                'show_points_per_question' => true,
            ]),
            'status' => Exercise::STATUS_DRAFT,
        ]);

        $q = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_TRUE_FALSE,
            'prompt' => 'Water is wet',
            'explanation' => 'Yes.',
            'points' => 1,
            'sort_order' => 0,
        ]);
        $optTrue = QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q->id,
            'option_text' => 'True',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q->id,
            'option_text' => 'False',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $firstRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$exercise->id}/simulate-grade", [
                'attempt_scenario' => 'first',
                'answers' => [
                    ['question_id' => $q->id, 'selected_option_id' => $optTrue->id],
                ],
            ]);

        $firstRes->assertOk()
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonPath('data.attempt_scenario', 'first')
            ->assertJsonPath('data.result_visibility.results_released', false)
            ->assertJsonPath('data.score', null)
            ->assertJsonMissingPath('data.questions.0.correct_option_id');

        $finalRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/centers/{$center->slug}/admin/courses/{$course->slug}/lessons/{$lesson->id}/exercises/{$exercise->id}/simulate-grade", [
                'attempt_scenario' => 'final',
                'answers' => [
                    ['question_id' => $q->id, 'selected_option_id' => $optTrue->id],
                ],
            ]);

        $finalRes->assertOk()
            ->assertJsonPath('data.attempt_number', 3)
            ->assertJsonPath('data.attempt_scenario', 'final')
            ->assertJsonPath('data.result_visibility.results_released', true)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.questions.0.correct_option_id', $optTrue->id);
    }

    public function test_multiple_select_accepts_two_options_and_one_correct_option(): void
    {
        [$center, , , , $lesson] = $this->createAdminContext();
        $exercise = Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Multi Select Validation',
            'status' => Exercise::STATUS_DRAFT,
        ]);

        $q = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_MULTIPLE_SELECT,
            'prompt' => 'Select valid',
            'points' => 1,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q->id,
            'option_text' => 'Option 1',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $q->id,
            'option_text' => 'Option 2',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $loaded = Question::query()->with('options')->findOrFail($q->id);
        $this->assertSame([], $loaded->optionConfigurationErrors());
    }
}
