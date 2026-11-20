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
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonProgress;
use App\Models\LessonRevision;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\LessonRevisionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class LearningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function createPublishedRevision(
        Lesson $lesson
    ): LessonRevision {
        $revision = LessonRevision::query()->create([
            'center_id' => $lesson->center_id,
            'lesson_id' => $lesson->id,

            'version_number' => 1,
            'status' => LessonRevision::STATUS_PUBLISHED,

            'title' => $lesson->title,
            'description' => $lesson->description,
            'content_locale' => $lesson->content_locale,
            'duration_minutes' => $lesson->duration_minutes,

            'created_by_user_id' => null,
            'published_by_user_id' => null,
            'published_at' => $lesson->published_at,
        ]);

        LessonBlock::query()->create([
            'center_id' => $lesson->center_id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $revision->id,

            'block_type' => LessonBlock::TYPE_TEXT,
            'sort_order' => 0,

            'content_json' => [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Published lesson content.',
                            ],
                        ],
                    ],
                ],
            ],

            'lesson_asset_id' => null,
            'exercise_id' => null,
            'settings' => null,
        ]);

        $lesson->forceFill([
            'published_revision_id' => $revision->id,
        ])->save();

        return $revision;
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

        $this->createPublishedRevision($lesson);

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
        /** @var JWTGuard $guard */
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

        $revision = LessonRevision::query()
            ->findOrFail($lesson->published_revision_id);

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        $unlinkedAsset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $revision->id,
            'block_type' => LessonBlock::TYPE_MEDIA,
            'sort_order' => 1,
            'content_json' => null,
            'lesson_asset_id' => $asset->id,
            'exercise_id' => null,
            'settings' => null,
        ]);

        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $revision->id,

            'block_type' => LessonBlock::TYPE_EXERCISE,
            'sort_order' => 2,

            'content_json' => null,
            'lesson_asset_id' => null,
            'exercise_id' => $exercise->id,
            'settings' => null,
        ]);

        Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Unlinked Exercise',
            'instructions' => 'Not part of this revision.',
            'max_attempts' => 3,
            'passing_score' => 60,
            'show_correct_answers_after_submission' => false,
            'sort_order' => 3,
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
            'data.revision.id',
            $revision->id
        );

        $response->assertJsonCount(
            3,
            'data.blocks'
        );

        $response->assertJsonPath(
            'data.blocks.0.block_type',
            LessonBlock::TYPE_TEXT
        );

        $response->assertJsonPath(
            'data.blocks.1.block_type',
            LessonBlock::TYPE_MEDIA
        );

        $response->assertJsonPath(
            'data.blocks.1.asset.id',
            $asset->id
        );

        $response->assertJsonPath(
            'data.blocks.2.block_type',
            LessonBlock::TYPE_EXERCISE
        );

        $response->assertJsonPath(
            'data.blocks.2.exercise.id',
            $exercise->id
        );

        $response->assertJsonMissingPath(
            'data.blocks.1.asset.storage_key'
        );

        $response->assertJsonMissingPath(
            'data.blocks.1.asset.storage_disk'
        );

        $response->assertJsonMissingPath(
            'data.blocks.1.asset.provider_asset_id'
        );

        $response->assertJsonMissingPath(
            'data.blocks.2.exercise.questions.0.explanation'
        );

        $response->assertJsonMissingPath(
            'data.blocks.2.exercise.questions.0.options.0.is_correct'
        );

        $this->assertSame(
            [$asset->id],
            collect($response->json('data.assets'))
                ->pluck('id')
                ->all()
        );

        $this->assertSame(
            [$exercise->id],
            collect($response->json('data.exercises'))
                ->pluck('id')
                ->all()
        );

        $this->assertNotContains(
            $unlinkedAsset->id,
            collect($response->json('data.assets'))
                ->pluck('id')
                ->all()
        );

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

    public function test_student_cannot_retrieve_published_lesson_without_published_revision(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $unversionedLesson = Lesson::factory()
            ->forModule($lesson->courseModule)
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
                'lesson' => $unversionedLesson->id,
            ])
        );

        $response->assertNotFound();
    }

    public function test_student_receives_only_current_published_revision_blocks(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $publishedRevision = LessonRevision::query()
            ->findOrFail($lesson->published_revision_id);

        $draft = LessonRevision::query()->create([
            'center_id' => $lesson->center_id,
            'lesson_id' => $lesson->id,
            'version_number' => 2,
            'status' => LessonRevision::STATUS_DRAFT,
            'title' => 'Unpublished Draft',
            'description' => null,
            'content_locale' => 'en',
            'duration_minutes' => 5,
            'created_by_user_id' => $user->id,
            'published_by_user_id' => null,
            'published_at' => null,
        ]);

        LessonBlock::query()->create([
            'center_id' => $lesson->center_id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $draft->id,
            'block_type' => LessonBlock::TYPE_TEXT,
            'sort_order' => 0,
            'content_json' => [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'PRIVATE DRAFT CONTENT',
                            ],
                        ],
                    ],
                ],
            ],
            'lesson_asset_id' => null,
            'exercise_id' => null,
            'settings' => null,
        ]);

        $lesson->forceFill([
            'draft_revision_id' => $draft->id,
        ])->save();

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
            'data.revision.id',
            $publishedRevision->id
        );

        $response->assertJsonPath(
            'data.revision.status',
            LessonRevision::STATUS_PUBLISHED
        );

        $response->assertJsonCount(
            1,
            'data.blocks'
        );

        $this->assertStringNotContainsString(
            'PRIVATE DRAFT CONTENT',
            $response->getContent()
        );
    }

    public function test_navigation_excludes_lessons_without_published_revisions(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'module' => $module,
            'lesson' => $lesson1,
        ] = $this->createLearningContext();

        // Lesson 2 is published but has NO published revision.
        $lesson2 = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create([
                'sort_order' => 2,
            ]);

        // Lesson 3 is published AND has a published revision.
        $lesson3 = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create([
                'sort_order' => 3,
            ]);
        $this->createPublishedRevision($lesson3);

        $token = $this->tokenFor($user);

        // When student views lesson 1, next lesson should skip lesson 2 and be lesson 3.
        $response1 = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson1->id,
            ])
        );

        $response1->assertOk();
        $response1->assertJsonPath('data.navigation.previous_lesson', null);
        $response1->assertJsonPath('data.navigation.next_lesson.id', $lesson3->id);

        // When student views lesson 3, previous lesson should be lesson 1, skipping lesson 2.
        $response3 = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson3->id,
            ])
        );

        $response3->assertOk();
        $response3->assertJsonPath('data.navigation.previous_lesson.id', $lesson1->id);
        $response3->assertJsonPath('data.navigation.next_lesson', null);
    }

    public function test_student_cannot_retrieve_lesson_with_future_published_at_or_superseded_revision(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $revision = LessonRevision::query()->findOrFail($lesson->published_revision_id);
        $revision->update([
            'published_at' => now()->addDay(),
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

        $response->assertNotFound();

        // Now test superseded revision.
        $revision->update([
            'published_at' => now()->subDay(),
            'status' => LessonRevision::STATUS_SUPERSEDED,
        ]);

        $response2 = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );

        $response2->assertNotFound();
    }

    public function test_student_media_block_does_not_expose_storage_references(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create([
                'storage_disk' => 'private_s3_bucket',
                'storage_key' => 'tenants/secret/path/video.mp4',
                'video_provider' => 'cloudflare',
                'provider_asset_id' => 'cf-secret-stream-id-999',
            ]);

        $revision = LessonRevision::query()->findOrFail($lesson->published_revision_id);

        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $revision->id,
            'block_type' => LessonBlock::TYPE_MEDIA,
            'sort_order' => 1,
            'content_json' => null,
            'lesson_asset_id' => $asset->id,
            'exercise_id' => null,
            'settings' => ['caption' => 'Video block'],
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

        $rawBody = $response->getContent();
        $this->assertStringNotContainsString('private_s3_bucket', $rawBody);
        $this->assertStringNotContainsString('tenants/secret/path/video.mp4', $rawBody);
        $this->assertStringNotContainsString('cf-secret-stream-id-999', $rawBody);

        $mediaBlock = collect($response->json('data.blocks'))
            ->firstWhere('block_type', LessonBlock::TYPE_MEDIA);

        $this->assertNotNull($mediaBlock);
        $this->assertArrayNotHasKey('storage_disk', $mediaBlock['asset']);
        $this->assertArrayNotHasKey('storage_key', $mediaBlock['asset']);
        $this->assertArrayNotHasKey('provider_asset_id', $mediaBlock['asset']);
    }

    public function test_draft_only_media_is_inaccessible_to_students(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $draftRevision = LessonRevision::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'version_number' => 2,
            'status' => LessonRevision::STATUS_DRAFT,
            'title' => 'Draft V2',
            'description' => null,
            'content_locale' => 'en',
            'duration_minutes' => 10,
            'created_by_user_id' => null,
            'published_by_user_id' => null,
            'published_at' => null,
        ]);

        $draftAsset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create([
                'title' => 'Draft Only Asset',
            ]);

        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $draftRevision->id,
            'block_type' => LessonBlock::TYPE_MEDIA,
            'sort_order' => 0,
            'content_json' => null,
            'lesson_asset_id' => $draftAsset->id,
            'exercise_id' => null,
            'settings' => null,
        ]);

        $lesson->forceFill(['draft_revision_id' => $draftRevision->id])->save();

        $token = $this->tokenFor($user);

        // Student viewing lesson does NOT receive the draft asset in blocks or assets
        $lessonResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );

        $lessonResponse->assertOk();
        $this->assertStringNotContainsString('Draft Only Asset', $lessonResponse->getContent());

        // Student attempting to access draft asset directly gets 404
        $assetAccessResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.lessons.assets.access', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
                'asset' => $draftAsset->id,
            ])
        );

        $assetAccessResponse->assertNotFound();
    }

    public function test_cross_tenant_access_is_rejected(): void
    {
        [
            'user' => $userA,
        ] = $this->createLearningContext();

        // Create Center B with its own learning content
        [
            'center' => $centerB,
            'course' => $courseB,
            'lesson' => $lessonB,
        ] = $this->createLearningContext();

        $tokenA = $this->tokenFor($userA);

        // Student A tries to access Center B's lesson
        $response = $this->withHeader(
            'Authorization',
            "Bearer {$tokenA}"
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $centerB->slug,
                'course' => $courseB->slug,
                'lesson' => $lessonB->id,
            ])
        );

        $response->assertForbidden();
    }

    public function test_invalid_published_revision_pointer_does_not_expose_legacy_content(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        // Put legacy private content in lesson body
        $lesson->update(['body' => 'LEGACY SECRET CONTENT']);

        // Case: published_revision_id is null (e.g. unmigrated legacy lesson or pre-publish)
        $lesson->forceFill(['published_revision_id' => null])->save();

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

        $response->assertNotFound();
        $this->assertStringNotContainsString('LEGACY SECRET CONTENT', $response->getContent());
    }

    public function test_student_sees_new_published_revision_only_after_publication(): void
    {
        [
            'center' => $center,
            'user' => $student,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $adminUser = User::factory()->create(['email_verified_at' => now()]);
        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $adminUser->id,
            'status' => 'active',
        ]);
        $role = Role::query()->where('key', 'center_admin')->firstOrFail();
        $membership->roles()->attach($role->id);

        /** @var LessonRevisionService $service */
        $service = app(LessonRevisionService::class);

        // Student checks initial lesson (v1)
        $studentToken = $this->tokenFor($student);
        $res1 = $this->withHeader('Authorization', "Bearer {$studentToken}")->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );
        $res1->assertOk();
        $res1->assertJsonPath('data.revision.version_number', 1);

        // Admin creates draft v2 with new block
        $draft2 = $service->createDraft($lesson->fresh(), $adminUser);
        $service->addTextBlock($draft2, [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Brand New V2 Content'],
                    ],
                ],
            ],
        ]);

        // Student checks lesson BEFORE publish: must STILL see v1, NOT v2
        $res2 = $this->withHeader('Authorization', "Bearer {$studentToken}")->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );
        $res2->assertOk();
        $res2->assertJsonPath('data.revision.version_number', 1);
        $this->assertStringNotContainsString('Brand New V2 Content', $res2->getContent());

        // Admin publishes draft v2
        $service->publish($lesson->fresh(), $adminUser);

        // Student checks lesson AFTER publish: must see v2
        $res3 = $this->withHeader('Authorization', "Bearer {$studentToken}")->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );
        $res3->assertOk();
        $res3->assertJsonPath('data.revision.version_number', 2);
        $this->assertStringContainsString('Brand New V2 Content', $res3->getContent());
    }

    public function test_student_progress_is_preserved_through_publication(): void
    {
        [
            'center' => $center,
            'user' => $student,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $studentToken = $this->tokenFor($student);

        // Student updates progress on v1
        $progressRes = $this->withHeader('Authorization', "Bearer {$studentToken}")->patchJson(
            route('api.centers.courses.learn.lessons.progress.update', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ]),
            [
                'last_position_seconds' => 145,
                'completed' => true,
            ]
        );
        $progressRes->assertOk();
        $progressRes->assertJsonPath('data.status', LessonProgress::STATUS_COMPLETED);
        $progressRes->assertJsonPath('data.last_position_seconds', 145);

        // Admin publishes a new revision v2
        $adminUser = User::factory()->create(['email_verified_at' => now()]);
        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $adminUser->id,
            'status' => 'active',
        ]);
        $role = Role::query()->where('key', 'center_admin')->firstOrFail();
        $membership->roles()->attach($role->id);

        /** @var LessonRevisionService $service */
        $service = app(LessonRevisionService::class);
        $draft2 = $service->createDraft($lesson->fresh(), $adminUser);
        $service->addTextBlock($draft2, [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Updated content']],
                ],
            ],
        ]);
        $service->publish($lesson->fresh(), $adminUser);

        // Student views lesson again: progress is intact
        $res = $this->withHeader('Authorization', "Bearer {$studentToken}")->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );
        $res->assertOk();
        $res->assertJsonPath('data.revision.version_number', 2);
        $res->assertJsonPath('data.progress.status', LessonProgress::STATUS_COMPLETED);
        $res->assertJsonPath('data.progress.last_position_seconds', 145);
    }
}
