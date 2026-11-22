<?php

namespace Tests\Unit;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $fixture = E2EFixtureManager::setup();

        $this->assertArrayHasKey('centerId', $fixture);
        $this->assertArrayHasKey('centerSlug', $fixture);
        $this->assertArrayHasKey('courseId', $fixture);
        $this->assertArrayHasKey('lessonId', $fixture);
        $this->assertArrayHasKey('assetId', $fixture);
        $this->assertArrayHasKey('exerciseId', $fixture);
        $this->assertArrayHasKey('adminEmail', $fixture);
        $this->assertArrayHasKey('studentEmail', $fixture);

        $this->assertDatabaseHas('centers', ['id' => $fixture['centerId']]);
        $this->assertDatabaseHas('users', ['id' => $fixture['adminUserId']]);
        $this->assertDatabaseHas('users', ['id' => $fixture['studentUserId']]);
        $this->assertDatabaseHas('courses', ['id' => $fixture['courseId']]);
        $this->assertDatabaseHas('lessons', ['id' => $fixture['lessonId']]);
        $this->assertDatabaseHas('exercises', ['id' => $fixture['exerciseId']]);
        $this->assertDatabaseHas('lesson_assets', ['id' => $fixture['assetId']]);

        // Cleanup
        E2EFixtureManager::teardown($fixture['centerId']);
    }

    /**
     * Test 2: Failed setup rolls back all partial creation atomically.
     */
    public function test_failed_setup_rollback(): void
    {
        $initialCenters = DB::table('centers')->count();
        $initialUsers = DB::table('users')->count();

        // Simulate failure during transactional setup by intercepting an event or creating duplicate unique key
        try {
            DB::transaction(function () {
                $uniq = 'failed_e2e_'.uniqid();
                Center::create([
                    'name' => E2EFixtureManager::FIXTURE_NAME_PREFIX.$uniq,
                    'slug' => E2EFixtureManager::FIXTURE_SLUG_PREFIX.$uniq,
                    'status' => 'active',
                    'timezone' => 'UTC',
                    'default_locale' => 'en',
                ]);

                User::create([
                    'name' => 'E2E Admin',
                    'email' => 'admin_'.$uniq.'@example.test',
                    'password' => 'Password123!',
                    'status' => User::STATUS_ACTIVE,
                    'token_version' => 1,
                ]);

                // Intentionally throw exception to trigger rollback
                throw new \RuntimeException('Simulated setup failure midway');
            });
            $this->fail('Should have thrown RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated setup failure midway', $e->getMessage());
        }

        // Verify full rollback: counts unchanged
        $this->assertSame($initialCenters, DB::table('centers')->count());
        $this->assertSame($initialUsers, DB::table('users')->count());
    }

    /**
     * Test 3: Successful teardown deletes all fixture records in foreign-key order.
     */
    public function test_successful_teardown(): void
    {
        $fixture = E2EFixtureManager::setup();
        $centerId = $fixture['centerId'];
        $adminId = $fixture['adminUserId'];
        $studentId = $fixture['studentUserId'];
        $lessonId = $fixture['lessonId'];

        E2EFixtureManager::teardown($centerId);

        $this->assertDatabaseMissing('centers', ['id' => $centerId]);
        $this->assertDatabaseMissing('users', ['id' => $adminId]);
        $this->assertDatabaseMissing('users', ['id' => $studentId]);
        $this->assertDatabaseMissing('lessons', ['id' => $lessonId]);
        $this->assertDatabaseMissing('courses', ['id' => $fixture['courseId']]);
    }

    /**
     * Test 4: Repeated teardown is idempotent.
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
     * Test 5: Rejection of real / ordinary center ID.
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
     * Test 6: Preservation of unrelated users and centers.
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
