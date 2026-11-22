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
use App\Models\Language;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\Level;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\ExerciseAttemptService;
use App\Services\Learning\LessonRevisionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LessonMySqlAcceptanceTest extends TestCase
{
    /** @var array<int> Tracked center IDs created during tests for deterministic cleanup. */
    protected array $trackedCenterIds = [];

    /** @var array<int> Tracked user IDs created during tests for deterministic cleanup. */
    protected array $trackedUserIds = [];

    /** @var array Database connection configuration for workers. */
    protected array $acceptanceDbConfig = [];

    /** @var string Previous default DB connection to restore in tearDown. */
    protected string $previousDefaultConnection = 'sqlite';

    protected function setUp(): void
    {
        parent::setUp();

        // Never permit database acceptance tests in production.
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'SAFETY VIOLATION: MySQL acceptance tests cannot run in production.'
            );
        }

        $this->previousDefaultConnection = config(
            'database.default',
            'sqlite'
        );

        // Require explicitly configured acceptance credentials.
        $host = trim((string) env('ACCEPTANCE_DB_HOST', ''));
        $port = env('ACCEPTANCE_DB_PORT', '3306');
        $database = trim((string) env('ACCEPTANCE_DB_DATABASE', ''));
        $username = trim((string) env('ACCEPTANCE_DB_USERNAME', ''));
        $password = env('ACCEPTANCE_DB_PASSWORD');

        $allowedPrefix = 'school_saas_g5_acceptance_';

        $portNumber = filter_var(
            $port,
            FILTER_VALIDATE_INT
        );

        if (
            $host === '' ||
            $username === '' ||
            $password === null ||
            $database === '' ||
            $portNumber === false ||
            $portNumber < 1 ||
            $portNumber > 65535
        ) {
            throw new \RuntimeException(
                'Missing or invalid dedicated ACCEPTANCE_DB configuration.'
            );
        }

        if (
            ! str_starts_with($database, $allowedPrefix) ||
            ! preg_match(
                '/\Aschool_saas_g5_acceptance_[a-zA-Z0-9_]+\z/',
                $database
            )
        ) {
            throw new \RuntimeException(
                'SAFETY VIOLATION: Invalid MySQL acceptance database name.'
            );
        }

        if ($database === 'school_saas') {
            throw new \RuntimeException(
                'SAFETY VIOLATION: Development database is forbidden.'
            );
        }

        $this->acceptanceDbConfig = [
            'host' => $host,
            'port' => (int) $portNumber,
            'database' => $database,
            'username' => $username,
            'password' => (string) $password,
        ];

        try {
            config([
                'database.connections.mysql_acceptance' => [
                    'driver' => 'mysql',
                    'host' => $host,
                    'port' => (int) $portNumber,
                    'database' => $database,
                    'username' => $username,
                    'password' => (string) $password,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'prefix_indexes' => true,
                    'strict' => true,
                    'engine' => 'InnoDB',
                ],

                'database.default' => 'mysql_acceptance',
            ]);

            DB::purge('mysql_acceptance');
            DB::setDefaultConnection('mysql_acceptance');

            $connection = DB::connection('mysql_acceptance');

            if ($connection->getDriverName() !== 'mysql') {
                throw new \RuntimeException(
                    'MySQL acceptance tests require the MySQL driver.'
                );
            }

            $versionRow = $connection->selectOne(
                'SELECT VERSION() AS version'
            );

            $version = (string) ($versionRow->version ?? '');

            if (! preg_match('/^8\./', $version)) {
                throw new \RuntimeException(
                    "MySQL 8 is required. Detected version: {$version}"
                );
            }

        } catch (\Throwable $exception) {
            DB::purge('mysql_acceptance');

            config([
                'database.default' => $this->previousDefaultConnection,
            ]);

            DB::setDefaultConnection(
                $this->previousDefaultConnection
            );

            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            if (app()->environment('production')) {
                throw new \RuntimeException(
                    'SAFETY VIOLATION: Acceptance cleanup is forbidden in production.'
                );
            }

            $currentDatabase = (string) config(
                'database.connections.mysql_acceptance.database',
                ''
            );

            if (
                ! preg_match(
                    '/\Aschool_saas_g5_acceptance_[a-zA-Z0-9_]+\z/',
                    $currentDatabase
                ) ||
                $currentDatabase === 'school_saas'
            ) {
                throw new \RuntimeException(
                    'SAFETY VIOLATION: Teardown refused for an unauthorized database.'
                );
            }

            // Preserve fixture IDs until the entire cleanup transaction commits.
            $centerIds = array_values(
                array_unique($this->trackedCenterIds)
            );

            $userIds = array_values(
                array_unique($this->trackedUserIds)
            );

            if ($centerIds !== [] || $userIds !== []) {
                DB::connection('mysql_acceptance')->transaction(
                    function () use ($centerIds, $userIds): void {
                        foreach ($centerIds as $centerId) {
                            $this->cleanCenterFixtures(
                                (int) $centerId
                            );
                        }

                        // Never delete users still connected to another center.
                        if ($userIds !== []) {
                            DB::table('users')
                                ->whereIn('id', $userIds)
                                ->whereNotExists(function ($query): void {
                                    $query
                                        ->selectRaw('1')
                                        ->from('center_user')
                                        ->whereColumn(
                                            'center_user.user_id',
                                            'users.id'
                                        );
                                })
                                ->delete();
                        }
                    }
                );
            }

            // Clear tracking only after successful database commit.
            $this->trackedCenterIds = [];
            $this->trackedUserIds = [];

        } finally {
            try {
                DB::purge('mysql_acceptance');

                config([
                    'database.default' => $this->previousDefaultConnection,
                ]);

                DB::setDefaultConnection(
                    $this->previousDefaultConnection
                );
            } finally {
                parent::tearDown();
            }
        }
    }

    /** Build environment array passing complete ACCEPTANCE_DB_* variables to workers. */
    private function getWorkerEnv(): array
    {
        return array_merge(getenv(), [
            'ACCEPTANCE_DB_HOST' => $this->acceptanceDbConfig['host'],
            'ACCEPTANCE_DB_PORT' => (string) $this->acceptanceDbConfig['port'],
            'ACCEPTANCE_DB_DATABASE' => $this->acceptanceDbConfig['database'],
            'ACCEPTANCE_DB_USERNAME' => $this->acceptanceDbConfig['username'],
            'ACCEPTANCE_DB_PASSWORD' => (string) $this->acceptanceDbConfig['password'],
        ]);
    }

    private function terminateWorker(
        $process,
        int $graceMs = 500
    ): void {
        if (! is_resource($process)) {
            return;
        }

        $status = proc_get_status($process);

        if (! $status['running']) {
            return;
        }

        // Try graceful termination first.
        proc_terminate($process);

        $graceDeadline = microtime(true)
            + ($graceMs / 1000);

        while (microtime(true) < $graceDeadline) {
            usleep(10000);

            $status = proc_get_status($process);

            if (! $status['running']) {
                return;
            }
        }

        // Graceful shutdown failed: force termination.
        proc_terminate($process, 9);

        $killDeadline = microtime(true) + 1.0;

        while (microtime(true) < $killDeadline) {
            usleep(10000);

            $status = proc_get_status($process);

            if (! $status['running']) {
                return;
            }
        }

        $status = proc_get_status($process);

        if ($status['running']) {
            throw new \RuntimeException(
                'Unable to terminate MySQL acceptance worker.'
            );
        }
    }

    private function collectWorkerProcesses(
        array $workers,
        int $timeoutSeconds = 20
    ): array {
        if ($workers === [] || $timeoutSeconds <= 0) {
            throw new \InvalidArgumentException(
                'Workers and a positive timeout are required.'
            );
        }

        $results = [];

        foreach ($workers as $index => $worker) {
            foreach (['process', 'stdout', 'stderr'] as $key) {
                if (
                    ! isset($worker[$key]) ||
                    ! is_resource($worker[$key])
                ) {
                    throw new \InvalidArgumentException(
                        "Worker {$index} has an invalid {$key} resource."
                    );
                }
            }

            stream_set_blocking($worker['stdout'], false);
            stream_set_blocking($worker['stderr'], false);

            $results[$index] = [
                'stdout' => '',
                'stderr' => '',
                'exitCode' => null,
            ];
        }

        $deadline = microtime(true) + $timeoutSeconds;

        try {
            while (true) {
                $allFinished = true;

                foreach ($workers as $index => $worker) {
                    $stdout = stream_get_contents($worker['stdout']);

                    if ($stdout !== false && $stdout !== '') {
                        $results[$index]['stdout'] .= $stdout;
                    }

                    $stderr = stream_get_contents($worker['stderr']);

                    if ($stderr !== false && $stderr !== '') {
                        $results[$index]['stderr'] .= $stderr;
                    }

                    if ($results[$index]['exitCode'] === null) {
                        $status = proc_get_status($worker['process']);

                        if (! $status['running']) {
                            $results[$index]['exitCode'] =
                                (int) $status['exitcode'];
                        }
                    }

                    $processFinished =
                        $results[$index]['exitCode'] !== null;

                    $streamsFinished =
                        feof($worker['stdout']) &&
                        feof($worker['stderr']);

                    if (! $processFinished || ! $streamsFinished) {
                        $allFinished = false;
                    }
                }

                if ($allFinished) {
                    return $results;
                }

                if (microtime(true) >= $deadline) {
                    $details = json_encode($results);

                    throw new \RuntimeException(
                        "Worker execution exceeded {$timeoutSeconds}s. "
                        ."Partial results: {$details}"
                    );
                }

                usleep(10000);
            }
        } catch (\Throwable $exception) {
            foreach ($workers as $worker) {
                $this->terminateWorker($worker['process']);
            }

            throw $exception;
        }
    }

    /**
     * Deterministically wait until both workers signal ready via their marker files.
     * Throws an exception if workers terminate prematurely or timeout.
     */
    private function waitForWorkersReady(string $ready1, string $ready2, $p1, $p2, int $timeoutSeconds = 10): void
    {
        $deadline = microtime(true) + (float) $timeoutSeconds;
        while ((! file_exists($ready1) || ! file_exists($ready2)) && microtime(true) < $deadline) {
            $s1 = proc_get_status($p1);
            $s2 = proc_get_status($p2);
            if (! $s1['running'] || ! $s2['running']) {
                break;
            }
            usleep(25000);
        }

        if (! file_exists($ready1) || ! file_exists($ready2)) {
            $s1 = proc_get_status($p1);
            $s2 = proc_get_status($p2);
            $this->terminateWorker($p1);
            $this->terminateWorker($p2);
            throw new \RuntimeException(sprintf(
                'Workers failed to reach ready state within %ds. Worker1 ready: %s (running: %s, exit: %s), Worker2 ready: %s (running: %s, exit: %s)',
                $timeoutSeconds,
                file_exists($ready1) ? 'yes' : 'no',
                $s1['running'] ? 'yes' : 'no',
                $s1['exitcode'],
                file_exists($ready2) ? 'yes' : 'no',
                $s2['running'] ? 'yes' : 'no',
                $s2['exitcode']
            ));
        }
    }

    /**
     * Delete all records associated with a test center in strict reverse foreign-key order.
     */
    private function cleanCenterFixtures(int $centerId): void
    {
        DB::table('exercise_attempt_answers')->where('center_id', $centerId)->delete();
        DB::table('exercise_attempts')->where('center_id', $centerId)->delete();
        DB::table('lesson_progresses')->where('center_id', $centerId)->delete();

        $cohortIds = DB::table('cohorts')->where('center_id', $centerId)->pluck('id');
        if ($cohortIds->isNotEmpty()) {
            DB::table('cohort_schedules')->whereIn('cohort_id', $cohortIds)->delete();
        }

        DB::table('enrollments')->where('center_id', $centerId)->delete();
        DB::table('cohorts')->where('center_id', $centerId)->delete();
        DB::table('student_profiles')->where('center_id', $centerId)->delete();

        DB::table('lessons')->where('center_id', $centerId)->update([
            'draft_revision_id' => null,
            'published_revision_id' => null,
        ]);

        DB::table('lesson_blocks')->where('center_id', $centerId)->delete();
        DB::table('lesson_revisions')->where('center_id', $centerId)->delete();
        DB::table('lesson_assets')->where('center_id', $centerId)->delete();

        $exerciseIds = DB::table('exercises')->where('center_id', $centerId)->pluck('id');
        if ($exerciseIds->isNotEmpty()) {
            $questionIds = DB::table('questions')->whereIn('exercise_id', $exerciseIds)->pluck('id');
            if ($questionIds->isNotEmpty()) {
                DB::table('question_options')->whereIn('question_id', $questionIds)->delete();
                DB::table('questions')->whereIn('id', $questionIds)->delete();
            }
            DB::table('exercises')->where('center_id', $centerId)->delete();
        }

        DB::table('lessons')->where('center_id', $centerId)->delete();
        DB::table('course_modules')->where('center_id', $centerId)->delete();

        $courseIds = DB::table('courses')->where('center_id', $centerId)->pluck('id');
        if ($courseIds->isNotEmpty()) {
            DB::table('course_outcomes')->whereIn('course_id', $courseIds)->delete();
            DB::table('course_translations')->whereIn('course_id', $courseIds)->delete();
        }

        DB::table('courses')->where('center_id', $centerId)->delete();
        DB::table('languages')->where('center_id', $centerId)->delete();
        DB::table('levels')->where('center_id', $centerId)->delete();

        $memberships = DB::table('center_user')->where('center_id', $centerId)->get();
        $membershipIds = $memberships->pluck('id')->all();

        if (! empty($membershipIds)) {
            DB::table('center_user_role')->whereIn('center_user_id', $membershipIds)->delete();
            DB::table('center_user')->where('center_id', $centerId)->delete();
        }

        DB::table('centers')->where('id', $centerId)->delete();

        // Verification: Center was cleanly removed
        $remaining = DB::table('centers')->where('id', $centerId)->count();
        $this->assertSame(0, $remaining, "Cleanup verification: center {$centerId} must not leave orphaned records.");
    }

    /** Create a complete tenant and course lesson fixture on MySQL. */
    private function createLessonFixture(): array
    {
        $uniq = Str::lower(Str::random(8));

        $center = Center::create([
            'name' => 'MySQL Acceptance Center '.$uniq,
            'slug' => 'mysql-center-'.$uniq,
            'status' => 'active',
            'timezone' => 'UTC',
            'default_locale' => 'en',
        ]);
        $this->trackedCenterIds[] = $center->id;

        $admin = User::create([
            'name' => 'Admin User '.$uniq,
            'email' => 'admin_'.$uniq.'@acceptance.test',
            'password' => 'Password123!',
            'status' => User::STATUS_ACTIVE,
            'token_version' => 1,
        ]);
        $this->trackedUserIds[] = $admin->id;

        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $adminRole = Role::query()->where('key', 'center_admin')->first();
        if (! $adminRole) {
            $adminRole = Role::firstOrCreate(['key' => 'center_admin'], ['name' => 'Center Admin']);
        }
        $membership->roles()->syncWithoutDetaching([$adminRole->id]);

        $language = Language::firstOrCreate(
            ['center_id' => $center->id, 'code' => 'en'],
            ['name' => 'English', 'native_name' => 'English', 'is_active' => true]
        );

        $level = Level::firstOrCreate(
            ['center_id' => $center->id, 'code' => 'A1'],
            ['name' => 'Beginner', 'sort_order' => 1, 'is_active' => true]
        );

        $course = Course::create([
            'center_id' => $center->id,
            'language_id' => $language->id,
            'level_id' => $level->id,
            'title' => 'MySQL Acceptance Course '.$uniq,
            'slug' => 'course-'.$uniq,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        $module = CourseModule::create([
            'center_id' => $center->id,
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 1,
            'status' => CourseModule::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);

        $lesson = Lesson::create([
            'center_id' => $center->id,
            'course_module_id' => $module->id,
            'title' => 'Acceptance Lesson '.$uniq,
            'content_locale' => 'en',
            'status' => Lesson::STATUS_DRAFT,
        ]);

        return compact('center', 'admin', 'course', 'module', 'lesson');
    }

    /** Create an independent secondary PDO connection to MySQL for concurrency testing. */
    private function createSecondaryConnection(): \PDO
    {
        $host = config('database.connections.mysql_acceptance.host', '127.0.0.1');
        $port = config('database.connections.mysql_acceptance.port', '3306');
        $database = config('database.connections.mysql_acceptance.database');
        $username = config('database.connections.mysql_acceptance.username');
        $password = config('database.connections.mysql_acceptance.password');

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
        $admin = $fixture['admin'];

        $service = app(LessonRevisionService::class);
        $draft = $service->createDraft($lesson, $admin);

        // Another center
        $otherCenter = Center::create([
            'name' => 'Other Center '.Str::random(6),
            'slug' => 'other-center-'.Str::random(8),
            'status' => 'active',
            'timezone' => 'UTC',
            'default_locale' => 'en',
        ]);
        $this->trackedCenterIds[] = $otherCenter->id;

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
            $this->assertTrue(
                isset($e->errors()['blocks']) || isset($e->errors()['asset_id']),
                'Expected validation error on blocks or asset_id'
            );
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
    }

    /**
     * Test 7: Actual concurrent lesson publishing across two genuinely independent worker processes.
     * Both processes invoke the actual publication service concurrently on the same lesson & draft.
     * Coordinated by a barrier file. Exactly one succeeds; the second fails safely.
     */
    public function test_concurrent_lesson_publishing_serialization_across_connections(): void
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
                    'content' => [['type' => 'text', 'text' => 'Real Concurrency Content']],
                ],
            ],
        ]);

        $workerScript = base_path('tests/fixtures/concurrent_publish_worker.php');
        $ready1File = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pub_w1_ready_'.uniqid().'.tmp';
        $ready2File = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pub_w2_ready_'.uniqid().'.tmp';
        $releaseSignalFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pub_release_'.uniqid().'.tmp';

        $php = PHP_BINARY ?: 'php';
        $cmd1 = escapeshellarg($php).' '.escapeshellarg($workerScript).' '.$lesson->id.' '.$admin->id.' '.escapeshellarg($ready1File).' '.escapeshellarg($releaseSignalFile);
        $cmd2 = escapeshellarg($php).' '.escapeshellarg($workerScript).' '.$lesson->id.' '.$admin->id.' '.escapeshellarg($ready2File).' '.escapeshellarg($releaseSignalFile);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->getWorkerEnv();

        $p1 = proc_open($cmd1, $descriptors, $pipes1, base_path(), $env);
        $p2 = proc_open($cmd2, $descriptors, $pipes2, base_path(), $env);

        $this->assertIsResource($p1);
        $this->assertIsResource($p2);

        fclose($pipes1[0]);
        fclose($pipes2[0]);

        $exit1 = null;
        $exit2 = null;

        try {
            // Deterministically wait for both workers to connect and load models
            $this->waitForWorkersReady($ready1File, $ready2File, $p1, $p2, 10);

            // Release barrier -> Both processes fire simultaneously
            touch($releaseSignalFile);

            $workerResults = $this->collectWorkerProcesses([
                [
                    'process' => $p1,
                    'stdout' => $pipes1[1],
                    'stderr' => $pipes1[2],
                ],
                [
                    'process' => $p2,
                    'stdout' => $pipes2[1],
                    'stderr' => $pipes2[2],
                ],
            ], 20);

            $out1 = $workerResults[0]['stdout'];
            $err1 = $workerResults[0]['stderr'];
            $exit1 = $workerResults[0]['exitCode'];

            $out2 = $workerResults[1]['stdout'];
            $err2 = $workerResults[1]['stderr'];
            $exit2 = $workerResults[1]['exitCode'];
        } finally {
            fclose($pipes1[1]);
            fclose($pipes2[1]);
            fclose($pipes1[2]);
            fclose($pipes2[2]);

            $this->terminateWorker($p1);
            $this->terminateWorker($p2);

            $closeExit1 = proc_close($p1);
            $closeExit2 = proc_close($p2);

            if ($exit1 === null || $exit1 < 0) {
                $exit1 = $closeExit1;
            }

            if ($exit2 === null || $exit2 < 0) {
                $exit2 = $closeExit2;
            }

            @unlink($ready1File);
            @unlink($ready2File);
            @unlink($releaseSignalFile);
        }

        $this->assertSame(
            0,
            $exit1,
            "Worker 1 exited unsuccessfully: {$err1}"
        );

        $this->assertSame(
            0,
            $exit2,
            "Worker 2 exited unsuccessfully: {$err2}"
        );

        $res1 = json_decode(trim($out1), true);
        $res2 = json_decode(trim($out2), true);

        $this->assertIsArray($res1, "Worker 1 output was not valid JSON. stdout: '{$out1}', stderr: '{$err1}', exit: {$exit1}");
        $this->assertIsArray($res2, "Worker 2 output was not valid JSON. stdout: '{$out2}', stderr: '{$err2}', exit: {$exit2}");

        // Exactly one worker must succeed, and the losing worker must report validation_error
        $statuses = [$res1['status'], $res2['status']];
        $this->assertContains('success', $statuses, 'Exactly one concurrent publication worker must succeed');
        $this->assertContains('validation_error', $statuses, 'Losing concurrent publication worker must fail cleanly with validation error');

        $successRes = $res1['status'] === 'success' ? $res1 : $res2;
        $this->assertSame(1, (int) $successRes['version_number'], 'Published revision must be version 1');

        // Database consistency verification
        $publishedRevisions = DB::table('lesson_revisions')
            ->where('lesson_id', $lesson->id)
            ->where('status', LessonRevision::STATUS_PUBLISHED)
            ->get();

        $this->assertCount(1, $publishedRevisions, 'Database consistency: exactly one revision is published');
        $this->assertSame((int) $publishedRevisions->first()->id, (int) $successRes['revision_id']);

        $dbLesson = DB::table('lessons')->where('id', $lesson->id)->first();
        $this->assertNotNull($dbLesson->published_revision_id);
        $this->assertNull($dbLesson->draft_revision_id, 'Draft pointer must be null after publication');
        $this->assertSame((int) $publishedRevisions->first()->id, (int) $dbLesson->published_revision_id);

        $dbLesson = DB::table('lessons')->where('id', $lesson->id)->first();
        $this->assertNotNull($dbLesson->published_revision_id);
        $this->assertNull($dbLesson->draft_revision_id, 'Draft pointer must be null after publication');
        $this->assertSame((int) $publishedRevisions->first()->id, (int) $dbLesson->published_revision_id);
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
     * Test 9: Actual concurrent student attempt creation across two independent worker processes.
     * Both processes invoke ExerciseAttemptService::startOrResumeAttempt simultaneously for the same student.
     * Coordinated by a barrier file. Exactly one active attempt is created; both receive the same attempt.
     */
    public function test_concurrent_student_attempt_creation_returns_single_in_progress_attempt(): void
    {
        $fixture = $this->createLessonFixture();
        $lesson = $fixture['lesson'];
        $course = $fixture['course'];
        $center = $fixture['center'];
        $admin = $fixture['admin'];

        $uniq = Str::lower(Str::random(6));

        $studentUser = User::create([
            'name' => 'Student Alex '.$uniq,
            'email' => 'student_'.$uniq.'@acceptance.test',
            'password' => 'Password123!',
            'status' => User::STATUS_ACTIVE,
            'token_version' => 1,
            'email_verified_at' => now(),
        ]);
        $this->trackedUserIds[] = $studentUser->id;

        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);

        $studentRole = Role::query()->where('key', 'student')->first();
        if (! $studentRole) {
            $studentRole = Role::firstOrCreate(['key' => 'student'], ['name' => 'Student']);
        }
        $membership->roles()->syncWithoutDetaching([$studentRole->id]);

        $profile = StudentProfile::factory()->forMembership($membership)->create();

        $cohort = Cohort::factory()->forCourse($course)->inProgress()->create([
            'center_id' => $center->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addMonths(2),
        ]);

        Enrollment::factory()
            ->forStudentProfile($profile)
            ->forCohort($cohort)
            ->create([
                'center_id' => $center->id,
                'start_at' => now()->subDay(),
                'end_at' => now()->addMonth(),
            ]);

        $exercise = Exercise::create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'title' => 'MySQL Concurrency Quiz '.$uniq,
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

        // Run two concurrent worker processes calling startOrResumeAttempt
        $workerScript = base_path('tests/fixtures/concurrent_attempt_worker.php');
        $ready1File = sys_get_temp_dir().DIRECTORY_SEPARATOR.'att_w1_ready_'.uniqid().'.tmp';
        $ready2File = sys_get_temp_dir().DIRECTORY_SEPARATOR.'att_w2_ready_'.uniqid().'.tmp';
        $releaseSignalFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'att_release_'.uniqid().'.tmp';

        $php = PHP_BINARY ?: 'php';
        $args = "{$studentUser->id} {$course->id} {$lesson->id} {$exercise->id} {$profile->id} {$center->id}";
        $cmd1 = escapeshellarg($php).' '.escapeshellarg($workerScript).' '.$args.' '.escapeshellarg($ready1File).' '.escapeshellarg($releaseSignalFile);
        $cmd2 = escapeshellarg($php).' '.escapeshellarg($workerScript).' '.$args.' '.escapeshellarg($ready2File).' '.escapeshellarg($releaseSignalFile);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->getWorkerEnv();

        $p1 = proc_open($cmd1, $descriptors, $pipes1, base_path(), $env);
        $p2 = proc_open($cmd2, $descriptors, $pipes2, base_path(), $env);

        $this->assertIsResource($p1);
        $this->assertIsResource($p2);

        fclose($pipes1[0]);
        fclose($pipes2[0]);

        $exit1 = null;
        $exit2 = null;

        try {
            $this->waitForWorkersReady($ready1File, $ready2File, $p1, $p2, 10);

            touch($releaseSignalFile); // Trigger simultaneous execution

            $workerResults = $this->collectWorkerProcesses([
                [
                    'process' => $p1,
                    'stdout' => $pipes1[1],
                    'stderr' => $pipes1[2],
                ],
                [
                    'process' => $p2,
                    'stdout' => $pipes2[1],
                    'stderr' => $pipes2[2],
                ],
            ], 20);

            $out1 = $workerResults[0]['stdout'];
            $err1 = $workerResults[0]['stderr'];
            $exit1 = $workerResults[0]['exitCode'];

            $out2 = $workerResults[1]['stdout'];
            $err2 = $workerResults[1]['stderr'];
            $exit2 = $workerResults[1]['exitCode'];
        } finally {
            fclose($pipes1[1]);
            fclose($pipes2[1]);
            fclose($pipes1[2]);
            fclose($pipes2[2]);

            $this->terminateWorker($p1);
            $this->terminateWorker($p2);

            $closeExit1 = proc_close($p1);
            $closeExit2 = proc_close($p2);

            if ($exit1 === null || $exit1 < 0) {
                $exit1 = $closeExit1;
            }

            if ($exit2 === null || $exit2 < 0) {
                $exit2 = $closeExit2;
            }

            @unlink($ready1File);
            @unlink($ready2File);
            @unlink($releaseSignalFile);
        }

        $this->assertSame(
            0,
            $exit1,
            "Worker 1 exited unsuccessfully: {$err1}"
        );

        $this->assertSame(
            0,
            $exit2,
            "Worker 2 exited unsuccessfully: {$err2}"
        );

        $res1 = json_decode(trim($out1), true);
        $res2 = json_decode(trim($out2), true);

        $this->assertIsArray($res1, "Worker 1 output invalid JSON. stdout: '{$out1}', stderr: '{$err1}', exit: {$exit1}");
        $this->assertIsArray($res2, "Worker 2 output invalid JSON. stdout: '{$out2}', stderr: '{$err2}', exit: {$exit2}");

        $this->assertSame('success', $res1['status'], 'Worker 1 error: '.json_encode($res1));
        $this->assertSame('success', $res2['status'], 'Worker 2 error: '.json_encode($res2));

        // Both concurrent requests must have resolved to the EXACT same attempt ID
        $this->assertSame($res1['attempt_id'], $res2['attempt_id'], 'Both concurrent workers must receive the exact same attempt ID');
        $this->assertSame(1, (int) $res1['attempt_number'], 'Attempt number must be exactly 1');
        $this->assertSame(1, (int) $res2['attempt_number'], 'Attempt number must be exactly 1');
        $this->assertSame('in_progress', $res1['attempt_status']);

        // In the database, exactly one attempt must exist
        $totalAttempts = DB::table('exercise_attempts')
            ->where('center_id', $center->id)
            ->where('student_profile_id', $profile->id)
            ->where('exercise_id', $exercise->id)
            ->count();

        $this->assertSame(1, $totalAttempts, 'Database consistency: exactly one active attempt exists');
    }

    /**
     * Test 10: Real MySQL InnoDB Deadlock detection and Laravel transaction recovery.
     * Separates:
     * 1. Real MySQL deadlock behavior (InnoDB cycle detection error 1213 / SQLSTATE 40001).
     * 2. Laravel transaction retry behavior (DB::transaction retries and recovers).
     * 3. Rollback integrity (failed iterations leave no partial writes).
     * 4. Nested transaction savepoint rollback integrity.
     */
    public function test_deadlock_retry_behavior(): void
    {
        $fixture = $this->createLessonFixture();
        $lessonA = $fixture['lesson'];

        // Create a second lesson in the same module
        $lessonB = Lesson::create([
            'center_id' => $fixture['center']->id,
            'course_module_id' => $fixture['module']->id,
            'title' => 'Deadlock Lesson B',
            'content_locale' => 'en',
            'status' => Lesson::STATUS_DRAFT,
        ]);

        // --- Part 1: Real MySQL InnoDB Deadlock Cycle ---
        $workerScript = base_path('tests/fixtures/deadlock_worker.php');
        $workerLockedBFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dl_wb_'.uniqid().'.tmp';
        $parentLockedAFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dl_pa_'.uniqid().'.tmp';
        $workerReqAFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dl_wreqa_'.uniqid().'.tmp';

        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellarg($php).' '.escapeshellarg($workerScript).' '.$lessonA->id.' '.$lessonB->id.' '.escapeshellarg($workerLockedBFile).' '.escapeshellarg($parentLockedAFile).' '.escapeshellarg($workerReqAFile);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->getWorkerEnv();

        $proc = proc_open($cmd, $descriptors, $pipes, base_path(), $env);
        $this->assertIsResource($proc);

        fclose($pipes[0]);

        $deadlockCaught = false;
        $workerExitCode = null;
        try {
            // Wait for Worker to lock Lesson B (Step 1)
            $deadlineB = microtime(true) + 10.0;
            while (! file_exists($workerLockedBFile) && microtime(true) < $deadlineB) {
                usleep(10000);
            }
            if (! file_exists($workerLockedBFile)) {
                throw new \RuntimeException('Timed out waiting for worker to lock Lesson B');
            }

            $pdo1 = DB::connection()->getPdo();
            $pdo1->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo1->exec('SET SESSION innodb_lock_wait_timeout = 5');

            try {
                $pdo1->beginTransaction();

                // Parent locks Lesson A
                $stmt = $pdo1->prepare('SELECT id FROM lessons WHERE id = ? FOR UPDATE');
                $stmt->execute([$lessonA->id]);
                $stmt->fetch();

                // Signal Step 2 to Worker (Parent has locked Lesson A)
                touch($parentLockedAFile);

                // Wait until worker signals it is requesting Lesson A (no arbitrary fixed sleeps)
                $deadlineReqA = microtime(true) + 10.0;
                while (! file_exists($workerReqAFile) && microtime(true) < $deadlineReqA) {
                    usleep(10000);
                }
                if (! file_exists($workerReqAFile)) {
                    throw new \RuntimeException('Timed out waiting for worker to request Lesson A');
                }

                // Parent attempts to lock Lesson B (which Worker already holds) -> Deadlock cycle!
                $stmt = $pdo1->prepare('SELECT id FROM lessons WHERE id = ? FOR UPDATE');
                $stmt->execute([$lessonB->id]);
                $stmt->fetch();

                $pdo1->commit();
            } catch (\PDOException $e) {
                $deadlockCaught = true;
                // Verify MySQL Error 1213 / SQLSTATE 40001
                $this->assertStringContainsString('Deadlock found', $e->getMessage());
                $this->assertSame('40001', (string) $e->getCode());
                if (isset($e->errorInfo[1])) {
                    $this->assertSame(1213, (int) $e->errorInfo[1]);
                }
                if ($pdo1->inTransaction()) {
                    $pdo1->rollBack();
                }
            }

            $workerResults = $this->collectWorkerProcesses([
                [
                    'process' => $proc,
                    'stdout' => $pipes[1],
                    'stderr' => $pipes[2],
                ],
            ], 15);

            $workerOut = $workerResults[0]['stdout'];
            $workerErr = $workerResults[0]['stderr'];
            $workerExitCode = $workerResults[0]['exitCode'];
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->terminateWorker($proc);
            $closeExit = proc_close($proc);
            if ($workerExitCode === null || $workerExitCode < 0) {
                $workerExitCode = $closeExit;
            }

            @unlink($workerLockedBFile);
            @unlink($parentLockedAFile);
            @unlink($workerReqAFile);
        }

        $workerRes = json_decode($workerOut, true);
        $workerDeadlocked = isset($workerRes['status']) && $workerRes['status'] === 'pdo_exception' && str_contains($workerRes['message'] ?? '', 'Deadlock');
        if ($workerDeadlocked) {
            $this->assertSame('40001', (string) ($workerRes['sqlstate'] ?? ''));
            if (isset($workerRes['error_info'][1])) {
                $this->assertSame(1213, (int) $workerRes['error_info'][1]);
            }
        }

        // At least one of the two connections must have received error 1213 from MySQL InnoDB
        $this->assertTrue(
            $deadlockCaught || $workerDeadlocked,
            'Real MySQL InnoDB deadlock detection succeeded: either connection 1 or connection 2 received Error 1213 / 40001'
        );

        // --- Part 2: Simulated Retry Classification (validates Laravel DB::transaction deadlock retry logic) ---
        // Note: This is an explicit simulated retry classification test to verify Laravel's transaction deadlock recovery logic, not a retry of the live InnoDB deadlock.
        $retryAttempts = 0;
        $result = DB::transaction(function () use (&$retryAttempts, $lessonA) {
            $retryAttempts++;
            if ($retryAttempts < 3) {
                // Mutate state inside transaction before failing
                DB::table('lessons')->where('id', $lessonA->id)->update(['title' => 'Intermediate_Failed_Title']);
                // Simulate deadlock exception code 40001
                throw new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001);
            }

            DB::table('lessons')->where('id', $lessonA->id)->update(['title' => 'Final_Success_Title']);

            return 'recovered';
        }, 3);

        $this->assertSame('recovered', $result);
        $this->assertSame(3, $retryAttempts);

        $persistedTitle = DB::table('lessons')->where('id', $lessonA->id)->value('title');
        $this->assertSame('Final_Success_Title', $persistedTitle, 'Rollback integrity: failed retry writes were rolled back');

        // --- Part 3: Nested Transaction Savepoint Rollback Integrity ---
        DB::transaction(function () use ($lessonA) {
            DB::table('lessons')->where('id', $lessonA->id)->update(['title' => 'Outer_Title']);

            try {
                DB::transaction(function () use ($lessonA) {
                    DB::table('lessons')->where('id', $lessonA->id)->update(['title' => 'Inner_Will_Rollback']);
                    throw new \RuntimeException('Inner failure');
                });
            } catch (\RuntimeException $e) {
                // Inner failed, rolled back to savepoint
            }

            $currentTitle = DB::table('lessons')->where('id', $lessonA->id)->value('title');
            $this->assertSame('Outer_Title', $currentTitle, 'Savepoint rollback integrity: inner failure rolled back without corrupting outer transaction');
        });
    }
}
