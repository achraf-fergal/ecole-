<?php

namespace Tests\Unit;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Exercise;
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
        $this->assertArrayHasKey('correctOptionId', $fixture);

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
     * 
     * 
     * and rolls back all entities atomically.

    */
    
    
    
    public function test_failed_setup_rollback(): void
    {
        Storage::fake('private');

        $tables = [
            'centers',
            'users',
            'center_user',
            'courses',
            'course_modules',
            'lessons',
            'lesson_assets',
            'exercises',
            'questions',
            'question_options',
        ];

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $originalDispatcher = Lesson::getEventDispatcher();

        Lesson::setEventDispatcher(
            clone $originalDispatcher
        );

        try {
            Lesson::saving(function (Lesson $lesson): void {
                if (
                    str_starts_with(
                        (string) $lesson->title,
                        'E2E Lesson'
                    )
                ) {
                    throw new \RuntimeException(
                        'Controlled failure during Lesson creation.'
                    );
                }
            });

            try {
                E2EFixtureManager::setup();

                $this->fail(
                    'Expected E2EFixtureManager::setup() to fail.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Controlled failure during Lesson creation.',
                    $exception->getMessage()
                );
            }
        } finally {
            Lesson::setEventDispatcher(
                $originalDispatcher
            );
        }

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "Table {$table} must be unchanged after failed fixture setup."
            );
        }

        $this->assertSame(
            [],
            Storage::disk('private')->allFiles('e2e_fixtures/centers')
        );
    }

    /**
     * Test 3: Failed setup removes physical storage files if failure happens after files are created.
     */
    public function test_failed_setup_removes_storage_files_after_late_failure(): void
    {
        Storage::fake('private');

        $tables = [
            'centers',
            'users',
            'center_user',
            'courses',
            'course_modules',
            'lessons',
            'lesson_assets',
            'exercises',
            'questions',
            'question_options',
        ];

        $beforeCounts = [];

        foreach ($tables as $table) {
            $beforeCounts[$table] = DB::table($table)->count();
        }

        $beforeFiles = Storage::disk('private')
            ->allFiles('e2e_fixtures/centers');

        $originalDispatcher = Exercise::getEventDispatcher();

        Exercise::setEventDispatcher(
            clone $originalDispatcher
        );

        try {
            Exercise::creating(function (Exercise $exercise): void {
                if ($exercise->title === 'E2E Assessment Quiz') {
                    throw new \RuntimeException(
                        'Injected failure after storage creation.'
                    );
                }
            });

            try {
                E2EFixtureManager::setup();

                $this->fail(
                    'Expected setup to fail after physical storage creation.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Injected failure after storage creation.',
                    $exception->getMessage()
                );
            }
        } finally {
            Exercise::setEventDispatcher(
                $originalDispatcher
            );
        }

        foreach ($tables as $table) {
            $this->assertSame(
                $beforeCounts[$table],
                DB::table($table)->count(),
                "Database table {$table} must roll back completely."
            );
        }

        $afterFiles = Storage::disk('private')
            ->allFiles('e2e_fixtures/centers');

        $this->assertSame(
            $beforeFiles,
            $afterFiles,
            'Failed fixture setup must remove its physical storage files.'
        );
    }
    public function test_setup_generates_pdf_with_correct_xref_offsets(): void
    {
        Storage::fake('private');
    
        $fixture = E2EFixtureManager::setup();
    
        try {
            $pdf = Storage::disk('private')->get(
                $fixture['pdfStorageKey']
            );
    
            $this->assertIsString($pdf);
    
            $this->assertStringStartsWith(
                "%PDF-1.4\n",
                $pdf
            );
    
            // Read the XRef offset from startxref.
            $this->assertSame(
                1,
                preg_match(
                    '/startxref\n(\d+)\n%%EOF/s',
                    $pdf,
                    $startxrefMatches
                )
            );
    
            $xrefOffset = (int) $startxrefMatches[1];
    
            $this->assertSame(
                "xref",
                substr($pdf, $xrefOffset, 4),
                'startxref must point to the actual XRef section.'
            );
    
            // Extract all XRef entries.
            $this->assertSame(
                1,
                preg_match(
                    '/xref\n0 4\n(.*?)trailer\n/s',
                    $pdf,
                    $xrefMatches
                )
            );
    
            $entries = preg_split(
                '/\r\n|\n|\r/',
                trim($xrefMatches[1])
            );
    
            $this->assertCount(4, $entries);
    
            // Object 0 must be the free object.
            $this->assertStringContainsString(
                '65535 f',
                $entries[0]
            );
    
            // Verify that every XRef entry points to its real object.
            for ($objectNumber = 1; $objectNumber <= 3; $objectNumber++) {
                $offset = (int) substr(
                    $entries[$objectNumber],
                    0,
                    10
                );
    
                $expectedHeader = $objectNumber.' 0 obj';
    
                $actualHeader = substr(
                    $pdf,
                    $offset,
                    strlen($expectedHeader)
                );
    
                $this->assertSame(
                    $expectedHeader,
                    $actualHeader,
                    "Incorrect XRef offset for PDF object {$objectNumber}."
                );
            }
    
            $this->assertGreaterThan(
                100,
                strlen($pdf)
            );
        } finally {
            E2EFixtureManager::teardown(
                $fixture['centerId']
            );
        }
    }
    /**
     * Test 4: Successful teardown deletes all fixture records in foreign-key order and removes storage files.
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
     * Test 5: Teardown transaction failure preserves storage files (files are deleted only after commit).
     */
    public function test_teardown_transaction_failure_preserves_storage_files(): void
    {
        Storage::fake('private');

        $fixture = E2EFixtureManager::setup();

        $centerId = $fixture['centerId'];
        $imageKey = $fixture['imageStorageKey'];
        $pdfKey = $fixture['pdfStorageKey'];

        $originalDispatcher = Center::getEventDispatcher();

        Center::setEventDispatcher(
            clone $originalDispatcher
        );

        try {
            Center::deleting(function ($center) use ($centerId): void {
                if ((int) $center->id === (int) $centerId) {
                    throw new \RuntimeException(
                        'Simulated database error during teardown transaction.'
                    );
                }
            });

            try {
                E2EFixtureManager::teardown($centerId);

                $this->fail(
                    'Expected teardown transaction failure.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Simulated database error during teardown transaction.',
                    $exception->getMessage()
                );
            }
        } finally {
            Center::setEventDispatcher(
                $originalDispatcher
            );
        }

        // Database transaction must have rolled back.
        $this->assertDatabaseHas('centers', [
            'id' => $centerId,
        ]);

        $this->assertDatabaseHas('lesson_assets', [
            'id' => $fixture['imageAssetId'],
        ]);

        // Files must still exist because DB deletion failed.
        Storage::disk('private')->assertExists($imageKey);
        Storage::disk('private')->assertExists($pdfKey);

        // Retry must now work.
        E2EFixtureManager::teardown($centerId);

        $this->assertDatabaseMissing('centers', [
            'id' => $centerId,
        ]);

        Storage::disk('private')->assertMissing($imageKey);
        Storage::disk('private')->assertMissing($pdfKey);

        // A further retry must also be safe.
        E2EFixtureManager::teardown($centerId);
    }

    /**
     * Test 6: Global level_templates are preserved and unchanged across fixture setup and teardown.
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
     * Test 7: Repeated teardown is idempotent.
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
     * Test 8: Rejection of real / ordinary center ID.
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
     * Test 9: Preservation of unrelated users and centers.
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
