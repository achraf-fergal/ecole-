<?php

namespace Tests\Fixtures;

require_once __DIR__.'/../../vendor/autoload.php';

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class E2EFixtureManager
{
    public const FIXTURE_NAME_PREFIX = 'E2E Acceptance Center ';
    public const FIXTURE_SLUG_PREFIX = 'center-e2e_';

    public static function bootstrap(): void
    {
        if (! function_exists('app') || ! app()->bound(Kernel::class)) {
            $app = require __DIR__.'/../../bootstrap/app.php';
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();
        }
    }

    /**
     * Create an isolated E2E test fixture inside a single transaction.
     * Records all created IDs and rolls back atomically on any failure.
     */
    public static function setup(): array
    {
        self::bootstrap();

        return DB::transaction(function (): array {
            $uniq = 'e2e_'.Str::lower(Str::random(10));

            // 1. Create Center with unmistakable E2E prefix
            $center = Center::create([
                'name' => self::FIXTURE_NAME_PREFIX.$uniq,
                'slug' => self::FIXTURE_SLUG_PREFIX.$uniq,
                'status' => 'active',
                'timezone' => 'UTC',
                'default_locale' => 'en',
            ]);

            // 2. Create Admin User with unmistakable E2E email
            $adminPassword = 'Password123!@#';
            $admin = User::create([
                'name' => 'E2E Admin',
                'email' => 'admin_'.$uniq.'@example.test',
                'password' => $adminPassword,
                'status' => User::STATUS_ACTIVE,
                'token_version' => 1,
            ]);
            $admin->email_verified_at = now();
            $admin->save();

            $adminMembership = CenterUser::create([
                'center_id' => $center->id,
                'user_id' => $admin->id,
                'status' => 'active',
            ]);

            $adminRole = Role::query()->where('key', 'center_admin')->first();
            if (! $adminRole) {
                $adminRole = Role::firstOrCreate(['key' => 'center_admin'], ['name' => 'Center Admin']);
            }
            $adminMembership->roles()->syncWithoutDetaching([$adminRole->id]);

            // 3. Create Student User with unmistakable E2E email
            $studentPassword = 'Password123!@#';
            $student = User::create([
                'name' => 'E2E Student',
                'email' => 'student_'.$uniq.'@example.test',
                'password' => $studentPassword,
                'status' => User::STATUS_ACTIVE,
                'token_version' => 1,
            ]);
            $student->email_verified_at = now();
            $student->save();

            $studentMembership = CenterUser::create([
                'center_id' => $center->id,
                'user_id' => $student->id,
                'status' => 'active',
            ]);

            $studentRole = Role::query()->where('key', 'student')->first();
            if (! $studentRole) {
                $studentRole = Role::firstOrCreate(['key' => 'student'], ['name' => 'Student']);
            }
            $studentMembership->roles()->syncWithoutDetaching([$studentRole->id]);

            $studentProfile = StudentProfile::factory()->forMembership($studentMembership)->create([
                'status' => StudentProfile::STATUS_ACTIVE,
            ]);

            // 4. Create Course, Module, Lesson
            $course = Course::factory()
                ->forCenter($center)
                ->published()
                ->create([
                    'title' => 'E2E Course '.$uniq,
                    'slug' => 'course-'.$uniq,
                    'status' => 'published',
                ]);

            $module = CourseModule::factory()
                ->forCourse($course)
                ->published()
                ->create([
                    'title' => 'Module 1',
                    'sort_order' => 1,
                    'status' => CourseModule::STATUS_PUBLISHED,
                ]);

            $lesson = Lesson::factory()
                ->forModule($module)
                ->create([
                    'title' => 'E2E Lesson '.$uniq,
                    'content_locale' => 'en',
                    'status' => Lesson::STATUS_DRAFT,
                ]);

            // 5. Create Cohort & Student Enrollment
            $cohort = Cohort::factory()->forCourse($course)->inProgress()->create([
                'center_id' => $center->id,
                'name' => 'Cohort '.$uniq,
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addMonths(2),
                'status' => Cohort::STATUS_OPEN,
            ]);

            $enrollment = Enrollment::factory()
                ->forStudentProfile($studentProfile)
                ->forCohort($cohort)
                ->create([
                    'center_id' => $center->id,
                    'start_at' => now()->subDay(),
                    'end_at' => now()->addMonth(),
                    'status' => 'active',
                ]);

            // 6. Pre-create active LessonAssets (Image and PDF) with real files in isolated storage
            $imageKey = 'e2e_fixtures/centers/'.$center->id.'/diagram.png';
            $imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            Storage::disk('private')->put($imageKey, $imageBytes);

            $imageAsset = LessonAsset::create([
                'center_id' => $center->id,
                'lesson_id' => $lesson->id,
                'title' => 'E2E Diagram Asset',
                'asset_type' => LessonAsset::TYPE_IMAGE,
                'storage_disk' => 'private',
                'storage_key' => $imageKey,
                'original_filename' => 'diagram.png',
                'mime_type' => 'image/png',
                'size_bytes' => strlen($imageBytes),
                'status' => LessonAsset::STATUS_ACTIVE,
            ]);

            $pdfKey = 'e2e_fixtures/centers/'.$center->id.'/handout.pdf';
            $pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000108 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";
            Storage::disk('private')->put($pdfKey, $pdfBytes);

            $pdfAsset = LessonAsset::create([
                'center_id' => $center->id,
                'lesson_id' => $lesson->id,
                'title' => 'E2E Study Guide PDF',
                'asset_type' => LessonAsset::TYPE_FILE,
                'storage_disk' => 'private',
                'storage_key' => $pdfKey,
                'original_filename' => 'handout.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($pdfBytes),
                'status' => LessonAsset::STATUS_ACTIVE,
            ]);

            // 7. Pre-create an active Exercise for exercise block testing
            $exercise = Exercise::create([
                'center_id' => $center->id,
                'lesson_id' => $lesson->id,
                'title' => 'E2E Assessment Quiz',
                'instructions' => 'Choose the correct answer.',
                'status' => Exercise::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $question = Question::create([
                'center_id' => $center->id,
                'exercise_id' => $exercise->id,
                'question_type' => Question::TYPE_SINGLE_CHOICE,
                'prompt' => 'Is this E2E test running against isolated fixtures?',
                'points' => 10,
                'sort_order' => 1,
            ]);

            $opt1 = QuestionOption::create([
                'center_id' => $center->id,
                'question_id' => $question->id,
                'option_text' => 'Yes, completely isolated',
                'is_correct' => true,
                'sort_order' => 1,
            ]);

            $opt2 = QuestionOption::create([
                'center_id' => $center->id,
                'question_id' => $question->id,
                'option_text' => 'No',
                'is_correct' => false,
                'sort_order' => 2,
            ]);

            return [
                'uniq' => $uniq,
                'centerId' => $center->id,
                'centerSlug' => $center->slug,
                'courseId' => $course->id,
                'courseSlug' => $course->slug,
                'moduleId' => $module->id,
                'lessonId' => $lesson->id,
                'cohortId' => $cohort->id,
                'enrollmentId' => $enrollment->id,
                'assetId' => $imageAsset->id,
                'imageAssetId' => $imageAsset->id,
                'imageStorageKey' => $imageKey,
                'imageDisk' => 'private',
                'pdfAssetId' => $pdfAsset->id,
                'pdfStorageKey' => $pdfKey,
                'pdfDisk' => 'private',
                'exerciseId' => $exercise->id,
                'questionId' => $question->id,
                'optionIds' => [$opt1->id, $opt2->id],
                'adminUserId' => $admin->id,
                'adminEmail' => $admin->email,
                'adminPassword' => $adminPassword,
                'studentUserId' => $student->id,
                'studentEmail' => $student->email,
                'studentPassword' => $studentPassword,
            ];
        });
    }

    /**
     * Safely teardown an E2E fixture.
     * Strictly restricted to centers created by this manager.
     * Deletes in correct foreign-key order and ensures idempotence.
     */
    public static function teardown(int $centerId): void
    {
        self::bootstrap();
    
        $center = Center::query()
            ->whereKey($centerId)
            ->first();
    
        if (! $center) {
            return;
        }
    
        self::assertFixtureCenter($center);
    
        $files = DB::transaction(function () use ($center) {
            $lockedCenter = Center::query()
                ->whereKey($center->id)
                ->lockForUpdate()
                ->firstOrFail();
    
            self::assertFixtureCenter($lockedCenter);
    
            $assets = DB::table('lesson_assets')
                ->where('center_id', $lockedCenter->id)
                ->get(['storage_disk', 'storage_key']);
    
            // Register a durable cleanup manifest here.
            // Do not delete asset records or center yet.
    
            return $assets;
        });
    
        foreach ($files as $file) {
            if (! $file->storage_disk || ! $file->storage_key) {
                continue;
            }
    
            $disk = Storage::disk($file->storage_disk);
    
            if ($disk->exists($file->storage_key)) {
                if (! $disk->delete($file->storage_key)) {
                    throw new RuntimeException(
                        "Failed to delete fixture asset: {$file->storage_key}"
                    );
                }
            }
        }
    
        DB::transaction(function () use ($centerId) {
            $lockedCenter = Center::query()
                ->whereKey($centerId)
                ->lockForUpdate()
                ->first();
    
            if (! $lockedCenter) {
                return;
            }
    
            self::assertFixtureCenter($lockedCenter);
    
            // Execute the existing FK-safe database cleanup here.
            self::deleteFixtureRecords($lockedCenter);
        });
    }
}

// CLI handler for setup/teardown
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    if ($argv[1] === 'setup') {
        $result = E2EFixtureManager::setup();
        echo json_encode($result);
        exit(0);
    } elseif ($argv[1] === 'teardown' && isset($argv[2])) {
        E2EFixtureManager::teardown((int) $argv[2]);
        echo json_encode(['status' => 'cleaned', 'centerId' => (int) $argv[2]]);
        exit(0);
    }
}
