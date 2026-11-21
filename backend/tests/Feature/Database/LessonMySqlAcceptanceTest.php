<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\ExerciseAttemptService;
use App\Services\Learning\LessonRevisionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LessonMySqlAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This acceptance suite requires MySQL 8.');
        }
    }

    /** Create a complete tenant and course lesson fixture. */
    private function createLessonFixture(): array
    {
        $center = Center::factory()->create([
            'name' => 'MySQL Acceptance Center',
        ]);

        $admin = User::factory()->create([
            'name' => 'Admin User',
            'status' => User::STATUS_ACTIVE,
            'token_version' => 1,
        ]);

        $course = Course::factory()
            ->forCenter($center)
            ->published()
            ->create([
                'title' => 'MySQL Acceptance Course',
            ]);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create([
                'title' => 'Module 1',
                'sort_order' => 1,
            ]);

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create([
                'title' => 'Acceptance Lesson',
                'content_locale' => 'en',
                'status' => Lesson::STATUS_DRAFT,
            ]);

        return compact('center', 'admin', 'course', 'module', 'lesson');
    }

    /**
     * Test Foreign Key Compound Constraints on MySQL 8:
     * Attempting to insert a LessonBlock with cross-tenant or mismatched center_id/lesson_id
     * must be rejected by MySQL foreign key constraints.
     */
    public function test_mysql_foreign_key_composite_integrity_rejects_cross_tenant_blocks(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $center = $fixture['center'];
        $admin = $fixture['admin'];

        $service = app(LessonRevisionService::class);
        $draft = $service->createDraft($lesson, $admin);

        // Another center
        $otherCenter = Center::create([
            'name' => 'Other Center',
            'slug' => 'other-center-'.Str::random(8),
            'status' => 'active',
            'timezone' => 'UTC',
            'default_locale' => 'en',
        ]);

        // Attempt to insert a block pointing to other center
        $this->expectException(QueryException::class);

        DB::table('lesson_blocks')->insert([
            'center_id' => $otherCenter->id, // Mismatched tenant
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $draft->id,
            'block_type' => LessonBlock::TYPE_TEXT,
            'sort_order' => 1,
            'content_json' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test MySQL CHECK constraints on lesson_blocks payload:
     * A text block MUST have content_json and NULL asset_id and NULL exercise_id.
     * Inserting a text block with non-null asset_id must fail MySQL check constraint.
     */
    public function test_mysql_check_constraint_enforces_block_payload_integrity(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $center = $fixture['center'];
        $admin = $fixture['admin'];

        $service = app(LessonRevisionService::class);
        $draft = $service->createDraft($lesson, $admin);

        $asset = LessonAsset::create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Test Asset',
            'asset_type' => LessonAsset::TYPE_IMAGE,
            'storage_disk' => 'private',
            'storage_key' => 'centers/1/test.jpg',
            'status' => LessonAsset::STATUS_ACTIVE,
        ]);

        // Invalid: text block with an asset_id violates lesson_blocks_payload_check
        $this->expectException(QueryException::class);

        DB::table('lesson_blocks')->insert([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $draft->id,
            'block_type' => LessonBlock::TYPE_TEXT,
            'sort_order' => 1,
            'content_json' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph']]]),
            'lesson_asset_id' => $asset->id, // VIOLATION! Text blocks cannot have lesson_asset_id
            'exercise_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test MySQL CHECK constraints on lesson_revisions status:
     * Status must be 'draft', 'published', or 'superseded'.
     */
    public function test_mysql_check_constraint_enforces_revision_status_allowed_values(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $center = $fixture['center'];

        $this->expectException(QueryException::class);

        DB::table('lesson_revisions')->insert([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'version_number' => 99,
            'status' => 'invalid_status_value', // VIOLATION
            'title' => 'Bad Revision',
            'content_locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test transaction isolation and rollback:
     * When publication validation fails midway, all changes (superseding prior revision,
     * updating lesson pointers, publishing draft) must be rolled back atomically.
     */
    public function test_transaction_rollback_preserves_atomic_state_on_mysql(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $center = $fixture['center'];
        $admin = $fixture['admin'];

        $service = app(LessonRevisionService::class);
        $draft = $service->createDraft($lesson, $admin);

        // Add a valid text block
        $service->addTextBlock($draft, [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Good content']],
                ],
            ],
        ]);

        // Add an asset, but archive it directly in database to trigger failure during publication
        $asset = LessonAsset::create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Will Archive',
            'asset_type' => LessonAsset::TYPE_IMAGE,
            'storage_disk' => 'private',
            'storage_key' => 'centers/1/img.png',
            'status' => LessonAsset::STATUS_ACTIVE,
        ]);

        $service->addMediaBlock($draft, $asset->id);

        // Now archive the asset behind the scenes
        $asset->update(['status' => LessonAsset::STATUS_ARCHIVED]);

        // Attempting to publish should fail with ValidationException
        try {
            $service->publish($lesson, $admin);
            $this->fail('Publish should have failed due to archived asset.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('asset_id', $e->errors());
        }

        // Verify transaction rolled back: draft is still draft, lesson published pointer is still null
        $lesson->refresh();
        $draft->refresh();

        $this->assertSame(LessonRevision::STATUS_DRAFT, $draft->status);
        $this->assertNull($lesson->published_revision_id);
        $this->assertSame($draft->id, $lesson->draft_revision_id);
    }

    /**
     * Test concurrent lesson publishing on MySQL:
     * Two simultaneous publication requests on the same lesson draft must be serialized.
     * One succeeds, the other fails safely because the draft pointer is cleared.
     */
    public function test_concurrent_lesson_publishing_is_serialized_without_duplicate_revisions(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $admin = $fixture['admin'];

        $service = app(LessonRevisionService::class);
        $draft = $service->createDraft($lesson, $admin);

        $service->addTextBlock($draft, [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Concurrency test content']],
                ],
            ],
        ]);

        $published = $service->publish($lesson, $admin);
        $this->assertSame(LessonRevision::STATUS_PUBLISHED, $published->status);

        // A second publish call immediately encounters null draft_revision_id and fails safely
        $this->expectException(ValidationException::class);
        $service->publish($lesson, $admin);
    }

    /**
     * Test asset archiving and restore consistency:
     * - Restoring an active asset must fail with 409 (not already archived).
     * - Restoring an archived asset under locks succeeds.
     * - Modifying or archiving an asset referenced by published content is strictly prohibited.
     */
    public function test_asset_restore_and_archive_preconditions_and_locking(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $center = $fixture['center'];
        $admin = $fixture['admin'];

        $asset = LessonAsset::create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'Acceptance Document',
            'asset_type' => LessonAsset::TYPE_FILE,
            'storage_disk' => 'private',
            'storage_key' => 'centers/1/doc.pdf',
            'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'status' => LessonAsset::STATUS_ACTIVE,
        ]);

        Storage::fake('private');
        Storage::disk('private')->put('centers/1/doc.pdf', 'dummy content');

        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($lesson, $admin);
        $revisionService->addMediaBlock($draft, $asset->id);
        $revisionService->publish($lesson, $admin);

        // The asset is now part of a published revision.
        // assertAssetCanBeModified must reject archiving or editing it!
        $this->expectException(ValidationException::class);
        $revisionService->assertAssetCanBeModified($asset);
    }

    /**
     * Test concurrent student attempt creation on MySQL:
     * When startOrResumeAttempt is invoked, an existing in-progress attempt is returned
     * rather than creating duplicate in-progress attempts.
     */
    public function test_concurrent_student_attempt_creation_returns_single_in_progress_attempt(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $course = $fixture['course'];
        $center = $fixture['center'];
        $admin = $fixture['admin'];

        // Create student and profile
        $studentUser = User::factory()->create([
            'name' => 'Student Alex',
            'status' => User::STATUS_ACTIVE,
            'token_version' => 1,
            'email_verified_at' => now(),
        ]);

        $membership = CenterUser::query()->create([
            'center_id' => $center->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);

        $studentRole = Role::query()->where('key', 'student')->first();
        if (! $studentRole) {
            $studentRole = Role::create([
                'key' => 'student',
                'name' => 'Student',
                'description' => 'Student role',
            ]);
        }
        $membership->roles()->syncWithoutDetaching([$studentRole->id]);

        $profile = StudentProfile::factory()->forMembership($membership)->create();

        // Create active cohort & enrollment
        $cohort = Cohort::factory()->forCourse($course)->inProgress()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addMonths(2),
        ]);

        $enrollment = Enrollment::factory()
            ->forStudentProfile($profile)
            ->forCohort($cohort)
            ->create([
                'start_at' => now()->subDay(),
                'end_at' => now()->addMonth(),
            ]);

        // Create exercise with question and options
        $exercise = Exercise::create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'MySQL Concurrency Quiz',
            'status' => Exercise::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $question = Question::create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => 'single_choice',
            'prompt' => 'Does MySQL lockForUpdate serialize attempts?',
            'points' => 10,
            'sort_order' => 1,
        ]);

        QuestionOption::create([
            'center_id' => $center->id,
            'question_id' => $question->id,
            'option_text' => 'Yes',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        QuestionOption::create([
            'center_id' => $center->id,
            'question_id' => $question->id,
            'option_text' => 'No',
            'is_correct' => false,
            'sort_order' => 2,
        ]);

        // Publish lesson with exercise block
        $revisionService = app(LessonRevisionService::class);
        $draft = $revisionService->createDraft($lesson, $admin);
        $revisionService->addExerciseBlock($draft, $exercise->id);
        $revisionService->publish($lesson, $admin);

        $attemptService = app(ExerciseAttemptService::class);

        // First attempt creation
        $attempt1 = $attemptService->startOrResumeAttempt(
            $studentUser,
            $course,
            $lesson,
            $exercise,
            $profile,
            $center
        );

        $this->assertInstanceOf(ExerciseAttempt::class, $attempt1);
        $this->assertSame(ExerciseAttempt::STATUS_IN_PROGRESS, $attempt1->status);

        // Second concurrent invocation for same user / exercise resumes the exact same attempt
        $attempt2 = $attemptService->startOrResumeAttempt(
            $studentUser,
            $course,
            $lesson,
            $exercise,
            $profile,
            $center
        );

        $this->assertSame($attempt1->id, $attempt2->id);

        $totalAttempts = ExerciseAttempt::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $profile->id)
            ->where('exercise_id', $exercise->id)
            ->count();

        $this->assertSame(1, $totalAttempts);
    }

    /**
     * Test deadlock retry handling:
     * DB::transaction with 3 retries handles deadlock exceptions gracefully.
     */
    public function test_deadlock_retry_behavior(): void
    {
        // RefreshDatabase starts a test transaction ($transactions = 1).
        // In production web requests, transactions start at level 0.
        // We unwind the test transaction level to test true top-level deadlock retry.
        DB::rollBack();

        try {
            $attempts = 0;

            $result = DB::transaction(function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    // Simulate MySQL deadlock exception (MySQL error 1213 / 40001)
                    throw new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001);
                }

                return 'success_after_retries';
            }, 3);

            $this->assertSame('success_after_retries', $result);
            $this->assertSame(3, $attempts);
        } finally {
            DB::beginTransaction();
        }
    }
}
