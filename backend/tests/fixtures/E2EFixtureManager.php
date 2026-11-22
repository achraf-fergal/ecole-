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

            // 6. Pre-create an active LessonAsset for media block testing
            $asset = LessonAsset::create([
                'center_id' => $center->id,
                'lesson_id' => $lesson->id,
                'title' => 'E2E Diagram Asset',
                'asset_type' => LessonAsset::TYPE_IMAGE,
                'storage_disk' => 'public',
                'storage_key' => 'centers/'.$center->id.'/e2e_image.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 2048,
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
                'assetId' => $asset->id,
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

        $center = Center::find($centerId);
        if (! $center) {
            // Idempotent: already deleted or does not exist
            return;
        }

        // Strict verification: Reject deletion of real/ordinary centers
        if (
            ! str_starts_with($center->name, self::FIXTURE_NAME_PREFIX) ||
            ! str_starts_with($center->slug, self::FIXTURE_SLUG_PREFIX)
        ) {
            throw new InvalidArgumentException(
                "SAFETY VIOLATION: Center ID {$centerId} ('{$center->name}') is an ordinary center and cannot be deleted by E2EFixtureManager."
            );
        }

        DB::transaction(function () use ($center) {
            // 1. Assessment attempt answers and attempts
            DB::table('exercise_attempt_answers')->where('center_id', $center->id)->delete();
            DB::table('exercise_attempts')->where('center_id', $center->id)->delete();

            // 2. Student lesson progress
            DB::table('lesson_progresses')->where('center_id', $center->id)->delete();

            // 3. Enrollments, cohorts, student profiles
            $cohortIds = DB::table('cohorts')->where('center_id', $center->id)->pluck('id');
            if ($cohortIds->isNotEmpty()) {
                DB::table('cohort_schedules')->whereIn('cohort_id', $cohortIds)->delete();
            }
            DB::table('enrollments')->where('center_id', $center->id)->delete();
            DB::table('cohorts')->where('center_id', $center->id)->delete();
            DB::table('student_profiles')->where('center_id', $center->id)->delete();

            // 4. Clear lesson pointers to break circular FK with revisions
            DB::table('lessons')->where('center_id', $center->id)->update([
                'draft_revision_id' => null,
                'published_revision_id' => null,
            ]);

            // 5. Lesson blocks, revisions, assets
            DB::table('lesson_blocks')->where('center_id', $center->id)->delete();
            DB::table('lesson_revisions')->where('center_id', $center->id)->delete();
            DB::table('lesson_assets')->where('center_id', $center->id)->delete();

            // 6. Exercises, questions, options
            $exerciseIds = DB::table('exercises')->where('center_id', $center->id)->pluck('id');
            if ($exerciseIds->isNotEmpty()) {
                $questionIds = DB::table('questions')->whereIn('exercise_id', $exerciseIds)->pluck('id');
                if ($questionIds->isNotEmpty()) {
                    DB::table('question_options')->whereIn('question_id', $questionIds)->delete();
                    DB::table('questions')->whereIn('id', $questionIds)->delete();
                }
                DB::table('exercises')->where('center_id', $center->id)->delete();
            }

            // 7. Lessons, modules, courses, languages, levels
            DB::table('lessons')->where('center_id', $center->id)->delete();
            DB::table('course_modules')->where('center_id', $center->id)->delete();

            $courseIds = DB::table('courses')->where('center_id', $center->id)->pluck('id');
            if ($courseIds->isNotEmpty()) {
                DB::table('course_outcomes')->whereIn('course_id', $courseIds)->delete();
                DB::table('course_translations')->whereIn('course_id', $courseIds)->delete();
            }
            DB::table('courses')->where('center_id', $center->id)->delete();
            DB::table('languages')->where('center_id', $center->id)->delete();
            DB::table('levels')->where('center_id', $center->id)->delete();
            DB::table('level_templates')->where('center_id', $center->id)->delete();

            // 8. User memberships and strictly verified E2E users
            $memberships = DB::table('center_user')->where('center_id', $center->id)->get();
            $userIds = $memberships->pluck('user_id')->all();
            $membershipIds = $memberships->pluck('id')->all();

            if (! empty($membershipIds)) {
                DB::table('center_user_role')->whereIn('center_user_id', $membershipIds)->delete();
                DB::table('center_user')->where('center_id', $center->id)->delete();
            }

            // Strictly verify ownership: Only delete users that belong solely to this fixture
            if (! empty($userIds)) {
                $fixtureUsers = DB::table('users')
                    ->whereIn('id', $userIds)
                    ->where(function ($q) {
                        $q->where('email', 'like', 'admin_e2e_%@example.test')
                          ->orWhere('email', 'like', 'student_e2e_%@example.test');
                    })
                    ->get();

                $userIdsToDelete = [];
                foreach ($fixtureUsers as $fu) {
                    $otherMemberships = DB::table('center_user')
                        ->where('user_id', $fu->id)
                        ->where('center_id', '!=', $center->id)
                        ->count();

                    if ($otherMemberships === 0) {
                        $userIdsToDelete[] = $fu->id;
                    }
                }

                if (! empty($userIdsToDelete)) {
                    DB::table('users')->whereIn('id', $userIdsToDelete)->delete();
                }
            }

            // 9. Finally delete the center
            $center->delete();
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
