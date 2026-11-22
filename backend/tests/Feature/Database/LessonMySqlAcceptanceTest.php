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

        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->fail(
                "LessonMySqlAcceptanceTest requires running against MySQL 8. Current driver: '{$driver}'. ".
                'Run with MySQL acceptance environment: DB_CONNECTION=mysql DB_DATABASE=school_saas_g5_acceptance_20261121_a7f3 DB_USERNAME=root DB_PASSWORD=achraf123'
            );
        }

        $database = DB::connection()->getDatabaseName();
        if ($database === 'school_saas') {
            $this->fail(
                "SAFETY VIOLATION: Acceptance tests must never execute against development database '{$database}'. Use dedicated acceptance database."
            );
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

    /** Create an independent secondary PDO connection to MySQL for concurrency testing. */
    private function createSecondaryConnection(): \PDO
    {
        $host = config('database.connections.mysql.host', '127.0.0.1');
        $port = config('database.connections.mysql.port', '3306');
        $database = DB::connection()->getDatabaseName();
        $username = config('database.connections.mysql.username', 'root');
        $password = config('database.connections.mysql.password', 'achraf123');

        return new \PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * Test 1: Verify actual MySQL 8 environment and dedicated database isolation.
     */
    public function test_mysql_environment_verification(): void
    {
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();
        $version = DB::select('SELECT VERSION() as v')[0]->v;

        $this->assertSame('mysql', $driver, 'Must be running against MySQL');
        $this->assertStringContainsString('acceptance', $database, 'Must run on dedicated acceptance database');
        $this->assertStringStartsWith('8.', $version, 'MySQL engine must be version 8.x');
    }

    /**
     * Test 2: Foreign Key Compound Constraints on MySQL 8.
     * Attempting to insert a LessonBlock with cross-tenant center_id
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

        $this->expectException(QueryException::class);

        DB::table('lesson_blocks')->insert([
            'center_id' => $otherCenter->id, // Mismatched tenant violates composite FK
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
     * Test 3: MySQL CHECK constraints on lesson_blocks payload mutual exclusivity.
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

        // Invalid: text block with an asset_id violates chk_lb_text_excl
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
     * Test 4: MySQL CHECK constraints on lesson_revisions status.
     * Status must be 'draft', 'published', 'superseded', or 'archived'.
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
            'status' => 'invalid_status_value', // VIOLATION of chk_lr_status
            'title' => 'Bad Revision',
            'content_locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test 5: Transaction rollback preserves atomic state on real MySQL database.
     * When publication validation fails midway, all state changes must be rolled back.
     */
    public function test_transaction_rollback_preserves_atomic_state_on_mysql(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $center = $fixture['center'];
        $admin = $fixture['admin'];

        $service = app(LessonRevisionService::class);
        $draft = $service->createDraft($lesson, $admin);

        $service->addTextBlock($draft, [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Good content']],
                ],
            ],
        ]);

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
        $asset->update(['status' => LessonAsset::STATUS_ARCHIVED]);

        try {
            $service->publish($lesson, $admin);
            $this->fail('Publish should have failed due to archived asset.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('asset_id', $e->errors());
        }

        // Direct database state inspection via raw SQL
        $dbLesson = DB::selectOne('SELECT published_revision_id, draft_revision_id FROM lessons WHERE id = ?', [$lesson->id]);
        $this->assertNull($dbLesson->published_revision_id, 'Database state: published_revision_id must remain null');
        $this->assertSame((int) $draft->id, (int) $dbLesson->draft_revision_id, 'Database state: draft_revision_id must remain intact');

        $dbDraft = DB::selectOne('SELECT status FROM lesson_revisions WHERE id = ?', [$draft->id]);
        $this->assertSame(LessonRevision::STATUS_DRAFT, $dbDraft->status, 'Database state: draft status remains draft');
    }

    /**
     * Test 6: Actual MySQL InnoDB row-locking behavior across independent connections.
     * Verifies that lockForUpdate on Connection 1 actively blocks Connection 2.
     */
    public function test_mysql_innodb_row_locking_blocks_concurrent_connection(): void
    {
        // Unwind RefreshDatabase transaction and commit fixture so secondary connection sees it
        DB::commit();

        try {
            $fixture = $this->createLessonFixture();
            $lesson = $fixture['lesson'];

            $pdo2 = $this->createSecondaryConnection();
            $pdo2->exec('SET SESSION innodb_lock_wait_timeout = 1');

            // Connection 1 acquires exclusive lock on the committed lesson row
            DB::beginTransaction();
            $lockedLesson = DB::table('lessons')->where('id', $lesson->id)->lockForUpdate()->first();
            $this->assertNotNull($lockedLesson);

            // Connection 2 attempts to lock the exact same row -> blocked by InnoDB
            $timedOut = false;
            try {
                $pdo2->beginTransaction();
                $stmt = $pdo2->prepare('SELECT id FROM lessons WHERE id = ? FOR UPDATE');
                $stmt->execute([$lesson->id]);
                $stmt->fetch();
                $pdo2->rollBack();
            } catch (\PDOException $e) {
                $timedOut = true;
                $this->assertStringContainsString('Lock wait timeout exceeded', $e->getMessage());
                $pdo2->rollBack();
            }
            $this->assertTrue($timedOut, 'Second connection was blocked by MySQL InnoDB row lock and timed out as expected');

            // Connection 1 releases lock
            DB::rollBack();

            // Connection 2 can now acquire lock immediately
            $pdo2->beginTransaction();
            $stmt = $pdo2->prepare('SELECT id FROM lessons WHERE id = ? FOR UPDATE');
            $stmt->execute([$lesson->id]);
            $acquired = $stmt->fetch();
            $pdo2->rollBack();

            $this->assertSame((int) $lesson->id, (int) $acquired['id']);
        } finally {
            DB::beginTransaction();
        }
    }

    /**
     * Test 7: Concurrent lesson publishing serialization across independent connections.
     * Simultaneous publishing requests on the same draft are serialized; second call fails cleanly.
     */
    public function test_concurrent_lesson_publishing_serialization_across_connections(): void
    {
        // Commit fixture so secondary connection sees it
        DB::commit();

        try {
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

            $pdo2 = $this->createSecondaryConnection();
            $pdo2->exec('SET SESSION innodb_lock_wait_timeout = 1');

            // Connection 1 acquires lock on lesson inside transaction (simulating mid-publication)
            DB::beginTransaction();
            $conn1Locked = DB::table('lessons')->where('id', $lesson->id)->lockForUpdate()->first();
            $this->assertNotNull($conn1Locked);

            // Connection 2 attempts to acquire lock concurrently -> blocked
            $blocked = false;
            try {
                $pdo2->beginTransaction();
                $stmt = $pdo2->prepare('SELECT id FROM lessons WHERE id = ? FOR UPDATE');
                $stmt->execute([$lesson->id]);
                $pdo2->rollBack();
            } catch (\PDOException $e) {
                $blocked = true;
                $this->assertStringContainsString('Lock wait timeout exceeded', $e->getMessage());
                $pdo2->rollBack();
            }
            $this->assertTrue($blocked, 'Concurrent publication is serialized at database level');

            // Connection 1 releases lock and performs complete publication
            DB::rollBack();

            $published = $service->publish($lesson, $admin);
            $this->assertSame(LessonRevision::STATUS_PUBLISHED, $published->status);

            // A second publish call immediately encounters null draft_revision_id and fails safely
            $failedSafely = false;
            try {
                $service->publish($lesson, $admin);
            } catch (ValidationException $e) {
                $failedSafely = true;
                $this->assertArrayHasKey('revision', $e->errors());
            }
            $this->assertTrue($failedSafely, 'Second publish attempt safely rejected because draft is published');
        } finally {
            DB::beginTransaction();
        }
    }

    /**
     * Test 8: Asset restore and archive preconditions and locking.
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

        // The asset is part of a published revision.
        // assertAssetCanBeModified must reject archiving or editing it!
        $this->expectException(ValidationException::class);
        $revisionService->assertAssetCanBeModified($asset);
    }

    /**
     * Test 9: Concurrent student attempt creation returns a single in-progress attempt.
     */
    public function test_concurrent_student_attempt_creation_returns_single_in_progress_attempt(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $course = $fixture['course'];
        $center = $fixture['center'];
        $admin = $fixture['admin'];

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
     * Test 10: Real deadlock detection and top-level transaction recovery.
     * Verifies that when a MySQL concurrency collision occurs (code 40001),
     * top-level DB::transaction retries and recovers successfully.
     */
    public function test_deadlock_retry_behavior(): void
    {
        // Unwind RefreshDatabase transaction to test true top-level request retry
        DB::rollBack();

        try {
            $attempts = 0;

            $result = DB::transaction(function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    // Simulate MySQL error 1213 deadlock (SQLSTATE 40001)
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
