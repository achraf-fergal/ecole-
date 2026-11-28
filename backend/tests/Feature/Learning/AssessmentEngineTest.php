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
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\ExerciseAttemptService;
use App\Services\Learning\LessonRevisionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    private function createPublishedExercise(array $context, bool $showCorrectAnswers): Exercise
    {
        $token = $this->tokenFor($context['user']);
        $routeParameters = [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.store', $routeParameters),
            [
                'title' => 'Assessment Authorization Fixture',
                'show_correct_answers_after_submission' => $showCorrectAnswers,
                'questions' => [
                    [
                        'prompt' => 'Which option is correct?',
                        'explanation' => 'This explanation follows the attempt release policy.',
                        'options' => [
                            ['option_text' => 'Correct', 'is_correct' => true],
                            ['option_text' => 'Incorrect', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $response->assertCreated();

        $exerciseId = (int) $response->json('data.id');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [
                ...$routeParameters,
                'exercise' => $exerciseId,
            ])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($context['lesson'], $context['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($context['lesson'], $context['user']);

        return Exercise::query()->findOrFail($exerciseId);
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

    public function test_exercise_total_points_are_derived_from_questions_and_update_when_questions_change(): void
    {
        $context = $this->createAdminContext();
        $token = $this->tokenFor($context['user']);
        $routeParameters = [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
        ];
        $createRoute = route('api.centers.admin.learning.exercises.store', $routeParameters);
        $question = [
            'prompt' => 'How many points is this question worth?',
            'question_type' => 'single_choice',
            'points' => 4,
            'options' => [
                ['option_text' => 'Four', 'is_correct' => true],
                ['option_text' => 'Five', 'is_correct' => false],
            ],
        ];

        $created = $this->withHeader('Authorization', "Bearer {$token}")->postJson($createRoute, [
            'title' => 'Automatic points total',
            'total_points' => 3,
            'questions' => [$question],
        ])->assertCreated()
            ->assertJsonPath('data.total_points', 4);

        $exerciseId = (int) $created->json('data.id');
        $questionId = (int) $created->json('data.questions.0.id');
        $publishRoute = route('api.centers.admin.learning.exercises.publish', [
            ...$routeParameters,
            'exercise' => $exerciseId,
        ]);

        $updated = $this->withHeader('Authorization', "Bearer {$token}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [
                ...$routeParameters,
                'exercise' => $exerciseId,
            ]),
            [
                'total_points' => 99,
                'questions' => [[
                    'id' => $questionId,
                    'prompt' => $question['prompt'],
                    'question_type' => $question['question_type'],
                    'points' => 1.25,
                ]],
            ]
        )->assertOk()->assertJsonPath('data.total_points', 1.25);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson($publishRoute)
            ->assertOk()
            ->assertJsonPath('data.status', Exercise::STATUS_PUBLISHED);

        $this->assertDatabaseHas('exercises', [
            'id' => $exerciseId,
            'total_points' => '1.25',
        ]);
    }

    public function test_fractional_exercise_totals_include_fill_blank_points_and_recalculate_after_question_removal(): void
    {
        $context = $this->createAdminContext();
        $token = $this->tokenFor($context['user']);
        $routeParameters = [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
        ];
        $createRoute = route('api.centers.admin.learning.exercises.store', $routeParameters);
        $fitb = [
            'prompt' => 'I [blank_1] [blank_2].',
            'question_type' => Question::TYPE_FILL_IN_THE_BLANKS,
            'answer_config' => [
                'blanks' => [
                    ['id' => 'blank_1', 'points' => 0.50, 'accepted_answers' => ['am']],
                    ['id' => 'blank_2', 'points' => 0.75, 'accepted_answers' => ['ready']],
                ],
            ],
        ];
        $choice = [
            'prompt' => 'Choose the correct answer.',
            'question_type' => Question::TYPE_SINGLE_CHOICE,
            'points' => 1.25,
            'options' => [
                ['option_text' => 'Correct', 'is_correct' => true],
                ['option_text' => 'Incorrect', 'is_correct' => false],
            ],
        ];

        $created = $this->withHeader('Authorization', "Bearer {$token}")->postJson($createRoute, [
            'title' => 'Fractional exercise total',
            'questions' => [$fitb, $choice],
        ])->assertCreated()
            ->assertJsonPath('data.total_points', 2.5)
            ->assertJsonPath('data.questions.0.points', 1.25);

        $exerciseId = (int) $created->json('data.id');
        $fitbId = (int) $created->json('data.questions.0.id');

        $this->withHeader('Authorization', "Bearer {$token}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            [
                'questions' => [[
                    'id' => $fitbId,
                    ...$fitb,
                    'answer_config' => [
                        'blanks' => [
                            ['id' => 'blank_1', 'points' => 0.25, 'accepted_answers' => ['am']],
                            ['id' => 'blank_2', 'points' => 0.75, 'accepted_answers' => ['ready']],
                        ],
                    ],
                ], [
                    'id' => (int) $created->json('data.questions.1.id'),
                    ...$choice,
                    'points' => 1.25,
                ]],
            ]
        )->assertOk()->assertJsonPath('data.total_points', 2.25);

        $this->withHeader('Authorization', "Bearer {$token}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            ['questions' => [[
                'id' => $fitbId,
                ...$fitb,
                'answer_config' => [
                    'blanks' => [
                        ['id' => 'blank_1', 'points' => 0.25, 'accepted_answers' => ['am']],
                        ['id' => 'blank_2', 'points' => 0.75, 'accepted_answers' => ['ready']],
                    ],
                ],
            ]]]
        )->assertOk()->assertJsonPath('data.total_points', 1);

        $this->assertDatabaseHas('exercises', [
            'id' => $exerciseId,
            'total_points' => '1.00',
        ]);
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
        $resubmitRes->assertStatus(409); // An omitted body differs from the submitted answer payload.
    }

    public function test_release_policy_hides_answers_when_disabled(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);
        $routeParameters = [
            'center' => $ctx['center']->slug,
            'course' => $ctx['course']->slug,
            'lesson' => $ctx['lesson']->id,
        ];
        $privateExplanation = 'CANARY_EXPLANATION_NOT_FOR_STUDENTS_3C';
        $privateAcceptedAnswer = 'CANARY_ACCEPTED_SECRET_3C';
        $privateCorrectOption = 'CANARY_CORRECT_OPTION_3C';

        // Create exercise with show_correct_answers_after_submission = false
        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', $routeParameters),
            [
                'title' => 'Secret Answers Quiz',
                'passing_score' => 60.00,
                'max_attempts' => 1,
                'show_correct_answers_after_submission' => false,
                'questions' => [
                    [
                        'prompt' => 'Confidential prompt',
                        'question_type' => 'true_false',
                        'explanation' => $privateExplanation,
                        'points' => 10.00,
                        'options' => [
                            ['option_text' => $privateCorrectOption, 'is_correct' => true],
                            ['option_text' => 'CANARY_INCORRECT_OPTION_3C', 'is_correct' => false],
                        ],
                    ],
                    [
                        'prompt' => 'Complete the private answer.',
                        'question_type' => Question::TYPE_SHORT_ANSWER,
                        'explanation' => $privateExplanation,
                        'points' => 1,
                        'answer_config' => [
                            'accepted_answers' => [$privateAcceptedAnswer],
                        ],
                        'options' => [],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');
        $createdQuestions = $createRes->json('data.questions');
        $trueFalseQuestionId = (int) $createdQuestions[0]['id'];
        $shortAnswerQuestionId = (int) $createdQuestions[1]['id'];
        $correctOptId = (int) $createdQuestions[0]['options'][0]['id'];

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertOk();

        $adminPreview = $this->withHeader('Authorization', "Bearer {$adminToken}")->getJson(
            route('api.centers.admin.learning.exercises.show', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertOk();
        $adminPreview->assertJsonPath('data.questions.1.answer_config.accepted_answers.0', $privateAcceptedAnswer);
        $adminPreview->assertJsonPath('data.questions.0.options.0.is_correct', true);
        $adminPreview->assertJsonPath('data.questions.0.options.0.option_text', $privateCorrectOption);

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        $studentCtx = $this->createStudentContext($ctx['center'], $ctx['course'], $ctx['lesson']);
        $studentToken = $this->tokenFor($studentCtx['studentUser']);
        $studentRouteParameters = [...$routeParameters, 'exercise' => $exerciseId];
        $studentHeaders = ['Authorization' => "Bearer {$studentToken}"];

        $this->withHeaders($studentHeaders)->getJson(
            route('api.centers.admin.learning.exercises.show', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertForbidden();

        $startRes = $this->withHeaders($studentHeaders)->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $studentRouteParameters)
        )->assertCreated();
        $this->assertStudentResponseHidesPrivateAnswers($startRes, [
            $privateExplanation,
            $privateAcceptedAnswer,
        ]);
        $attemptId = (int) $startRes->json('data.id');
        $answerPayload = [
            ['question_id' => $trueFalseQuestionId, 'selected_option_id' => $correctOptId],
            ['question_id' => $shortAnswerQuestionId, 'text_answer' => 'a student guess'],
        ];

        $currentRes = $this->withHeaders($studentHeaders)->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.current', $studentRouteParameters)
        )->assertOk();
        $this->assertStudentResponseHidesPrivateAnswers($currentRes, [
            $privateExplanation,
            $privateAcceptedAnswer,
        ]);

        $saveRes = $this->withHeaders($studentHeaders)->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', $studentRouteParameters),
            ['answers' => $answerPayload]
        )->assertOk();
        $this->assertStudentResponseHidesPrivateAnswers($saveRes, [
            $privateExplanation,
            $privateAcceptedAnswer,
        ]);

        $currentAfterSave = $this->withHeaders($studentHeaders)->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.current', $studentRouteParameters)
        )->assertOk();
        $this->assertStudentResponseHidesPrivateAnswers($currentAfterSave, [
            $privateExplanation,
            $privateAcceptedAnswer,
        ]);

        $submitRes = $this->withHeaders($studentHeaders)->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', $studentRouteParameters),
            ['answers' => $answerPayload]
        );

        $submitRes->assertOk();
        $submitRes->assertJsonPath('data.show_correct_answers', false);
        $submitRes->assertJsonPath('data.score', 10);
        $submitRes->assertJsonPath('data.passed', true);
        $this->assertStudentResponseHidesPrivateAnswers($submitRes, [
            $privateExplanation,
            $privateAcceptedAnswer,
        ], true);

        $explicitSubmitRes = $this->withHeaders($studentHeaders)->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit-specific', [
                ...$studentRouteParameters,
                'attempt' => $attemptId,
            ]),
            ['answers' => $answerPayload]
        )->assertOk();
        $this->assertStudentResponseHidesPrivateAnswers($explicitSubmitRes, [
            $privateExplanation,
            $privateAcceptedAnswer,
        ], true);

        $legacyNoActive = $this->withHeaders($studentHeaders)->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', $studentRouteParameters),
            ['answers' => $answerPayload]
        )->assertConflict();
        $this->assertStringNotContainsString($privateAcceptedAnswer, $legacyNoActive->getContent());

        $historyRes = $this->withHeaders($studentHeaders)->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.history', $studentRouteParameters)
        )->assertOk();
        $this->assertStudentResponseHidesPrivateAnswers($historyRes, [
            $privateExplanation,
            $privateAcceptedAnswer,
        ], true);

        $currentAfterSubmit = $this->withHeaders($studentHeaders)->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.current', $studentRouteParameters)
        )->assertOk();
        $this->assertStudentResponseHidesPrivateAnswers($currentAfterSubmit, [
            $privateExplanation,
            $privateAcceptedAnswer,
        ], true);
    }

    private function assertStudentResponseHidesPrivateAnswers($response, array $privateValues, bool $allowQuestionStatus = false): void
    {
        $content = $response->getContent();

        $privateFields = [
            'correct_option_id',
            'correct_option_ids',
            'accepted_answers',
            'answer_config',
            'answer_config_snapshot',
            'correct_option_id_snapshot',
            'correct_option_ids_snapshot',
            'options_snapshot',
            'explanation',
        ];
        if (! $allowQuestionStatus) {
            $privateFields[] = 'is_correct';
        }

        foreach ($privateFields as $privateField) {
            $this->assertStringNotContainsString('"'.$privateField.'"', $content);
        }

        foreach ($privateValues as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $content);
        }
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

        $attemptRouteParameters = [
            'center' => $ctx['center']->slug,
            'course' => $ctx['course']->slug,
            'lesson' => $ctx['lesson']->id,
            'exercise' => $exerciseId,
        ];

        $this->withHeader('Authorization', "Bearer {$token}")->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.current', $attemptRouteParameters)
        )->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $attemptRouteParameters)
        )->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', $attemptRouteParameters),
            ['answers' => []]
        )->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', $attemptRouteParameters),
            ['answers' => []]
        )->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.history', $attemptRouteParameters)
        )->assertForbidden();

        $this->assertDatabaseCount('exercise_attempts', 0);
    }

    public function test_center_admin_without_student_membership_cannot_use_assessments(): void
    {
        $context = $this->createAdminContext();
        $exercise = $this->createPublishedExercise($context, true);
        $token = $this->tokenFor($context['user']);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
                'exercise' => $exercise->id,
            ])
        )->assertForbidden();

        $this->assertDatabaseCount('exercise_attempts', 0);
    }

    public function test_platform_admin_without_course_enrollment_cannot_use_assessments(): void
    {
        $context = $this->createAdminContext();
        $exercise = $this->createPublishedExercise($context, true);

        $platformUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::query()->create([
            'user_id' => $platformUser->id,
        ]);
        $membership = CenterUser::query()->create([
            'center_id' => $context['center']->id,
            'user_id' => $platformUser->id,
            'status' => 'active',
        ]);
        $studentRole = Role::query()->where('key', 'student')->firstOrFail();
        $membership->roles()->attach($studentRole->id);
        StudentProfile::factory()->forMembership($membership)->create();

        $token = $this->tokenFor($platformUser);
        $routeParameters = [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
            'exercise' => $exercise->id,
        ];

        $this->withHeader('Authorization', "Bearer {$token}")->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.current', $routeParameters)
        )->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $routeParameters)
        )->assertForbidden();

        $this->assertDatabaseCount('exercise_attempts', 0);
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

    public function test_attempt_start_rechecks_current_published_revision_inside_transaction(): void
    {
        $context = $this->createAdminContext();
        $exercise = $this->createPublishedExercise($context, true);
        $context['lesson'] = $context['lesson']->fresh();
        $student = $this->createStudentContext(
            $context['center'],
            $context['course'],
            $context['lesson']
        );

        $attemptService = app(ExerciseAttemptService::class);
        [$authorizedExercise, $authorizedProfile] = $attemptService
            ->resolveAuthorizedExercise(
                $student['studentUser'],
                $context['center'],
                $context['course'],
                $context['lesson'],
                $exercise->id
            );

        // Simulate publication completing after the first authorization check.
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft(
            $context['lesson'],
            $context['user']
        );
        $exerciseBlock = $draft->blocks->firstWhere(
            'exercise_id',
            $exercise->id
        );
        $this->assertNotNull($exerciseBlock);

        $revisionService->deleteBlock($draft, $exerciseBlock->id);
        $revisionService->addTextBlock($draft, [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Updated lesson content.'],
                    ],
                ],
            ],
        ]);
        $revisionService->publish($context['lesson'], $context['user']);

        try {
            $attemptService->startOrResumeAttempt(
                $student['studentUser'],
                $context['course'],
                $context['lesson'],
                $authorizedExercise,
                $authorizedProfile,
                $context['center']
            );

            $this->fail('An attempt must not start for an exercise removed from the published revision.');
        } catch (NotFoundHttpException) {
            // The transaction sees the newly published revision and rejects the stale context.
        }

        $this->assertDatabaseCount('exercise_attempts', 0);
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

        $attempt = ExerciseAttempt::query()
            ->where('exercise_id', $exerciseId)
            ->where('student_profile_id', $studentCtx['studentProfile']->id)
            ->firstOrFail();

        $originalScore = $attempt->score;
        $originalSubmittedAt = $attempt->submitted_at?->toIso8601String();
        $originalGradedAt = $attempt->graded_at?->toIso8601String();

        // The legacy route is not retry-safe after completion; explicit IDs are required.
        $retryResponse = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            ['answers' => []]
        );
        $retryResponse->assertConflict();
        $this->assertStringContainsString('explicit attempt ID', $retryResponse->json('message'));

        // Same endpoint with a changed request body conflicts and does not re-grade.
        $questionId = (int) $attempt->answers()->value('question_id');
        $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            ['answers' => [['question_id' => $questionId, 'selected_option_id' => null]]]
        )->assertStatus(409);

        // Verify the second submission cannot modify the graded attempt
        $attempt->refresh();
        $this->assertSame(ExerciseAttempt::STATUS_GRADED, $attempt->status);
        $this->assertSame((float) $originalScore, (float) $attempt->score);
        $this->assertSame($originalSubmittedAt, $attempt->submitted_at?->toIso8601String());
        $this->assertSame($originalGradedAt, $attempt->graded_at?->toIso8601String());

        // Verify no answers are graded twice
        $this->assertCount(1, $attempt->answers);
        $this->assertSame((float) $originalScore, (float) $attempt->answers->first()->points_awarded);

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

    public function test_delayed_legacy_submission_cannot_submit_a_newer_active_attempt(): void
    {
        $context = $this->createAdminContext();
        $exercise = $this->createPublishedExercise($context, true);
        $exercise->update(['max_attempts' => 2]);

        $student = $this->createStudentContext(
            $context['center'],
            $context['course'],
            $context['lesson']
        );
        $token = $this->tokenFor($student['studentUser']);
        $routeParameters = [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
            'exercise' => $exercise->id,
        ];
        $headers = ['Authorization' => "Bearer {$token}"];
        $startRoute = route('api.centers.courses.learn.lessons.exercises.attempts.start', $routeParameters);
        $legacySubmitRoute = route('api.centers.courses.learn.lessons.exercises.attempts.submit', $routeParameters);

        $firstStart = $this->withHeaders($headers)->postJson($startRoute)->assertCreated();
        $firstAttemptId = (int) $firstStart->json('data.id');
        $firstQuestion = $firstStart->json('data.questions.0');
        $correctOptionId = (int) collect($firstQuestion['options'])->firstWhere('option_text', 'Correct')['id'];
        $originalAnswers = [[
            'question_id' => (int) $firstQuestion['id'],
            'selected_option_id' => $correctOptionId,
        ]];

        $firstLegacySubmission = $this->withHeaders($headers)->postJson(
            $legacySubmitRoute,
            ['answers' => $originalAnswers]
        )->assertOk()
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.passed', true);

        $firstAttempt = ExerciseAttempt::query()
            ->with('answers')
            ->findOrFail($firstAttemptId);
        $firstResult = $firstLegacySubmission->json('data');

        $this->withHeaders($headers)->postJson(
            $legacySubmitRoute,
            ['answers' => $originalAnswers]
        )->assertConflict()
            ->assertJsonPath('message', 'The legacy submission route cannot identify an attempt after a prior completion. Use the explicit attempt ID route.');

        $secondStart = $this->withHeaders($headers)->postJson($startRoute)->assertCreated();
        $secondAttemptId = (int) $secondStart->json('data.id');
        $this->assertSame(2, (int) $secondStart->json('data.attempt_number'));

        // A delayed retry for attempt #1 must leave attempt #2 untouched.
        $this->withHeaders($headers)->postJson(
            $legacySubmitRoute,
            ['answers' => $originalAnswers]
        )->assertConflict()
            ->assertJsonPath('message', 'The legacy submission route cannot identify an attempt after a prior completion. Use the explicit attempt ID route.');

        $secondAttempt = ExerciseAttempt::query()
            ->with('answers')
            ->findOrFail($secondAttemptId);
        $this->assertSame(ExerciseAttempt::STATUS_IN_PROGRESS, $secondAttempt->status);
        $this->assertNull($secondAttempt->score);
        $this->assertNull($secondAttempt->percentage);
        $this->assertNull($secondAttempt->passed);
        $this->assertNull($secondAttempt->submitted_at);
        $this->assertNull($secondAttempt->graded_at);
        $this->assertNull($secondAttempt->answers->sole()->is_correct);
        $this->assertNull($secondAttempt->answers->sole()->points_awarded);
        $this->assertSame(1, ExerciseAttempt::query()->where('status', ExerciseAttempt::STATUS_GRADED)->count());
        $this->assertSame(2, ExerciseAttempt::query()->where('exercise_id', $exercise->id)->count());

        $explicitSubmitRoute = route(
            'api.centers.courses.learn.lessons.exercises.attempts.submit-specific',
            [...$routeParameters, 'attempt' => $secondAttemptId]
        );
        $secondSubmission = $this->withHeaders($headers)->postJson(
            $explicitSubmitRoute,
            ['answers' => $originalAnswers]
        )->assertOk()
            ->assertJsonPath('data.id', $secondAttemptId)
            ->assertJsonPath('data.attempt_number', 2)
            ->assertJsonPath('data.status', ExerciseAttempt::STATUS_GRADED)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.max_score', 1)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.questions.0.selected_option_id', $correctOptionId)
            ->assertJsonPath('data.questions.0.is_correct', true);

        $secondResult = $secondSubmission->json('data');
        $secondAttempt = ExerciseAttempt::query()->with('answers')->findOrFail($secondAttemptId);
        $submittedAt = $secondAttempt->submitted_at?->toIso8601String();
        $gradedAt = $secondAttempt->graded_at?->toIso8601String();
        $answerUpdatedAt = $secondAttempt->answers->sole()->updated_at?->toIso8601String();
        $this->travel(1)->second();

        $retry = $this->withHeaders($headers)->postJson(
            $explicitSubmitRoute,
            ['answers' => $originalAnswers]
        )->assertOk();

        $this->assertSame($secondResult, $retry->json('data'));
        $secondAttempt->refresh()->load('answers');
        $this->assertSame(ExerciseAttempt::STATUS_GRADED, $secondAttempt->status);
        $this->assertSame($submittedAt, $secondAttempt->submitted_at?->toIso8601String());
        $this->assertSame($gradedAt, $secondAttempt->graded_at?->toIso8601String());
        $this->assertSame($answerUpdatedAt, $secondAttempt->answers->sole()->updated_at?->toIso8601String());
        $this->assertSame(2, ExerciseAttempt::query()->where('exercise_id', $exercise->id)->count());
        $this->assertSame(2, ExerciseAttempt::query()->where('status', ExerciseAttempt::STATUS_GRADED)->count());
        $this->assertSame(1.0, (float) $firstAttempt->score);
        $this->assertSame(1, (int) $firstResult['attempt_number']);
        $this->assertSame(true, $firstResult['passed']);
    }

    public function test_fractional_decimal_scores_and_passing_boundaries_are_exact(): void
    {
        $context = $this->createAdminContext();
        $adminToken = $this->tokenFor($context['user']);
        $routeParameters = [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
        ];

        $created = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', $routeParameters),
            [
                'title' => 'Fractional score assessment',
                'max_attempts' => 4,
                'passing_score' => 70,
                'questions' => [
                    [
                        'prompt' => 'Question worth ten hundredths.',
                        'points' => '0.10',
                        'options' => [
                            ['option_text' => 'Correct', 'is_correct' => true],
                            ['option_text' => 'Incorrect', 'is_correct' => false],
                        ],
                    ],
                    [
                        'prompt' => 'Question worth twenty hundredths.',
                        'points' => '0.20',
                        'options' => [
                            ['option_text' => 'Correct', 'is_correct' => true],
                            ['option_text' => 'Incorrect', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        )->assertCreated();

        $exerciseId = (int) $created->json('data.id');
        $questions = $created->json('data.questions');
        $questionIds = array_map(static fn (array $question): int => (int) $question['id'], $questions);
        $optionIds = array_map(static function (array $question): array {
            return [
                'correct' => (int) collect($question['options'])->firstWhere('is_correct', true)['id'],
                'incorrect' => (int) collect($question['options'])->firstWhere('is_correct', false)['id'],
            ];
        }, $questions);

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertOk();

        $revisionService = app(LessonRevisionService::class);
        $revision = $revisionService->createDraft($context['lesson'], $context['user']);
        $revisionService->addExerciseBlock($revision, $exerciseId);
        $revisionService->publish($context['lesson'], $context['user']);

        $student = $this->createStudentContext(
            $context['center'],
            $context['course'],
            $context['lesson']
        );
        $studentToken = $this->tokenFor($student['studentUser']);
        $studentRouteParameters = [...$routeParameters, 'exercise' => $exerciseId];
        $headers = ['Authorization' => "Bearer {$studentToken}"];
        $startRoute = route('api.centers.courses.learn.lessons.exercises.attempts.start', $studentRouteParameters);

        $answerPayload = static function (bool $firstCorrect, bool $secondCorrect) use ($questionIds, $optionIds): array {
            return [
                [
                    'question_id' => $questionIds[0],
                    'selected_option_id' => $optionIds[0][$firstCorrect ? 'correct' : 'incorrect'],
                ],
                [
                    'question_id' => $questionIds[1],
                    'selected_option_id' => $optionIds[1][$secondCorrect ? 'correct' : 'incorrect'],
                ],
            ];
        };

        $submitAttempt = function (bool $firstCorrect, bool $secondCorrect) use ($headers, $startRoute, $studentRouteParameters, $answerPayload): array {
            $started = $this->withHeaders($headers)->postJson($startRoute)->assertCreated();
            $attemptId = (int) $started->json('data.id');
            $submitRoute = route(
                'api.centers.courses.learn.lessons.exercises.attempts.submit-specific',
                [...$studentRouteParameters, 'attempt' => $attemptId]
            );
            $submitted = $this->withHeaders($headers)->postJson(
                $submitRoute,
                ['answers' => $answerPayload($firstCorrect, $secondCorrect)]
            )->assertOk();

            return [
                'attempt' => ExerciseAttempt::query()->with('answers')->findOrFail($attemptId),
                'response' => $submitted,
            ];
        };

        [$fullAttempt, $fullResponse] = array_values($submitAttempt(true, true));
        $fullResponse->assertJsonPath('data.score', 0.3)
            ->assertJsonPath('data.max_score', 0.3)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.passed', true);
        $this->assertSame('0.30', $fullAttempt->score);
        $this->assertSame('0.30', $fullAttempt->max_score);
        $this->assertSame('0.10', $fullAttempt->answers->firstWhere('question_id', $questionIds[0])->points_awarded);
        $this->assertSame('0.20', $fullAttempt->answers->firstWhere('question_id', $questionIds[1])->points_awarded);

        Exercise::query()->whereKey($exerciseId)->update(['passing_score' => '66.67']);
        [$atThresholdAttempt, $atThresholdResponse] = array_values($submitAttempt(false, true));
        $atThresholdResponse->assertJsonPath('data.score', 0.2)
            ->assertJsonPath('data.max_score', 0.3)
            ->assertJsonPath('data.percentage', 66.67)
            ->assertJsonPath('data.passed', true);
        $this->assertSame('0.20', $atThresholdAttempt->score);
        $this->assertSame('66.67', $atThresholdAttempt->percentage);

        Exercise::query()->whereKey($exerciseId)->update(['passing_score' => '66.68']);
        [$belowThresholdAttempt, $belowThresholdResponse] = array_values($submitAttempt(false, true));
        $belowThresholdResponse->assertJsonPath('data.score', 0.2)
            ->assertJsonPath('data.percentage', 66.67)
            ->assertJsonPath('data.passed', false);
        $this->assertSame('66.68', $belowThresholdAttempt->passing_score_snapshot);

        [$zeroAttempt, $zeroResponse] = array_values($submitAttempt(false, false));
        $zeroResponse->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.max_score', 0.3)
            ->assertJsonPath('data.percentage', 0)
            ->assertJsonPath('data.passed', false);
        $this->assertSame('0.00', $zeroAttempt->score);
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
                    [
                        'prompt' => 'Legitimate Q2',
                        'options' => [
                            ['option_text' => 'Q2 Opt 1', 'is_correct' => true],
                            ['option_text' => 'Q2 Opt 2', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $createRes->assertCreated();
        $exerciseId = $createRes->json('data.id');
        $q1Id = $createRes->json('data.questions.0.id');
        $q2Id = $createRes->json('data.questions.1.id');
        $q1OptionId = $createRes->json('data.questions.0.options.0.id');
        $q2OptionId = $createRes->json('data.questions.1.options.0.id');

        $otherContext = $this->createAdminContext();
        $otherAdminToken = $this->tokenFor($otherContext['user']);
        $foreignExerciseResponse = $this->withHeader(
            'Authorization',
            "Bearer {$otherAdminToken}"
        )->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $otherContext['center']->slug,
                'course' => $otherContext['course']->slug,
                'lesson' => $otherContext['lesson']->id,
            ]),
            [
                'title' => 'Foreign Quiz',
                'questions' => [
                    [
                        'prompt' => 'Foreign Question',
                        'options' => [
                            ['option_text' => 'Foreign Correct', 'is_correct' => true],
                            ['option_text' => 'Foreign Incorrect', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $foreignExerciseResponse->assertCreated();
        $foreignQuestionId = $foreignExerciseResponse->json('data.questions.0.id');

        $originalQuestionRows = Question::query()
            ->where('center_id', $ctx['center']->id)
            ->where('exercise_id', $exerciseId)
            ->orderBy('id')
            ->get()
            ->map(fn (Question $question): array => [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'sort_order' => $question->sort_order,
            ])
            ->all();
        $originalOptionRows = QuestionOption::query()
            ->where('center_id', $ctx['center']->id)
            ->whereIn('question_id', [$q1Id, $q2Id])
            ->orderBy('id')
            ->get()
            ->map(fn (QuestionOption $option): array => [
                'id' => $option->id,
                'question_id' => $option->question_id,
                'option_text' => $option->option_text,
                'is_correct' => $option->is_correct,
                'sort_order' => $option->sort_order,
            ])
            ->all();
        $assertOriginalRowsRemain = function () use (
            $ctx,
            $exerciseId,
            $q1Id,
            $q2Id,
            $originalQuestionRows,
            $originalOptionRows
        ): void {
            $currentQuestions = Question::query()
                ->where('center_id', $ctx['center']->id)
                ->where('exercise_id', $exerciseId)
                ->orderBy('id')
                ->get()
                ->map(fn (Question $question): array => [
                    'id' => $question->id,
                    'prompt' => $question->prompt,
                    'sort_order' => $question->sort_order,
                ])
                ->all();
            $currentOptions = QuestionOption::query()
                ->where('center_id', $ctx['center']->id)
                ->whereIn('question_id', [$q1Id, $q2Id])
                ->orderBy('id')
                ->get()
                ->map(fn (QuestionOption $option): array => [
                    'id' => $option->id,
                    'question_id' => $option->question_id,
                    'option_text' => $option->option_text,
                    'is_correct' => $option->is_correct,
                    'sort_order' => $option->sort_order,
                ])
                ->all();

            $this->assertSame($originalQuestionRows, $currentQuestions);
            $this->assertSame($originalOptionRows, $currentOptions);
        };

        // A question ID from another center and exercise must be rejected before any deletes.
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
                        'id' => $foreignQuestionId,
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
        $assertOriginalRowsRemain();

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
                        'id' => $q1Id,
                        'prompt' => 'Dup Q',
                        'options' => [['option_text' => 'A', 'is_correct' => true], ['option_text' => 'B', 'is_correct' => false]],
                    ],
                    [
                        'id' => $q1Id,
                        'prompt' => 'Dup Q 2',
                        'options' => [['option_text' => 'C', 'is_correct' => true], ['option_text' => 'D', 'is_correct' => false]],
                    ],
                ],
            ]
        );
        $updateRes2->assertUnprocessable();
        $assertOriginalRowsRemain();

        // A new question cannot claim an existing option ID.
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
                            ['id' => $q1OptionId, 'option_text' => 'Stolen Option', 'is_correct' => true],
                            ['option_text' => 'Normal Option', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $updateRes3->assertUnprocessable();
        $assertOriginalRowsRemain();

        // Duplicate option IDs in one question are rejected without changing either question.
        $duplicateOptionsResponse = $this->withHeader('Authorization', "Bearer {$adminToken}")->putJson(
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
                        'id' => $q1Id,
                        'prompt' => 'Duplicate Option Question',
                        'options' => [
                            ['id' => $q1OptionId, 'option_text' => 'Duplicate A', 'is_correct' => true],
                            ['id' => $q1OptionId, 'option_text' => 'Duplicate B', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $duplicateOptionsResponse->assertUnprocessable();
        $assertOriginalRowsRemain();

        // An option belonging to another question cannot be reassigned.
        $crossQuestionOptionResponse = $this->withHeader('Authorization', "Bearer {$adminToken}")->putJson(
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
                        'id' => $q1Id,
                        'prompt' => 'Cross Question Option',
                        'options' => [
                            ['id' => $q2OptionId, 'option_text' => 'Stolen Between Questions', 'is_correct' => true],
                            ['option_text' => 'New Option', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $crossQuestionOptionResponse->assertUnprocessable();
        $assertOriginalRowsRemain();
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

    public function test_author_and_insert_rolls_back_when_revision_is_not_the_current_draft(): void
    {
        $context = $this->createAdminContext();
        $token = $this->tokenFor($context['user']);

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $revision = $revisionService->createDraft($context['lesson'], $context['user']);
        $revisionService->publish($context['lesson'], $context['user']);

        $initialExerciseCount = Exercise::query()->count();
        $initialQuestionCount = Question::query()->count();
        $initialBlockCount = LessonBlock::query()->count();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.content.blocks.exercise.author-and-insert', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
                'revision' => $revision->id,
            ]),
            [
                'title' => 'Must Roll Back',
                'questions' => [
                    [
                        'prompt' => 'Rollback question',
                        'options' => [
                            ['option_text' => 'Correct', 'is_correct' => true],
                            ['option_text' => 'Incorrect', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );

        $response->assertUnprocessable();
        $this->assertSame($initialExerciseCount, Exercise::query()->count());
        $this->assertSame($initialQuestionCount, Question::query()->count());
        $this->assertSame($initialBlockCount, LessonBlock::query()->count());
    }

    public function test_attempt_history_is_paginated_and_respects_each_attempt_release_snapshot(): void
    {
        $context = $this->createAdminContext();
        $exercise = $this->createPublishedExercise($context, false);
        $student = $this->createStudentContext(
            $context['center'],
            $context['course'],
            $context['lesson']
        );
        $token = $this->tokenFor($student['studentUser']);
        $routeParameters = [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
            'exercise' => $exercise->id,
        ];

        $firstStart = $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $routeParameters)
        )->assertCreated();
        $firstAttemptRouteParameters = [...$routeParameters, 'attempt' => (int) $firstStart->json('data.id')];

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit-specific', $firstAttemptRouteParameters),
            ['answers' => []]
        )->assertOk()
            ->assertJsonPath('data.show_correct_answers', false)
            ->assertJsonMissingPath('data.questions.0.correct_option_id')
            ->assertJsonMissingPath('data.questions.0.explanation');

        // The attempt keeps its original release setting when the exercise setting changes.
        $exercise->update([
            'show_correct_answers_after_submission' => true,
        ]);

        $secondStart = $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $routeParameters)
        )->assertCreated();
        $secondAttemptRouteParameters = [...$routeParameters, 'attempt' => (int) $secondStart->json('data.id')];

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit-specific', $secondAttemptRouteParameters),
            ['answers' => []]
        )->assertOk()
            ->assertJsonPath('data.show_correct_answers', true)
            ->assertJsonPath('data.questions.0.correct_option_id', $exercise->questions()->firstOrFail()->options()->where('is_correct', true)->value('id'))
            ->assertJsonPath('data.questions.0.explanation', 'This explanation follows the attempt release policy.');

        // Keep a third active attempt to verify history includes completed attempts only.
        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $routeParameters)
        )->assertCreated();

        $historyRouteParameters = [
            ...$routeParameters,
            'per_page' => 1,
            'page' => 1,
        ];
        $firstPage = $this->withHeader('Authorization', "Bearer {$token}")->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.history', $historyRouteParameters)
        );

        $firstPage->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.attempt_number', 2)
            ->assertJsonPath('data.0.show_correct_answers', true)
            ->assertJsonPath('data.0.questions.0.correct_option_id', $exercise->questions()->firstOrFail()->options()->where('is_correct', true)->value('id'))
            ->assertJsonPath('data.0.questions.0.explanation', 'This explanation follows the attempt release policy.');

        $historyRouteParameters['page'] = 2;
        $secondPage = $this->withHeader('Authorization', "Bearer {$token}")->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.history', $historyRouteParameters)
        );

        $secondPage->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.attempt_number', 1)
            ->assertJsonPath('data.0.show_correct_answers', false)
            ->assertJsonMissingPath('data.0.questions.0.correct_option_id')
            ->assertJsonMissingPath('data.0.questions.0.explanation');
    }

    public function test_strict_answer_payload_validation(): void
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
                'title' => 'Payload Validation Quiz',
                'questions' => [
                    [
                        'prompt' => 'Question One',
                        'options' => [
                            ['option_text' => 'Q1 Opt 1', 'is_correct' => true],
                            ['option_text' => 'Q1 Opt 2', 'is_correct' => false],
                        ],
                    ],
                    [
                        'prompt' => 'Question Two',
                        'options' => [
                            ['option_text' => 'Q2 Opt 1', 'is_correct' => true],
                            ['option_text' => 'Q2 Opt 2', 'is_correct' => false],
                        ],
                    ],
                ],
            ]
        );
        $exerciseId = $createRes->json('data.id');
        $q1Id = $createRes->json('data.questions.0.id');
        $q1Opt1 = $createRes->json('data.questions.0.options.0.id');
        $q2Id = $createRes->json('data.questions.1.id');
        $q2Opt1 = $createRes->json('data.questions.1.options.0.id');

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

        $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertCreated();

        // 1. Reject duplicate question_id in answers payload
        $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'answers' => [
                    ['question_id' => $q1Id, 'selected_option_id' => $q1Opt1],
                    ['question_id' => $q1Id, 'selected_option_id' => null],
                ],
            ]
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['answers']);

        // 2. Reject foreign question ID
        $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'answers' => [
                    ['question_id' => 99999, 'selected_option_id' => null],
                ],
            ]
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['answers']);

        // 3. Reject option from another question
        $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'answers' => [
                    ['question_id' => $q1Id, 'selected_option_id' => $q2Opt1],
                ],
            ]
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['answers']);

        // 4. Incomplete answer array with null selection is allowed
        $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'answers' => [
                    ['question_id' => $q1Id, 'selected_option_id' => null],
                ],
            ]
        )->assertOk();
    }

    public function test_draft_first_exercise_lifecycle_preserves_question_ids_and_uses_seventy_percent_default(): void
    {
        $ctx = $this->createAdminContext();
        $token = $this->tokenFor($ctx['user']);
        $routeParameters = [
            'center' => $ctx['center']->slug,
            'course' => $ctx['course']->slug,
            'lesson' => $ctx['lesson']->id,
        ];

        $created = $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.store', $routeParameters),
            ['title' => 'Draft language practice']
        )->assertCreated()
            ->assertJsonPath('data.status', Exercise::STATUS_DRAFT)
            ->assertJsonPath('data.total_points', 0)
            ->assertJsonPath('data.passing_score', 70);

        $exerciseId = (int) $created->json('data.id');
        $this->withHeader('Authorization', "Bearer {$token}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            ['questions' => []]
        )->assertOk()->assertJsonPath('data.total_points', 0);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertUnprocessable()->assertJsonValidationErrors(['questions']);

        $normalization = [
            'case_sensitive' => false,
            'trim_whitespace' => true,
            'collapse_internal_whitespace' => true,
            'punctuation_sensitive' => true,
            'diacritic_sensitive' => true,
        ];

        $savedIncomplete = $this->withHeader('Authorization', "Bearer {$token}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            [
                'questions' => [[
                    'question_type' => Question::TYPE_SHORT_ANSWER,
                    'prompt' => '',
                    'answer_config' => [
                        'accepted_answers' => [''],
                        'normalization' => $normalization,
                    ],
                    'options' => [],
                ]],
            ]
        )->assertOk()
            ->assertJsonPath('data.status', Exercise::STATUS_DRAFT);

        $questionId = (int) $savedIncomplete->json('data.questions.0.id');
        $this->withHeader('Authorization', "Bearer {$token}")->getJson(
            route('api.centers.admin.learning.exercises.show', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertOk()
            ->assertJsonPath('data.questions.0.id', $questionId)
            ->assertJsonPath('data.questions.0.answer_config.normalization.case_sensitive', false);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['questions']);

        $this->withHeader('Authorization', "Bearer {$token}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            [
                'questions' => [[
                    'id' => $questionId,
                    'question_type' => Question::TYPE_SHORT_ANSWER,
                    'prompt' => 'Complete the sentence: I ___ ready.',
                    'points' => 1,
                    'answer_config' => [
                        'accepted_answers' => ['am', "'m"],
                        'normalization' => $normalization,
                    ],
                    'options' => [],
                ]],
            ]
        )->assertOk()
            ->assertJsonPath('data.questions.0.id', $questionId);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertOk();

        $this->assertDatabaseHas('exercises', [
            'id' => $exerciseId,
            'passing_score' => 70,
            'status' => Exercise::STATUS_PUBLISHED,
        ]);
    }

    public function test_all_four_question_types_grade_from_immutable_snapshots_and_submission_is_idempotent(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);
        $routeParameters = [
            'center' => $ctx['center']->slug,
            'course' => $ctx['course']->slug,
            'lesson' => $ctx['lesson']->id,
        ];
        $normalization = [
            'case_sensitive' => false,
            'trim_whitespace' => true,
            'collapse_internal_whitespace' => true,
            'punctuation_sensitive' => true,
            'diacritic_sensitive' => true,
        ];

        $created = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', $routeParameters),
            [
                'title' => 'Four question type assessment',
                'passing_score' => 70,
                'show_correct_answers_after_submission' => true,
                'questions' => [
                    [
                        'question_type' => Question::TYPE_SINGLE_CHOICE,
                        'prompt' => 'Choose the correct greeting.',
                        'points' => 1,
                        'options' => [
                            ['option_text' => 'Good morning', 'is_correct' => true],
                            ['option_text' => 'Good evening', 'is_correct' => false],
                        ],
                    ],
                    [
                        'question_type' => Question::TYPE_TRUE_FALSE,
                        'prompt' => '"Bonjour" is French.',
                        'points' => 1,
                        'options' => [
                            ['option_text' => 'True', 'is_correct' => true],
                            ['option_text' => 'False', 'is_correct' => false],
                        ],
                    ],
                    [
                        'question_type' => Question::TYPE_MULTIPLE_SELECT,
                        'prompt' => 'Select the two nouns.',
                        'points' => 1,
                        'options' => [
                            ['option_text' => 'teacher', 'is_correct' => true],
                            ['option_text' => 'student', 'is_correct' => true],
                            ['option_text' => 'quickly', 'is_correct' => false],
                        ],
                    ],
                    [
                        'question_type' => Question::TYPE_SHORT_ANSWER,
                        'prompt' => 'Complete: I ___ ready.',
                        'points' => 1,
                        'answer_config' => [
                            'accepted_answers' => ['am', "'m"],
                            'normalization' => $normalization,
                        ],
                    ],
                ],
            ]
        )->assertCreated();

        $exerciseId = (int) $created->json('data.id');
        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $revision = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($revision, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        $studentContext = $this->createStudentContext($ctx['center'], $ctx['course'], $ctx['lesson']);
        $studentToken = $this->tokenFor($studentContext['studentUser']);
        $start = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertCreated()
            ->assertJsonMissingPath('data.questions.2.correct_option_ids')
            ->assertJsonMissingPath('data.questions.3.answer_config')
            ->assertJsonMissingPath('data.questions.3.accepted_answers');

        $attemptId = (int) $start->json('data.id');
        $questions = $start->json('data.questions');
        $multiQuestionId = (int) $questions[2]['id'];
        $multiOptionIds = array_column($questions[2]['options'], 'id');

        $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', [...$routeParameters, 'exercise' => $exerciseId]),
            ['answers' => [[
                'question_id' => $multiQuestionId,
                'selected_option_ids' => [$multiOptionIds[0], $multiOptionIds[0]],
            ]]]
        )->assertUnprocessable();

        $singleCorrectId = (int) $questions[0]['options'][0]['id'];
        $trueFalseCorrectId = (int) $questions[1]['options'][0]['id'];
        $answerPayload = [
            ['question_id' => (int) $questions[0]['id'], 'selected_option_id' => $singleCorrectId],
            ['question_id' => (int) $questions[1]['id'], 'selected_option_id' => $trueFalseCorrectId],
            ['question_id' => $multiQuestionId, 'selected_option_ids' => [$multiOptionIds[1], $multiOptionIds[0]]],
            ['question_id' => (int) $questions[3]['id'], 'text_answer' => 'AM'],
        ];

        $shortQuestion = Question::query()->where('exercise_id', $exerciseId)
            ->where('question_type', Question::TYPE_SHORT_ANSWER)->firstOrFail();
        $shortQuestion->update([
            'prompt' => 'Changed after the attempt started.',
            'points' => 9,
            'answer_config' => [
                'accepted_answers' => ['different'],
                'normalization' => $normalization,
            ],
        ]);

        $submitRoute = route('api.centers.courses.learn.lessons.exercises.attempts.submit-specific', [
            ...$routeParameters,
            'exercise' => $exerciseId,
            'attempt' => $attemptId,
        ]);

        $submitted = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            $submitRoute,
            ['answers' => $answerPayload]
        )->assertOk()
            ->assertJsonPath('data.score', 4)
            ->assertJsonPath('data.max_score', 4)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.questions.2.correct_option_ids', [$multiOptionIds[0], $multiOptionIds[1]])
            ->assertJsonPath('data.questions.3.accepted_answers', ['am', "'m"]);

        $completedAttempt = ExerciseAttempt::query()->with('answers')->findOrFail($attemptId);
        $originalSubmittedAt = $completedAttempt->submitted_at?->toIso8601String();
        $originalGradedAt = $completedAttempt->graded_at?->toIso8601String();
        $originalAnswerUpdatedAt = $completedAttempt->answers->firstWhere('question_id', $multiQuestionId)->updated_at?->toIso8601String();
        $storedResult = $submitted->json('data');
        $this->travel(1)->second();

        $reorderedAnswerPayload = $answerPayload;
        $reorderedAnswerPayload[2]['selected_option_ids'] = array_reverse($reorderedAnswerPayload[2]['selected_option_ids']);
        $retry = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            $submitRoute,
            ['answers' => $reorderedAnswerPayload]
        )->assertOk();
        $this->assertSame($storedResult, $retry->json('data'));

        $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            $submitRoute,
            ['answers' => [
                ...$answerPayload,
                ['question_id' => (int) $questions[3]['id'], 'text_answer' => 'changed'],
            ]]
        )->assertStatus(409);

        $attempt = ExerciseAttempt::query()->with('answers')->findOrFail($attemptId);
        $this->assertSame($originalSubmittedAt, $attempt->submitted_at?->toIso8601String());
        $this->assertSame($originalGradedAt, $attempt->graded_at?->toIso8601String());
        $this->assertSame(4.0, (float) $attempt->score);
        $this->assertSame(4.0, (float) $attempt->max_score);
        $this->assertTrue((bool) $attempt->passed);
        $this->assertCount(4, $attempt->answers);
        $this->assertSame(
            $originalAnswerUpdatedAt,
            $attempt->answers->firstWhere('question_id', $multiQuestionId)->updated_at?->toIso8601String()
        );
        $this->assertSame(1.0, (float) $attempt->answers->firstWhere('question_id', $shortQuestion->id)->points_possible);
        $this->assertSame('Complete: I ___ ready.', $attempt->answers->firstWhere('question_id', $shortQuestion->id)->prompt_snapshot);

        // A later change to the current exercise configuration cannot rewrite a submitted result.
        Exercise::query()->whereKey($exerciseId)->update(['total_points' => 40]);
        Question::query()->whereKey($shortQuestion->id)->update(['points' => 20]);
        $attempt->refresh();
        $attempt->load('answers');
        $this->assertSame(4.0, (float) $attempt->score);
        $this->assertSame(4.0, (float) $attempt->max_score);
        $this->assertSame(100.0, (float) $attempt->percentage);
        $this->assertSame(1.0, (float) $attempt->answers->firstWhere('question_id', $shortQuestion->id)->points_possible);
    }

    public function test_admin_service_rejects_duplicate_option_labels_after_unicode_and_whitespace_normalization(): void
    {
        $ctx = $this->createAdminContext();
        $token = $this->tokenFor($ctx['user']);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson(
            route('api.centers.admin.learning.exercises.store', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
            ]),
            [
                'title' => 'Duplicate labels',
                'questions' => [[
                    'prompt' => 'Choose one.',
                    'options' => [
                        ['option_text' => 'New   York', 'is_correct' => true],
                        ['option_text' => "New\u{00A0}York", 'is_correct' => false],
                    ],
                ]],
            ]
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['questions.0.options.1.option_text']);

        $this->assertDatabaseCount('exercises', 0);
    }

    public function test_published_exercise_immutability_is_protected(): void
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
                'title' => 'Immutable Quiz',
                'questions' => [
                    [
                        'prompt' => 'Immutability Q1',
                        'options' => [
                            ['option_text' => 'Yes', 'is_correct' => true],
                            ['option_text' => 'No', 'is_correct' => false],
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

        // Now that the lesson revision is published, modifying the exercise must fail
        $this->withHeader('Authorization', "Bearer {$adminToken}")->putJson(
            route('api.centers.admin.learning.exercises.update', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ]),
            [
                'title' => 'Attempted Rename',
            ]
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['exercise']);

        // Deleting the exercise must fail
        $this->withHeader('Authorization', "Bearer {$adminToken}")->deleteJson(
            route('api.centers.admin.learning.exercises.destroy', [
                'center' => $ctx['center']->slug,
                'course' => $ctx['course']->slug,
                'lesson' => $ctx['lesson']->id,
                'exercise' => $exerciseId,
            ])
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['exercise']);
    }

    public function test_fill_in_the_blanks_authoring_placeholder_validation_derived_points_and_partial_credit(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);
        $routeParameters = [
            'center' => $ctx['center']->slug,
            'course' => $ctx['course']->slug,
            'lesson' => $ctx['lesson']->id,
        ];
        $normalization = [
            'case_sensitive' => false,
            'trim_whitespace' => true,
            'collapse_internal_whitespace' => true,
            'punctuation_sensitive' => true,
            'diacritic_sensitive' => true,
        ];

        // 1. Create draft with conflicting frontend points (1.00) vs blanks (0.50 + 1.00 + 1.50 = 3.00)
        $created = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', $routeParameters),
            [
                'title' => 'FITB Assessment',
                'passing_score' => 60,
                'show_correct_answers_after_submission' => true,
                'questions' => [[
                    'question_type' => Question::TYPE_FILL_IN_THE_BLANKS,
                    'prompt' => 'I [blank_1] learning [blank_2] because it is [blank_3].',
                    'points' => 1,
                    'answer_config' => [
                        'blanks' => [
                            ['id' => 'blank_1', 'points' => 0.50, 'accepted_answers' => ['am'], 'normalization' => $normalization],
                            ['id' => 'blank_2', 'points' => 1.00, 'accepted_answers' => ['English'], 'normalization' => $normalization],
                            ['id' => 'blank_3', 'points' => 1.50, 'accepted_answers' => ['useful'], 'normalization' => $normalization],
                        ],
                    ],
                    'options' => [],
                ]],
            ]
        )->assertCreated()
            ->assertJsonPath('data.questions.0.points', 3);

        $exerciseId = (int) $created->json('data.id');
        $questionId = (int) $created->json('data.questions.0.id');

        // Reopen draft and verify derived points & blanks persisted
        $this->withHeader('Authorization', "Bearer {$adminToken}")->getJson(
            route('api.centers.admin.learning.exercises.show', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertOk()
            ->assertJsonPath('data.questions.0.points', 3)
            ->assertJsonCount(3, 'data.questions.0.answer_config.blanks');

        // 2. Placeholder validation failures on publish:
        // 2a. Missing definition ([blank_99] in prompt)
        $this->withHeader('Authorization', "Bearer {$adminToken}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            [
                'questions' => [[
                    'id' => $questionId,
                    'question_type' => Question::TYPE_FILL_IN_THE_BLANKS,
                    'prompt' => 'I [blank_1] [blank_2] [blank_3] [blank_99].',
                    'answer_config' => [
                        'blanks' => [
                            ['id' => 'blank_1', 'points' => 0.50, 'accepted_answers' => ['am'], 'normalization' => $normalization],
                            ['id' => 'blank_2', 'points' => 1.00, 'accepted_answers' => ['English'], 'normalization' => $normalization],
                            ['id' => 'blank_3', 'points' => 1.50, 'accepted_answers' => ['useful'], 'normalization' => $normalization],
                        ],
                    ],
                ]],
            ]
        )->assertOk();

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['questions']);

        // 2b. Unused blank definition (blank_3 missing from prompt)
        $this->withHeader('Authorization', "Bearer {$adminToken}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            [
                'questions' => [[
                    'id' => $questionId,
                    'question_type' => Question::TYPE_FILL_IN_THE_BLANKS,
                    'prompt' => 'I [blank_1] learning [blank_2].',
                    'answer_config' => [
                        'blanks' => [
                            ['id' => 'blank_1', 'points' => 0.50, 'accepted_answers' => ['am'], 'normalization' => $normalization],
                            ['id' => 'blank_2', 'points' => 1.00, 'accepted_answers' => ['English'], 'normalization' => $normalization],
                            ['id' => 'blank_3', 'points' => 1.50, 'accepted_answers' => ['useful'], 'normalization' => $normalization],
                        ],
                    ],
                ]],
            ]
        )->assertOk();

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['questions']);

        // 2c. Duplicate prompt placeholder ([blank_1] twice)
        $this->withHeader('Authorization', "Bearer {$adminToken}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            [
                'questions' => [[
                    'id' => $questionId,
                    'question_type' => Question::TYPE_FILL_IN_THE_BLANKS,
                    'prompt' => '[blank_1] and [blank_1] and [blank_2] and [blank_3].',
                    'answer_config' => [
                        'blanks' => [
                            ['id' => 'blank_1', 'points' => 0.50, 'accepted_answers' => ['am'], 'normalization' => $normalization],
                            ['id' => 'blank_2', 'points' => 1.00, 'accepted_answers' => ['English'], 'normalization' => $normalization],
                            ['id' => 'blank_3', 'points' => 1.50, 'accepted_answers' => ['useful'], 'normalization' => $normalization],
                        ],
                    ],
                ]],
            ]
        )->assertOk();

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['questions']);

        // 3. Restore valid 1-to-1 prompt & publish + attach to published lesson revision
        $this->withHeader('Authorization', "Bearer {$adminToken}")->patchJson(
            route('api.centers.admin.learning.exercises.update', [...$routeParameters, 'exercise' => $exerciseId]),
            [
                'questions' => [[
                    'id' => $questionId,
                    'question_type' => Question::TYPE_FILL_IN_THE_BLANKS,
                    'prompt' => 'I [blank_1] learning [blank_2] because it is [blank_3].',
                    'answer_config' => [
                        'blanks' => [
                            ['id' => 'blank_1', 'points' => 0.50, 'accepted_answers' => ['am'], 'normalization' => $normalization],
                            ['id' => 'blank_2', 'points' => 1.00, 'accepted_answers' => ['English'], 'normalization' => $normalization],
                            ['id' => 'blank_3', 'points' => 1.50, 'accepted_answers' => ['useful'], 'normalization' => $normalization],
                        ],
                    ],
                ]],
            ]
        )->assertOk();

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $exerciseId])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $exerciseId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        // 4. Enrolled student starts attempt
        $studentContext = $this->createStudentContext($ctx['center'], $ctx['course'], $ctx['lesson']);
        $studentToken = $this->tokenFor($studentContext['studentUser']);
        $attemptRouteParams = [
            ...$routeParameters,
            'exercise' => $exerciseId,
        ];

        $started = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $attemptRouteParams)
        )->assertCreated()
            ->assertJsonPath('data.max_score', 3);

        // Accepted answers must be hidden before submission
        $this->assertArrayNotHasKey('blanks', $started->json('data.questions.0'));
        $this->assertArrayNotHasKey('answer_config_snapshot', $started->json('data.questions.0'));

        $attemptId = (int) $started->json('data.id');

        // 5. Unknown blank ID must return 422
        $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', $attemptRouteParams),
            [
                'answers' => [[
                    'question_id' => $questionId,
                    'blank_answers' => [
                        'blank_1' => 'am',
                        'blank_999' => 'unexpected',
                    ],
                ]],
            ]
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['answers']);

        // 6. Save partial blank answer & reload
        $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.save', $attemptRouteParams),
            [
                'answers' => [[
                    'question_id' => $questionId,
                    'blank_answers' => [
                        'blank_1' => 'am',
                    ],
                ]],
            ]
        )->assertOk()
            ->assertJsonPath('data.questions.0.blank_answers.blank_1', 'am');

        // 7. Submit with 1st and 3rd correct (0.50 + 1.50 = 2.00 / 3.00 -> 66.67%)
        $submitted = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit-specific', [
                ...$attemptRouteParams,
                'attempt' => $attemptId,
            ]),
            [
                'answers' => [[
                    'question_id' => $questionId,
                    'blank_answers' => [
                        'blank_1' => 'am',
                        'blank_2' => 'French',
                        'blank_3' => 'useful',
                    ],
                ]],
            ]
        )->assertOk()
            ->assertJsonPath('data.score', 2)
            ->assertJsonPath('data.max_score', 3)
            ->assertJsonPath('data.percentage', 66.67)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.questions.0.is_correct', false)
            ->assertJsonPath('data.questions.0.points_awarded', 2)
            ->assertJsonCount(3, 'data.questions.0.blanks');

        $this->assertTrue($submitted->json('data.questions.0.blanks.0.is_correct'));
        $this->assertFalse($submitted->json('data.questions.0.blanks.1.is_correct'));
        $this->assertTrue($submitted->json('data.questions.0.blanks.2.is_correct'));

        // Unenrolled user is forbidden
        $otherStudent = User::factory()->create();
        $otherToken = $this->tokenFor($otherStudent);
        $this->withHeader('Authorization', "Bearer {$otherToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $attemptRouteParams)
        )->assertForbidden();
    }

    public function test_granular_result_visibility_and_after_final_attempt_release_timing(): void
    {
        $ctx = $this->createAdminContext();
        $adminToken = $this->tokenFor($ctx['user']);
        $routeParameters = [
            'center' => $ctx['center']->slug,
            'course' => $ctx['course']->slug,
            'lesson' => $ctx['lesson']->id,
        ];
        $normalization = [
            'case_sensitive' => false,
            'trim_whitespace' => true,
            'collapse_internal_whitespace' => true,
            'punctuation_sensitive' => true,
            'diacritic_sensitive' => true,
        ];

        // Dependency normalization: show_question_results=false forces show_correct_answers=false & show_explanations=false
        $hiddenReviewCreate = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', $routeParameters),
            [
                'title' => 'Hidden Score & Review',
                'max_attempts' => 2,
                'result_settings' => [
                    'show_score' => false,
                    'show_percentage' => false,
                    'show_pass_fail' => false,
                    'show_question_results' => false,
                    'show_student_answers' => false,
                    'show_correct_answers' => true,
                    'show_explanations' => true,
                    'show_points_per_question' => false,
                    'release_timing' => Exercise::RELEASE_TIMING_IMMEDIATELY,
                ],
                'questions' => [[
                    'question_type' => Question::TYPE_TRUE_FALSE,
                    'prompt' => 'Earth is round.',
                    'explanation' => 'SECRET_EXPLANATION_HIDDEN',
                    'points' => 1,
                    'options' => [
                        ['option_text' => 'True', 'is_correct' => true],
                        ['option_text' => 'False', 'is_correct' => false],
                    ],
                ]],
            ]
        )->assertCreated()
            ->assertJsonPath('data.result_settings.show_question_results', false)
            ->assertJsonPath('data.result_settings.show_correct_answers', false)
            ->assertJsonPath('data.result_settings.show_explanations', false)
            ->assertJsonPath('data.show_correct_answers_after_submission', false);

        $hiddenExId = (int) $hiddenReviewCreate->json('data.id');
        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $hiddenExId])
        )->assertOk();

        // Second exercise: show_question_results=true, show_correct_answers=false, release_timing=after_final_attempt
        $timedCreate = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.store', $routeParameters),
            [
                'title' => 'Timed Release Assessment',
                'max_attempts' => 2,
                'passing_score' => 50,
                'result_settings' => [
                    'show_score' => true,
                    'show_percentage' => true,
                    'show_pass_fail' => true,
                    'show_question_results' => true,
                    'show_student_answers' => true,
                    'show_correct_answers' => false,
                    'show_explanations' => false,
                    'show_points_per_question' => true,
                    'release_timing' => Exercise::RELEASE_TIMING_AFTER_FINAL_ATTEMPT,
                ],
                'questions' => [
                    [
                        'question_type' => Question::TYPE_SINGLE_CHOICE,
                        'prompt' => 'Capital of France?',
                        'explanation' => 'SECRET_TIMED_EXPLANATION',
                        'points' => 1,
                        'options' => [
                            ['option_text' => 'Paris', 'is_correct' => true],
                            ['option_text' => 'Lyon', 'is_correct' => false],
                        ],
                    ],
                    [
                        'question_type' => Question::TYPE_FILL_IN_THE_BLANKS,
                        'prompt' => 'Water is [blank_1].',
                        'points' => 1,
                        'answer_config' => [
                            'blanks' => [
                                ['id' => 'blank_1', 'points' => 1, 'accepted_answers' => ['SECRET_FITB_KEY'], 'normalization' => $normalization],
                            ],
                        ],
                        'options' => [],
                    ],
                ],
            ]
        )->assertCreated();

        $timedExId = (int) $timedCreate->json('data.id');
        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            route('api.centers.admin.learning.exercises.publish', [...$routeParameters, 'exercise' => $timedExId])
        )->assertOk();

        /** @var LessonRevisionService $revisionService */
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($ctx['lesson'], $ctx['user']);
        $revisionService->addExerciseBlock($draft, $hiddenExId);
        $revisionService->addExerciseBlock($draft, $timedExId);
        $revisionService->publish($ctx['lesson'], $ctx['user']);

        $studentCtx = $this->createStudentContext($ctx['center'], $ctx['course'], $ctx['lesson']);
        $studentToken = $this->tokenFor($studentCtx['studentUser']);

        // 1. Verify hidden score & hidden review leak nothing
        $hiddenStart = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', [...$routeParameters, 'exercise' => $hiddenExId])
        )->assertCreated();
        $hiddenOptId = (int) $hiddenStart->json('data.questions.0.options.0.id');
        $hiddenQId = (int) $hiddenStart->json('data.questions.0.id');

        $hiddenSubmit = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit-specific', [
                ...$routeParameters,
                'exercise' => $hiddenExId,
                'attempt' => (int) $hiddenStart->json('data.id'),
            ]),
            ['answers' => [['question_id' => $hiddenQId, 'selected_option_id' => $hiddenOptId]]]
        )->assertOk()
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.max_score', null)
            ->assertJsonPath('data.percentage', null)
            ->assertJsonPath('data.passed', null)
            ->assertJsonPath('data.correct_count', null)
            ->assertJsonMissingPath('data.questions.0.is_correct')
            ->assertJsonMissingPath('data.questions.0.points_awarded')
            ->assertJsonMissingPath('data.questions.0.correct_option_id')
            ->assertJsonMissingPath('data.questions.0.explanation');
        $this->assertStringNotContainsString('SECRET_EXPLANATION_HIDDEN', $hiddenSubmit->getContent());

        // 2. Verify release_timing=after_final_attempt withholds Attempt #1, then releases on Attempt #2
        $timedParams = [...$routeParameters, 'exercise' => $timedExId];
        $attempt1 = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $timedParams)
        )->assertCreated();
        $scQId = (int) $attempt1->json('data.questions.0.id');
        $scParisId = (int) $attempt1->json('data.questions.0.options.0.id');
        $fitbQId = (int) $attempt1->json('data.questions.1.id');

        $submit1 = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit-specific', [...$timedParams, 'attempt' => (int) $attempt1->json('data.id')]),
            ['answers' => [['question_id' => $scQId, 'selected_option_id' => $scParisId]]]
        )->assertOk()
            ->assertJsonPath('data.result_visibility.results_released', false)
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.percentage', null)
            ->assertJsonPath('data.passed', null)
            ->assertJsonMissingPath('data.questions.0.is_correct');
        $this->assertStringNotContainsString('SECRET_FITB_KEY', $submit1->getContent());

        // Attempt #2 (final permitted attempt = 2 of 2) -> results released, question results shown, correct answers & explanations still hidden!
        $attempt2 = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.start', $timedParams)
        )->assertCreated();

        $submit2 = $this->withHeader('Authorization', "Bearer {$studentToken}")->postJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.submit-specific', [...$timedParams, 'attempt' => (int) $attempt2->json('data.id')]),
            ['answers' => [['question_id' => $scQId, 'selected_option_id' => $scParisId]]]
        )->assertOk()
            ->assertJsonPath('data.result_visibility.results_released', true)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.max_score', 2)
            ->assertJsonPath('data.percentage', 50)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.correct_count', 1)
            ->assertJsonPath('data.incorrect_count', 0)
            ->assertJsonPath('data.unanswered_count', 1)
            ->assertJsonPath('data.questions.0.result_status', 'correct')
            ->assertJsonPath('data.questions.1.result_status', 'unanswered')
            ->assertJsonMissingPath('data.questions.0.correct_option_id')
            ->assertJsonMissingPath('data.questions.0.explanation')
            ->assertJsonMissingPath('data.questions.1.blanks.0.accepted_answers');
        $this->assertStringNotContainsString('SECRET_TIMED_EXPLANATION', $submit2->getContent());
        $this->assertStringNotContainsString('SECRET_FITB_KEY', $submit2->getContent());

        // History now shows both attempts with released results
        $this->withHeader('Authorization', "Bearer {$studentToken}")->getJson(
            route('api.centers.courses.learn.lessons.exercises.attempts.history', $timedParams)
        )->assertOk()
            ->assertJsonPath('data.0.result_visibility.results_released', true)
            ->assertJsonPath('data.1.result_visibility.results_released', true);
    }
}
