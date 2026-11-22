<?php

namespace Tests\Unit;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Lesson;
use App\Models\LevelTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Fixtures\E2EFixtureManager;
use Tests\TestCase;

class E2EFixtureManagerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: Successful setup creates all required fixture entities and returns expected keys.
     */
    public function test_successful_setup(): void
    {
        Storage::fake('private');

        $fixture = E2EFixtureManager::setup();

        $this->assertArrayHasKey('centerId', $fixture);
        $this->assertArrayHasKey('centerSlug', $fixture);
        $this->assertArrayHasKey('courseId', $fixture);
        $this->assertArrayHasKey('lessonId', $fixture);
        $this->assertArrayHasKey('assetId', $fixture);
        $this->assertArrayHasKey('imageAssetId', $fixture);
        $this->assertArrayHasKey('imageStorageKey', $fixture);
        $this->assertArrayHasKey('pdfAssetId', $fixture);
        $this->assertArrayHasKey('pdfStorageKey', $fixture);
        $this->assertArrayHasKey('exerciseId', $fixture);
        $this->assertArrayHasKey('adminEmail', $fixture);
        $this->assertArrayHasKey('studentEmail', $fixture);

        $this->assertDatabaseHas('centers', ['id' => $fixture['centerId']]);
        $this->assertDatabaseHas('users', ['id' => $fixture['adminUserId']]);
        $this->assertDatabaseHas('users', ['id' => $fixture['studentUserId']]);
        $this->assertDatabaseHas('courses', ['id' => $fixture['courseId']]);
        $this->assertDatabaseHas('lessons', ['id' => $fixture['lessonId']]);
        $this->assertDatabaseHas('exercises', ['id' => $fixture['exerciseId']]);
        $this->assertDatabaseHas('lesson_assets', ['id' => $fixture['imageAssetId']]);
        $this->assertDatabaseHas('lesson_assets', ['id' => $fixture['pdfAssetId']]);

        // Physical file creation verified
        Storage::disk('private')->assertExists($fixture['imageStorageKey']);
        Storage::disk('private')->assertExists($fixture['pdfStorageKey']);

        // Cleanup
        E2EFixtureManager::teardown($fixture['centerId']);
    }

    /**
     * Test 2: Failed setup actually invokes E2EFixtureManager::setup() with injected failure
     * and rolls back all entities atomically.
     */
    public function test_failed_setup_rollback(): void
    {
        Storage::fake('private');

        $initialCenters = DB::table('centers')->count();
        $initialUsers = DB::table('users')->count();
        $initialMemberships = DB::table('center_user')->count();
        $initialCourses = DB::table('courses')->count();
        $initialModules = DB::table('course_modules')->count();
        $initialLessons = DB::table('lessons')->count();
        $initialAssets = DB::table('lesson_assets')->count();
        $initialExercises = DB::table('exercises')->count();

        // Inject a controlled failure during real Lesson creation inside E2EFixtureManager::setup()
        Lesson::saving(function ($lesson) {
            if (str_starts_with($lesson->title, 'E2E Lesson')) {
                throw new \RuntimeException('Controlled failure during Lesson creation');
            }
        });

        try {
            E2EFixtureManager::setup();
            $this->fail('Expected RuntimeException was not thrown by E2EFixtureManager::setup()');
        } catch (\RuntimeException $e) {
            $this->assertSame('Controlled failure during Lesson creation', $e->getMessage());
        } finally {
            // Unregister the model event dispatcher callback to avoid contaminating subsequent tests
            Lesson::flushEventListeners();
        }

        // Verify complete transactional rollback: Every table count remains completely unchanged
        $this->assertSame($initialCenters, DB::table('centers')->count(), 'Centers must roll back');
        $this->assertSame($initialUsers, DB::table('users')->count(), 'Users must roll back');
        $this->assertSame($initialMemberships, DB::table('center_user')->count(), 'Memberships must roll back');
        $this->assertSame($initialCourses, DB::table('courses')->count(), 'Courses must roll back');
        $this->assertSame($initialModules, DB::table('course_modules')->count(), 'Modules must roll back');
        $this->assertSame($initialLessons, DB::table('lessons')->count(), 'Lessons must roll back');
        $this->assertSame($initialAssets, DB::table('lesson_assets')->count(), 'Assets must roll back');
        $this->assertSame($initialExercises, DB::table('exercises')->count(), 'Exercises must roll back');
    }

    /**
     * Test 3: Successful teardown deletes all fixture records in foreign-key order and removes storage files.
     */
    public function test_successful_teardown(): void
    {
        Storage::fake('private');

        $fixture = E2EFixtureManager::setup();
        $centerId = $fixture['centerId'];
        $adminId = $fixture['adminUserId'];
        $studentId = $fixture['studentUserId'];
        $lessonId = $fixture['lessonId'];
        $imageKey = $fixture['imageStorageKey'];
        $pdfKey = $fixture['pdfStorageKey'];

        E2EFixtureManager::teardown($centerId);

        $this->assertDatabaseMissing('centers', ['id' => $centerId]);
        $this->assertDatabaseMissing('users', ['id' => $adminId]);
        $this->assertDatabaseMissing('users', ['id' => $studentId]);
        $this->assertDatabaseMissing('lessons', ['id' => $lessonId]);
        $this->assertDatabaseMissing('courses', ['id' => $fixture['courseId']]);
        $this->assertDatabaseMissing('lesson_assets', ['id' => $fixture['imageAssetId']]);
        $this->assertDatabaseMissing('lesson_assets', ['id' => $fixture['pdfAssetId']]);

        // Physical storage files deleted during teardown
        Storage::disk('private')->assertMissing($imageKey);
        Storage::disk('private')->assertMissing($pdfKey);
    }

    /**
     * Test 4: Teardown transaction failure preserves storage files (files are deleted only after commit).
     */
    public function test_teardown_transaction_failure_preserves_storage_files(): void
    {
        Storage::fake('private');

        $fixture = E2EFixtureManager::setup();
        $centerId = $fixture['centerId'];
        $imageKey = $fixture['imageStorageKey'];
        $pdfKey = $fixture['pdfStorageKey'];

        // Inject failure during Center::delete() inside teardown's DB::transaction
        Center::deleting(function ($center) use ($centerId) {
            if ($center->id === $centerId) {
                throw new \RuntimeException('Simulated database error during teardown transaction');
            }
        });

        try {
            E2EFixtureManager::teardown($centerId);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated database error during teardown transaction', $e->getMessage());
        } finally {
            Center::flushEventListeners();
        }

        // Storage files must still exist because DB transaction rolled back before file deletion
        Storage::disk('private')->assertExists($imageKey);
        Storage::disk('private')->assertExists($pdfKey);

        // Subsequent clean teardown deletes them successfully
        E2EFixtureManager::teardown($centerId);
        Storage::disk('private')->assertMissing($imageKey);
        Storage::disk('private')->assertMissing($pdfKey);
    }

    /**
     * Test 5: Global level_templates are preserved and unchanged across fixture setup and teardown.
     */
    public function test_global_level_templates_preserved_across_setup_and_teardown(): void
    {
        // Ensure baseline global LevelTemplates exist
        $template1 = LevelTemplate::firstOrCreate(
            ['code' => 'TEST_A1'],
            ['name' => 'Template A1', 'sort_order' => 1, 'is_active' => true]
        );
        $template2 = LevelTemplate::firstOrCreate(
            ['code' => 'TEST_B1'],
            ['name' => 'Template B1', 'sort_order' => 2, 'is_active' => true]
        );

        $initialTemplateIds = LevelTemplate::query()->orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($initialTemplateIds);

        // Run fixture setup and teardown
        $fixture = E2EFixtureManager::setup();
        $this->assertDatabaseHas('centers', ['id' => $fixture['centerId']]);

        E2EFixtureManager::teardown($fixture['centerId']);
        $this->assertDatabaseMissing('centers', ['id' => $fixture['centerId']]);

        // Verify that global level templates are 100% unchanged
        $afterTemplateIds = LevelTemplate::query()->orderBy('id')->pluck('id')->all();
        $this->assertSame(
            $initialTemplateIds,
            $afterTemplateIds,
            'Global level_templates reference records must remain completely intact after fixture teardown.'
        );
    }

    /**
     * Test 5: Repeated teardown is idempotent.
     */
    public function test_repeated_teardown_is_idempotent(): void
    {
        $fixture = E2EFixtureManager::setup();
        $centerId = $fixture['centerId'];

        // First teardown
        E2EFixtureManager::teardown($centerId);
        $this->assertDatabaseMissing('centers', ['id' => $centerId]);

        // Second teardown on already deleted center must succeed without throwing
        E2EFixtureManager::teardown($centerId);
        $this->assertTrue(true, 'Repeated teardown completed idempotently.');
    }

    /**
     * Test 6: Rejection of real / ordinary center ID.
     */
    public function test_rejection_of_real_center_id(): void
    {
        // Create an ordinary center (NOT an E2E fixture center)
        $realCenter = Center::create([
            'name' => 'Real Production Center',
            'slug' => 'real-production-center-123',
            'status' => 'active',
            'timezone' => 'UTC',
            'default_locale' => 'en',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SAFETY VIOLATION');

        try {
            E2EFixtureManager::teardown($realCenter->id);
        } finally {
            // Assert that the real center was NOT deleted
            $this->assertDatabaseHas('centers', ['id' => $realCenter->id]);
        }
    }

    /**
     * Test 7: Preservation of unrelated users and centers.
     */
    public function test_preservation_of_unrelated_users_and_centers(): void
    {
        // Create an unrelated center and an unrelated user
        $unrelatedCenter = Center::create([
            'name' => 'Unrelated Academy',
            'slug' => 'unrelated-academy-456',
            'status' => 'active',
            'timezone' => 'UTC',
            'default_locale' => 'en',
        ]);

        $unrelatedUser = User::create([
            'name' => 'Unrelated Teacher',
            'email' => 'teacher@unrelated.example',
            'password' => 'Secret123!',
            'status' => User::STATUS_ACTIVE,
            'token_version' => 1,
        ]);

        CenterUser::create([
            'center_id' => $unrelatedCenter->id,
            'user_id' => $unrelatedUser->id,
            'status' => 'active',
        ]);

        // Now setup and teardown an E2E fixture
        $fixture = E2EFixtureManager::setup();

        // Also add the unrelated user to the fixture center to test multi-center membership protection
        CenterUser::create([
            'center_id' => $fixture['centerId'],
            'user_id' => $unrelatedUser->id,
            'status' => 'active',
        ]);

        // Teardown the fixture
        E2EFixtureManager::teardown($fixture['centerId']);

        // Assert that the unrelated center and user still exist!
        $this->assertDatabaseHas('centers', ['id' => $unrelatedCenter->id]);
        $this->assertDatabaseHas('users', ['id' => $unrelatedUser->id]);
        $this->assertDatabaseHas('center_user', [
            'center_id' => $unrelatedCenter->id,
            'user_id' => $unrelatedUser->id,
        ]);
    }
}
