<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\Level;
use App\Models\Question;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\LessonRevisionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class AssessmentEngineTest extends TestCase
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
        $lesson = Lesson::factory()->forModule($module)->published()->create();

        return compact('center', 'user', 'course', 'module', 'lesson');
    }

    private function createStudentContext(Center $center, Course $course, Lesson $lesson): array
    {
        $studentUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $membership = CenterUser::query()->create([
            'center_id' => $center->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);

        $studentRole = Role::query()->where('key', 'student')->firstOrFail();
        $membership->roles()->attach($studentRole->id);

        $studentProfile = StudentProfile::factory()->forMembership($membership)->create();

        $cohort = Cohort::factory()->forCourse($course)->inProgress()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addMonths(2),
        ]);

        $enrollment = Enrollment::factory()
            ->forStudentProfile($studentProfile)
            ->forCohort($cohort)
            ->create([
                'start_at' => now()->subDay(),
                'end_at' => now()->addMonth(),
            ]);

        return [
            'studentUser' => $studentUser,
            'studentProfile' => $studentProfile,
            'enrollment' => $enrollment,
        ];
    }

    public function test_center_admin_can_create_and_update_draft_exercise(): void
    {
        $ctx = $this->createAdminContext();
        $token = $this->tokenFor($ctx['user']);

        $res = $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Vocabulary Quiz 1',
                'instructions' => 'Choose the best translation.',
                'passing_score' => 80.00,
                'max_attempts' => 2,
                'show_correct_answers_after_submission' => true,
                'questions' => [
                    [
                        'prompt' => 'What is the Spanish word for "apple"?',
                        'question_type' => 'single_choice',
                        'explanation' => 'Manzana is apple.',
                        'points' => 2.00,
                        'options' => [
                            ['option_text' => 'Manzana', 'is_correct' => true],
                            ['option_text' => 'Perro', 'is_correct' => false],
                            ['option_text' => 'Gato', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );

        $res->assertCreated();
        $res->assertJsonPath('data.title', 'Vocabulary Quiz 1');
        $res->assertJsonPath('data.status', 'draft');
        $res->assertJsonCount(1, 'data.questions');
        $res->assertJsonCount(3, 'data.questions.0.options');

        $exerciseId = $res->json('data.id');

        // Center Admin can update it
        $updateRes = $this->withHeader('Authorization', "Bearer {$token}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'title' => 'Updated Vocabulary Quiz 1',
            ]
        );

        $updateRes->assertOk();
        $updateRes->assertJsonPath('data.title', 'Updated Vocabulary Quiz 1');
    }

    public function test_exercise_cannot_be_published_with_invalid_question_configuration(): void
    {
        $ctx = $this->createAdminContext();
        $token = $this->tokenFor($ctx['user']);

        // Create an exercise without any correct option
        $createRes = $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Invalid Quiz',
                'questions' => [
                    [
                        'prompt' => 'No correct option question',
                        'question_type' => 'single_choice',
                        'options' => [
                            ['option_text' => 'Option A', 'is_correct' => false],
                            ['option_text' => 'Option B', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $createRes->assertCreated();
        $exerciseId = $createRes->json('data.id');

        // Publishing must fail
        $pubRes = $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        );

        $pubRes->assertUnprocessable();
        $pubRes->assertJsonValidationErrors(['questions']);
    }

    public function test_complete_assessment_workflow_end_to_end(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        // 1. Admin creates exercise with 2 questions
        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Grammar Assessment',
                'instructions' => 'Answer all questions carefully.',
                'passing_score' => 50.00,
                'max_attempts' => 2,
                'show_correct_answers_after_submission' => true,
                'questions' => [
                    [
                        'prompt' => 'Question 1: Select the verb.',
                        'question_type' => 'single_choice',
                        'explanation' => '"Run" is an action verb.',
                        'points' => 10.00,
                        'options' => [
                            ['option_text' => 'House', 'is_correct' => false],
                            ['option_text' => 'Run', 'is_correct' => true],
                            ['option_text' => 'Blue', 'is_correct' => false],
                        ],
                    ],
                    [
                        'prompt' => 'Question 2: "Cat" is an animal.',
                        'question_type' => 'true_false',
                        'explanation' => 'Cats are felines.',
                        'points' => 10.00,
                        'options' => [
                            ['option_text' => 'True', 'is_correct' => true],
                            ['option_text' => 'False', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $createRes->assertCreated();
        $exerciseId = $createRes->json('data.id');
        $q1Id = $createRes->json('data.questions.0.id');
        $q1CorrectOptId = collect($createRes->json('data.questions.0.options'))->firstWhere('is_correct', true)['id'];
        $q1WrongOptId = collect($createRes->json('data.questions.0.options'))->firstWhere('is_correct', false)['id'];

        $q2Id = $createRes->json('data.questions.1.id');
        $q2CorrectOptId = collect($createRes->json('data.questions.1.options'))->firstWhere('is_correct', true)['id'];

        // 2. Admin publishes exercise
        $pubRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        );
        $pubRes->assertOk();
        $pubRes->assertJsonPath('data.status', 'published');

        // 3. Admin creates a lesson draft, inserts the exercise block, and publishes the revision
        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        // 4. Student enrolls and accesses the lesson
        $studentCtx = $this->createStudentContext($ctx['center'], $ctx['course'], $ctx['lesson']);
        $studentToken = $this->tokenFor($studentCtx['studentUser']);

        // Student checks current attempt status
        $currentRes = $this->withHeader('Authorization', "Bearer {$studentToken}")->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.current', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        );
        $currentRes->assertOk();
        $currentRes->assertJsonPath('data.exercise.can_start_new_attempt', true);
        $currentRes->assertJsonPath('data.exercise.has_active_attempt', false);
        $currentRes->assertJsonPath('data.exercise.attempts_remaining', 2);

        // 5. Student starts attempt
        $startRes = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        );
        $startRes->assertCreated();
        $startRes->assertJsonPath('data.status', 'in_progress');
        $startRes->assertJsonPath('data.attempt_number', 1);
        $startRes->assertJsonCount(2, 'data.questions');

        // Sensitive fields must NOT be exposed while in progress
        $rawStart = $startRes->getContent();
        $this->assertStringNotContainsString('"is_correct":true', $rawStart);
        $this->assertStringNotContainsString('"is_correct":false', $rawStart);
        $this->assertStringNotContainsString('Run is an action verb', $rawStart);

        // 6. Student saves answers (in-progress)
        $saveRes = $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'answers' => [
                    ['question_id' => $q1Id, 'selected_option_id' => $q1CorrectOptId],
                ],
            ]
        );
        $saveRes->assertOk();

        // 7. Student submits attempt with final selections (Q1 correct, Q2 correct -> 100%)
        $submitRes = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'answers' => [
                    ['question_id' => $q1Id, 'selected_option_id' => $q1CorrectOptId],
                    ['question_id' => $q2Id, 'selected_option_id' => $q2CorrectOptId],
                ],
            ]
        );
        $submitRes->assertOk();
        $submitRes->assertJsonPath('data.status', 'graded');
        $submitRes->assertJsonPath('data.score', 20);
        $submitRes->assertJsonPath('data.max_score', 20);
        $submitRes->assertJsonPath('data.percentage', 100);
        $submitRes->assertJsonPath('data.passed', true);

        // Since show_correct_answers_after_submission was true, answers are released
        $submitRes->assertJsonPath('data.show_correct_answers', true);
        $this->assertStringContainsString('action verb', $submitRes->getContent());

        // 8. Attempt cannot be modified after submission
        $resubmitRes = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        );
        $resubmitRes->assertNotFound(); // No active attempt in progress to submit
    }

    public function test_release_policy_hides_answers_when_disabled(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        // Create exercise with show_correct_answers_after_submission = false
        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Secret Answers Quiz',
                'passing_score' => 60.00,
                'max_attempts' => 1,
                'show_correct_answers_after_submission' => false,
                'questions' => [
                    [
                        'prompt' => 'Confidential prompt',
                        'question_type' => 'true_false',
                        'explanation' => 'Super Secret Explanation',
                        'points' => 10.00,
                        'options' => [
                            ['option_text' => 'True', 'is_correct' => true],
                            ['option_text' => 'False', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');
        $qId = $createRes->json('data.questions.0.id');
        $correctOptId = collect($createRes->json('data.questions.0.options'))->firstWhere('is_correct', true)['id'];

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        $studentCtx = $this->createStudentContext($ctx['center'], $ctx['course'], $ctx['lesson']);
        $studentToken = $this->tokenFor($studentCtx['studentUser']);

        // Start
        $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertCreated();

        // Submit
        $submitRes = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'answers' => [
                    ['question_id' => $qId, 'selected_option_id' => $correctOptId],
                ],
            ]
        );

        $submitRes->assertOk();
        $submitRes->assertJsonPath('data.show_correct_answers', false);
        $submitRes->assertJsonPath('data.score', 10);
        $submitRes->assertJsonPath('data.passed', true);

        // Explanation and correct option must NOT be in the response
        $raw = $submitRes->getContent();
        $this->assertStringNotContainsString('Super Secret Explanation', $raw);
        $this->assertStringNotContainsString('correct_option_id', $raw);
    }

    public function test_cross_tenant_attempt_is_rejected(): void
    {
        $ctxA = $this->createAdminContext();
        $ctxB = $this->createAdminContext();

        $adminTokenA = $this->tokenFor($ctxA['user']);
        $createRes = $this->withHeader('Authorization', "Bearer {$adminTokenA}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctxA['center']->slug,
                'course' => $ctxA['course']->slug,
                'lesson' => $ctxA['lesson']->id,
            ]),
            [
                'title' => 'Center A Quiz',
                'questions' => [
                    [
                        'prompt' => 'Question A',
                        'options' => [
                            ['option_text' => '1', 'is_correct' => true],
                            ['option_text' => '2', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');

        $this->withHeader('Authorization', "Bearer {$adminTokenA}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctxA['center']->slug,
                'course' => $ctxA['course']->slug,
                'lesson' => $ctxA['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctxA['lesson'], $ctxA['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctxA['lesson'], $ctxA['user']);

        // Student enrolled in Center B tries to access Center A's exercise attempts
        $studentCtxB = $this->createStudentContext($ctxB['center'], $ctxB['course'], $ctxB['lesson']);
        $studentTokenB = $this->tokenFor($studentCtxB['studentUser']);

        $res = $this->withHeader('Authorization', "Bearer {$studentTokenB}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctxA['center']->slug,
                'course' => $ctxA['course']->slug,
                'lesson' => $ctxA['lesson']->id,
                'exercise' => $exerciseId,
            ])
        );

        $res->assertForbidden();
    }

    public function test_unenrolled_student_is_denied_assessment(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Sample Quiz',
                'questions' => [
                    [
                        'prompt' => 'Q1',
                        'options' => [
                            ['option_text' => 'A', 'is_correct' => true],
                            ['option_text' => 'B', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        // Student with profile in center but NOT enrolled in course
        $unenrolledUser = User::factory()->create(['email_verified_at' => now()]);
        $membership = CenterUser::query()->create([
            'center_id' => $ctx['center']->id,
            'user_id' => $unenrolledUser->id,
            'status' => 'active',
        ]);
        $studentRole = Role::query()->where('key', 'student')->firstOrFail();
        $membership->roles()->attach($studentRole->id);
        StudentProfile::factory()->forMembership($membership)->create();

        $token = $this->tokenFor($unenrolledUser);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.current', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertForbidden();
    }

    public function test_dual_role_admin_without_course_enrollment_is_denied_assessment(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Sample Quiz',
                'questions' => [
                    [
                        'prompt' => 'Q1',
                        'options' => [
                            ['option_text' => 'A', 'is_correct' => true],
                            ['option_text' => 'B', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        // Dual-role user: center_admin + student with active student profile, but NO course enrollment
        $dualUser = User::factory()->create(['email_verified_at' => now()]);
        $membership = CenterUser::query()->create([
            'center_id' => $ctx['center']->id,
            'user_id' => $dualUser->id,
            'status' => 'active',
        ]);
        $adminRole = Role::query()->where('key', 'center_admin')->firstOrFail();
        $studentRole = Role::query()->where('key', 'student')->firstOrFail();
        $membership->roles()->attach([$adminRole->id, $studentRole->id]);
        StudentProfile::factory()->forMembership($membership)->create();

        $token = $this->tokenFor($dualUser);

        // Accessing assessment endpoint must be denied because no enrollment exists
        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertForbidden();
    }

    public function test_dual_role_admin_with_valid_enrollment_can_take_assessment(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Sample Quiz',
                'questions' => [
                    [
                        'prompt' => 'Q1',
                        'options' => [
                            ['option_text' => 'A', 'is_correct' => true],
                            ['option_text' => 'B', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        // Dual-role user WITH active enrollment
        $dualUser = User::factory()->create(['email_verified_at' => now()]);
        $membership = CenterUser::query()->create([
            'center_id' => $ctx['center']->id,
            'user_id' => $dualUser->id,
            'status' => 'active',
        ]);
        $adminRole = Role::query()->where('key', 'center_admin')->firstOrFail();
        $studentRole = Role::query()->where('key', 'student')->firstOrFail();
        $membership->roles()->attach([$adminRole->id, $studentRole->id]);
        $studentProfile = StudentProfile::factory()->forMembership($membership)->create();

        $cohort = Cohort::factory()->forCourse($ctx['course'])->inProgress()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addMonths(2),
        ]);

        Enrollment::factory()
            ->forStudentProfile($studentProfile)
            ->forCohort($cohort)
            ->create([
                'start_at' => now()->subDay(),
                'end_at' => now()->addMonth(),
            ]);

        $token = $this->tokenFor($dualUser);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertCreated();
    }

    public function test_starting_attempt_twice_resumes_same_in_progress_attempt(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Resume Test Quiz',
                'questions' => [
                    [
                        'prompt' => 'Q1',
                        'options' => [
                            ['option_text' => 'A', 'is_correct' => true],
                            ['option_text' => 'B', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        $studentCtx = $this->createStudentContext($ctx['center'], $ctx['course'], $ctx['lesson']);
        $studentToken = $this->tokenFor($studentCtx['studentUser']);

        // First start
        $res1 = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        );
        $res1->assertCreated();
        $attemptId1 = $res1->json('data.id');

        // Second start
        $res2 = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        );
        $res2->assertCreated();
        $attemptId2 = $res2->json('data.id');

        // Must be the same attempt
        $this->assertSame($attemptId1, $attemptId2);
        $this->assertSame(1, ExerciseAttempt::count());
    }

    public function test_max_attempts_limit_and_submitting_twice_are_enforced(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Strict Max 1 Quiz',
                'max_attempts' => 1,
                'questions' => [
                    [
                        'prompt' => 'Q1',
                        'options' => [
                            ['option_text' => 'A', 'is_correct' => true],
                            ['option_text' => 'B', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        $studentCtx = $this->createStudentContext($ctx['center'], $ctx['course'], $ctx['lesson']);
        $studentToken = $this->tokenFor($studentCtx['studentUser']);

        // Start attempt #1
        $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertCreated();

        // Submit attempt #1
        $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            ['answers' => []]
        )->assertOk();

        // Cannot save answers on completed attempt
        $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'answers' => [
                    ['question_id' => 1, 'selected_option_id' => null],
                ],
            ]
        )->assertNotFound(); // firstOrFail on in_progress attempt throws 404

        // Cannot start attempt #2 because max_attempts is 1
        $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertUnprocessable()
         ->assertJsonValidationErrors(['attempt']);
    }

    public function test_question_and_option_id_integrity_validation(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Integrity Check Quiz',
                'questions' => [
                    [
                        'prompt' => 'Legitimate Q1',
                        'options' => [
                            ['option_text' => 'Opt 1', 'is_correct' => true],
                            ['option_text' => 'Opt 2', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');
        $qId = $createRes->json('data.questions.0.id');
        $optId = $createRes->json('data.questions.0.options.0.id');

        // 1. Update with a non-existent / foreign question ID
        $updateRes1 = $this->withHeader('Authorization', "Bearer {$adminToken}")->putJson(
            route('api.centers.admin.learning.exercises.update', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'title' => 'Integrity Check Quiz',
                'questions' => [
                    [
                        'id' => 99999,
                        'prompt' => 'Hacked Question',
                        'options' => [
                            ['option_text' => 'Opt 1', 'is_correct' => true],
                            ['option_text' => 'Opt 2', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $updateRes1->assertUnprocessable();

        // Verify database was NOT touched
        $this->assertDatabaseHas('questions', ['id' => $qId, 'prompt' => 'Legitimate Q1']);

        // 2. Update with duplicate question IDs in payload
        $updateRes2 = $this->withHeader('Authorization', "Bearer {$adminToken}")->putJson(
            route('api.centers.admin.learning.exercises.update', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'title' => 'Integrity Check Quiz',
                'questions' => [
                    [
                        'id' => $qId,
                        'prompt' => 'Dup Q',
                        'options' => [['option_text' => 'A', 'is_correct' => true], ['option_text' => 'B', 'is_correct' => false]],
                    ],
                    [
                        'id' => $qId,
                        'prompt' => 'Dup Q 2',
                        'options' => [['option_text' => 'C', 'is_correct' => true], ['option_text' => 'D', 'is_correct' => false]],
                    ],
                ],
            ]
        );
        $updateRes2->assertUnprocessable();

        // 3. New question referencing existing option ID
        $updateRes3 = $this->withHeader('Authorization', "Bearer {$adminToken}")->putJson(
            route('api.centers.admin.learning.exercises.update', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'title' => 'Integrity Check Quiz',
                'questions' => [
                    [
                        'prompt' => 'New Question without ID',
                        'options' => [
                            ['id' => $optId, 'option_text' => 'Stolen Option', 'is_correct' => true],
                            ['option_text' => 'Normal Option', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $updateRes3->assertUnprocessable();
    }

    public function test_atomically_author_and_insert_exercise_block(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);

        $res = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.content.blocks.exercise.author-and-insert', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'revision' => $draft->id,
            ]),
            [
                'title' => 'Atomic Author & Insert Quiz',
                'passing_score' => 80,
                'questions' => [
                    [
                        'prompt' => 'Atomic Q1',
                        'points' => 5,
                        'question_type' => 'single_choice',
                        'options' => [
                            ['option_text' => 'Choice A', 'is_correct' => true],
                            ['option_text' => 'Choice B', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );

        $res->assertCreated();
        $res->assertJsonPath('data.block_type', 'exercise');
        $this->assertNotNull($res->json('data.exercise.id'));
        $this->assertSame('Atomic Author & Insert Quiz', $res->json('data.exercise.title'));
        $this->assertSame('published', $res->json('data.exercise.status'));

        // Check block in database
        $this->assertDatabaseHas('lesson_blocks', [
            'lesson_revision_id' => $draft->id,
            'block_type' => 'exercise',
            'exercise_id' => $res->json('data.exercise.id'),
        ]);
    }
}
