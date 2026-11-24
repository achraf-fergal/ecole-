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

    private static function buildPdfFixture(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);

            $objectNumber = $index + 1;

            $pdf .= $objectNumber." 0 obj\n";
            $pdf .= $object."\n";
            $pdf .= "endobj\n";
        }

        // XRef must point to its actual byte position.
        $xrefOffset = strlen($pdf);

        $objectCount = count($objects);

        $pdf .= "xref\n";
        $pdf .= '0 '.($objectCount + 1)."\n";

        // Free object 0.
        $pdf .= "0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf(
                "%010d 00000 n \n",
                $offset
            );
        }

        $pdf .= "trailer\n";
        $pdf .= '<< /Size '.($objectCount + 1)." /Root 1 0 R >>\n";

        $pdf .= "startxref\n";
        $pdf .= $xrefOffset."\n";
        $pdf .= "%%EOF\n";

        return $pdf;
    }

    public static function setup(): array
    {
        self::bootstrap();

        $createdStorageFiles = [];

        try {
            return DB::transaction(function () use (
                &$createdStorageFiles
            ): array {
                $uniq = 'e2e_'.Str::lower(Str::random(10));

                // 1. Create Center
                $center = Center::create([
                    'name' => self::FIXTURE_NAME_PREFIX.$uniq,
                    'slug' => self::FIXTURE_SLUG_PREFIX.$uniq,
                    'status' => 'active',
                    'timezone' => 'UTC',
                    'default_locale' => 'en',
                ]);

                // 2. Create Admin
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

                $adminRole = Role::query()
                    ->where('key', 'center_admin')
                    ->first();

                if (! $adminRole) {
                    $adminRole = Role::firstOrCreate(
                        ['key' => 'center_admin'],
                        ['name' => 'Center Admin']
                    );
                }

                $adminMembership->roles()
                    ->syncWithoutDetaching([$adminRole->id]);

                // 3. Create Student
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

                $studentRole = Role::query()
                    ->where('key', 'student')
                    ->first();

                if (! $studentRole) {
                    $studentRole = Role::firstOrCreate(
                        ['key' => 'student'],
                        ['name' => 'Student']
                    );
                }

                $studentMembership->roles()
                    ->syncWithoutDetaching([$studentRole->id]);

                $studentProfile = StudentProfile::factory()
                    ->forMembership($studentMembership)
                    ->create([
                        'status' => StudentProfile::STATUS_ACTIVE,
                    ]);

                // 4. Create Course, Module and Lesson
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

                // 5. Cohort and Enrollment
                $cohort = Cohort::factory()
                    ->forCourse($course)
                    ->inProgress()
                    ->create([
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

                // 6. Image fixture
                $storageRoot = 'e2e_fixtures/centers/'.$center->id;

                $imageKey = $storageRoot.'/diagram.png';

                $imageBytes = base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4////fwAJ+wP9KobjigAAAABJRU5ErkJggg==',
                    true
                );

                if ($imageBytes === false) {
                    throw new \RuntimeException(
                        'Failed to decode E2E image fixture.'
                    );
                }

                // Register before writing so partial writes can be compensated.
                $createdStorageFiles[] = [
                    'disk' => 'private',
                    'key' => $imageKey,
                ];

                if (! Storage::disk('private')->put($imageKey, $imageBytes)) {
                    throw new \RuntimeException(
                        'Failed to write E2E image fixture.'
                    );
                }

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

                // 7. PDF fixture
                // 7. PDF fixture
                $pdfKey = $storageRoot.'/handout.pdf';

                // Generate a valid PDF with calculated XRef offsets.
                $pdfBytes = self::buildPdfFixture();

                $createdStorageFiles[] = [
                    'disk' => 'private',
                    'key' => $pdfKey,
                ];

                if (! Storage::disk('private')->put($pdfKey, $pdfBytes)) {
                    throw new \RuntimeException(
                        'Failed to write E2E PDF fixture.'
                    );
                }

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
                    'is_downloadable' => true,
                    'status' => LessonAsset::STATUS_ACTIVE,
                ]);

                // 8. Exercise
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

                    // Explicit values for reliable browser assertions.
                    'correctOptionId' => $opt1->id,
                    'incorrectOptionId' => $opt2->id,

                    'adminUserId' => $admin->id,
                    'adminEmail' => $admin->email,
                    'adminPassword' => $adminPassword,

                    'studentUserId' => $student->id,
                    'studentEmail' => $student->email,
                    'studentPassword' => $studentPassword,
                ];
            });
        } catch (\Throwable $setupError) {
            // Database transaction has already rolled back.
            // Compensate external Storage writes explicitly.
            $cleanupFailures = [];

            foreach (array_reverse($createdStorageFiles) as $file) {
                try {
                    $disk = Storage::disk($file['disk']);

                    if (
                        $disk->exists($file['key']) &&
                        ! $disk->delete($file['key'])
                    ) {
                        $cleanupFailures[] = $file['key'];
                    }

                    if ($disk->exists($file['key'])) {
                        $cleanupFailures[] = $file['key'];
                    }
                } catch (\Throwable $cleanupError) {
                    $cleanupFailures[] = $file['key'];
                }
            }

            if ($cleanupFailures !== []) {
                throw new \RuntimeException(
                    'E2E setup failed and Storage compensation was incomplete: '
                    .implode(', ', array_unique($cleanupFailures)),
                    0,
                    $setupError
                );
            }

            throw $setupError;
        }
    }

    public static function teardown(int $centerId): void
    {
        self::bootstrap();

        if ($centerId <= 0) {
            throw new InvalidArgumentException(
                'Invalid E2E fixture center ID.'
            );
        }

        $storageRoot = 'e2e_fixtures/centers/'.$centerId;

        $center = Center::query()
            ->whereKey($centerId)
            ->first();

        if ($center) {
            self::assertFixtureCenter($center);

            DB::transaction(function () use (
                $centerId,
                $storageRoot
            ): void {
                $lockedCenter = Center::query()
                    ->whereKey($centerId)
                    ->lockForUpdate()
                    ->firstOrFail();

                self::assertFixtureCenter($lockedCenter);

                // Validate every stored asset before removing database rows.
                $assets = DB::table('lesson_assets')
                    ->where('center_id', $centerId)
                    ->get([
                        'storage_disk',
                        'storage_key',
                    ]);

                foreach ($assets as $asset) {
                    if (
                        empty($asset->storage_disk) ||
                        empty($asset->storage_key)
                    ) {
                        continue;
                    }

                    $expectedPrefix = $storageRoot.'/';

                    if (
                        $asset->storage_disk !== 'private' ||
                        ! str_starts_with(
                            $asset->storage_key,
                            $expectedPrefix
                        )
                    ) {
                        throw new \RuntimeException(
                            'Unsafe Storage reference found in E2E fixture. '
                            .'Cleanup was rejected.'
                        );
                    }
                }

                // Database cleanup only.
                // Physical files are intentionally preserved until commit.
                self::deleteFixtureRecords($lockedCenter);
            });
        }

        // This step also handles retries when the database center
        // was removed by an earlier teardown, but file deletion failed.
        $disk = Storage::disk('private');

        $disk->deleteDirectory($storageRoot);

        $remainingFiles = $disk->allFiles($storageRoot);

        if ($remainingFiles !== []) {
            throw new \RuntimeException(
                'E2E Storage cleanup incomplete. Remaining files: '
                .implode(', ', $remainingFiles)
            );
        }

        // Verify database cleanup.
        $remainingAssets = DB::table('lesson_assets')
            ->where('center_id', $centerId)
            ->count();

        $remainingLessons = DB::table('lessons')
            ->where('center_id', $centerId)
            ->count();

        $remainingCenters = DB::table('centers')
            ->where('id', $centerId)
            ->count();

        if (
            $remainingAssets !== 0 ||
            $remainingLessons !== 0 ||
            $remainingCenters !== 0
        ) {
            throw new \RuntimeException(
                "E2E database cleanup incomplete for center {$centerId}."
            );
        }
    }

    /**
     * Strictly verify E2E fixture ownership.
     *
     * A center must have:
     * - The exact E2E name prefix.
     * - The exact E2E slug prefix.
     * - The same generated token in both values.
     */
    private static function assertFixtureCenter(Center $center): void
    {
        $name = (string) $center->name;
        $slug = (string) $center->slug;

        if (
            ! str_starts_with($name, self::FIXTURE_NAME_PREFIX) ||
            ! str_starts_with($slug, self::FIXTURE_SLUG_PREFIX)
        ) {
            throw new InvalidArgumentException(
                "SAFETY VIOLATION: Center {$center->id} is not an E2E fixture."
            );
        }

        $nameToken = substr(
            $name,
            strlen(self::FIXTURE_NAME_PREFIX)
        );

        $slugToken = substr(
            $slug,
            strlen(self::FIXTURE_SLUG_PREFIX)
        );

        if (
            $nameToken === '' ||
            $slugToken === '' ||
            $nameToken !== $slugToken ||
            ! preg_match('/\Ae2e_[a-z0-9]+\z/i', $slugToken)
        ) {
            throw new InvalidArgumentException(
                "SAFETY VIOLATION: Center {$center->id} has an invalid fixture identity."
            );
        }
    }

    /**
     * Delete E2E database records in foreign-key-safe order.
     *
     * This method must run inside the caller's DB transaction.
     */
    private static function deleteFixtureRecords(Center $center): void
    {
        $centerId = $center->id;

        // 1. Assessment answers and attempts.
        DB::table('exercise_attempt_answers')
            ->where('center_id', $centerId)
            ->delete();

        DB::table('exercise_attempts')
            ->where('center_id', $centerId)
            ->delete();

        // 2. Student lesson progress.
        DB::table('lesson_progresses')
            ->where('center_id', $centerId)
            ->delete();

        // 3. Cohorts and enrollments.
        $cohortIds = DB::table('cohorts')
            ->where('center_id', $centerId)
            ->pluck('id');

        if ($cohortIds->isNotEmpty()) {
            DB::table('cohort_schedules')
                ->whereIn('cohort_id', $cohortIds)
                ->delete();
        }

        DB::table('enrollments')
            ->where('center_id', $centerId)
            ->delete();

        DB::table('cohorts')
            ->where('center_id', $centerId)
            ->delete();

        DB::table('student_profiles')
            ->where('center_id', $centerId)
            ->delete();

        // 4. Clear circular revision pointers.
        DB::table('lessons')
            ->where('center_id', $centerId)
            ->update([
                'draft_revision_id' => null,
                'published_revision_id' => null,
            ]);

        // 5. Content blocks and revisions.
        DB::table('lesson_blocks')
            ->where('center_id', $centerId)
            ->delete();

        DB::table('lesson_revisions')
            ->where('center_id', $centerId)
            ->delete();

        // Only DB rows are deleted here.
        // Actual files are removed after the database commit.
        DB::table('lesson_assets')
            ->where('center_id', $centerId)
            ->delete();

        // 6. Exercises, questions and options.
        $exerciseIds = DB::table('exercises')
            ->where('center_id', $centerId)
            ->pluck('id');

        if ($exerciseIds->isNotEmpty()) {
            $questionIds = DB::table('questions')
                ->whereIn('exercise_id', $exerciseIds)
                ->pluck('id');

            if ($questionIds->isNotEmpty()) {
                DB::table('question_options')
                    ->whereIn('question_id', $questionIds)
                    ->delete();

                DB::table('questions')
                    ->whereIn('id', $questionIds)
                    ->delete();
            }

            DB::table('exercises')
                ->where('center_id', $centerId)
                ->delete();
        }

        // 7. Lessons and course structure.
        DB::table('lessons')
            ->where('center_id', $centerId)
            ->delete();

        DB::table('course_modules')
            ->where('center_id', $centerId)
            ->delete();

        $courseIds = DB::table('courses')
            ->where('center_id', $centerId)
            ->pluck('id');

        if ($courseIds->isNotEmpty()) {
            DB::table('course_outcomes')
                ->whereIn('course_id', $courseIds)
                ->delete();

            DB::table('course_translations')
                ->whereIn('course_id', $courseIds)
                ->delete();
        }

        DB::table('courses')
            ->where('center_id', $centerId)
            ->delete();

        DB::table('languages')
            ->where('center_id', $centerId)
            ->delete();

        DB::table('levels')
            ->where('center_id', $centerId)
            ->delete();

        // Global level_templates are deliberately preserved.

        // 8. Memberships and fixture users.
        $memberships = DB::table('center_user')
            ->where('center_id', $centerId)
            ->get();

        $userIds = $memberships
            ->pluck('user_id')
            ->all();

        $membershipIds = $memberships
            ->pluck('id')
            ->all();

        if ($membershipIds !== []) {
            DB::table('center_user_role')
                ->whereIn('center_user_id', $membershipIds)
                ->delete();

            DB::table('center_user')
                ->where('center_id', $centerId)
                ->delete();
        }

        // 9. Delete only users matching the exact E2E token.
        if ($userIds !== []) {
            $fixtureToken = substr(
                (string) $center->slug,
                strlen(self::FIXTURE_SLUG_PREFIX)
            );

            $exactEmails = [
                "admin_{$fixtureToken}@example.test",
                "student_{$fixtureToken}@example.test",
            ];

            $fixtureUsers = DB::table('users')
                ->whereIn('id', $userIds)
                ->whereIn('email', $exactEmails)
                ->get(['id']);

            $userIdsToDelete = [];

            foreach ($fixtureUsers as $fixtureUser) {
                $hasOtherMemberships = DB::table('center_user')
                    ->where('user_id', $fixtureUser->id)
                    ->exists();

                if (! $hasOtherMemberships) {
                    $userIdsToDelete[] = $fixtureUser->id;
                }
            }

            if ($userIdsToDelete !== []) {
                DB::table('users')
                    ->whereIn('id', $userIdsToDelete)
                    ->delete();
            }
        }

        // 10. Delete center last.
        $center->delete();
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
