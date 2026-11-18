<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class LearningAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Role $centerAdminRole;

    protected Role $studentRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centerAdminRole = Role::create([
            'key' => 'center_admin',
            'name' => 'Center Admin',
            'is_system' => true,
        ]);

        $this->studentRole = Role::create([
            'key' => 'student',
            'name' => 'Student',
            'is_system' => true,
        ]);
    }

    /**
     * Generate a JWT for the given user.
     */
    private function tokenFor(User $user): string
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard
            ->claims(['ver' => (int) $user->token_version])
            ->login($user);
    }

    /**
     * Create a course with the requested publication state.
     */
    private function createCourse(
        Center $center,
        bool $published = false
    ): Course {
        $language = Language::factory()
            ->for($center)
            ->create();

        $level = Level::factory()
            ->for($center)
            ->create();

        $factory = Course::factory()
            ->forCenter($center);

        if ($published) {
            $factory = $factory->published();
        }

        return $factory->create([
            'language_id' => $language->id,
            'level_id' => $level->id,
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now() : null,
        ]);
    }

    /**
     * Create an administrator and unpublished course content.
     *
     * @return array<string, mixed>
     */
    private function createAdminContext(
        bool $alsoStudent = false
    ): array {
        $center = Center::factory()->create();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $membership->roles()->attach(
            $this->centerAdminRole->id
        );

        if ($alsoStudent) {
            $membership->roles()->attach(
                $this->studentRole->id
            );
        }

        $course = $this->createCourse($center);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create([
                'status' => CourseModule::STATUS_DRAFT,
                'published_at' => null,
            ]);

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create([
                'status' => Lesson::STATUS_DRAFT,
                'published_at' => null,
            ]);

        return compact(
            'center',
            'user',
            'membership',
            'course',
            'module',
            'lesson'
        );
    }

    /**
     * Create a student with a profile and active enrollment.
     *
     * @return array<string, mixed>
     */
    private function createStudentContext(
        bool $publishedCourse = true,
        bool $publishedModule = true,
        bool $publishedLesson = true
    ): array {
        $center = Center::factory()->create();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $membership->roles()->attach(
            $this->studentRole->id
        );

        $studentProfile = StudentProfile::factory()
            ->forMembership($membership)
            ->create();

        $course = $this->createCourse(
            $center,
            $publishedCourse
        );

        $cohort = Cohort::factory()
            ->forCourse($course)
            ->create([
                'status' => Cohort::STATUS_OPEN,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonths(3),
            ]);

        $enrollment = Enrollment::factory()
            ->forStudentProfile($studentProfile)
            ->forCohort($cohort)
            ->create([
                'status' => Enrollment::STATUS_ACTIVE,
                'start_at' => now()->subDay(),
                'end_at' => now()->addMonths(3),
            ]);

        $moduleFactory = CourseModule::factory()
            ->forCourse($course);

        if ($publishedModule) {
            $moduleFactory = $moduleFactory->published();
        }

        $module = $moduleFactory->create([
            'status' => $publishedModule ? 'published' : 'draft',
            'published_at' => $publishedModule ? now() : null,
        ]);

        $lessonFactory = Lesson::factory()
            ->forModule($module);

        if ($publishedLesson) {
            $lessonFactory = $lessonFactory->published();
        }

        $lesson = $lessonFactory->create([
            'status' => $publishedLesson ? 'published' : 'draft',
            'published_at' => $publishedLesson ? now() : null,
        ]);

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
     * Center admins can preview unpublished courses and lessons.
     */
    public function test_center_admin_can_view_unpublished_course_and_lessons(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'module' => $module,
            'lesson' => $lesson,
        ] = $this->createAdminContext();

        $token = $this->tokenFor($user);

        $overviewResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.overview', [
                'center' => $center->slug,
                'course' => $course->slug,
            ])
        );

        $overviewResponse->assertOk();

        $overviewResponse->assertJsonPath(
            'data.access_mode',
            'admin_preview'
        );

        $overviewResponse->assertJsonPath(
            'data.course.status',
            'draft'
        );

        $overviewResponse->assertJsonPath(
            'data.modules.0.id',
            $module->id
        );

        $overviewResponse->assertJsonPath(
            'data.modules.0.status',
            'draft'
        );

        $overviewResponse->assertJsonPath(
            'data.modules.0.lessons.0.id',
            $lesson->id
        );

        $overviewResponse->assertJsonPath(
            'data.modules.0.lessons.0.status',
            'draft'
        );

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

        $lessonResponse->assertJsonPath(
            'data.access_mode',
            'admin_preview'
        );

        $lessonResponse->assertJsonPath(
            'data.lesson.status',
            'draft'
        );
    }

    /**
     * Center admins cannot access another tenant's course.
     */
    public function test_center_admin_cannot_access_another_centers_course(): void
    {
        [
            'user' => $user,
        ] = $this->createAdminContext();

        $otherCenter = Center::factory()->create();

        $otherCourse = $this->createCourse($otherCenter);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.overview', [
                'center' => $otherCenter->slug,
                'course' => $otherCourse->slug,
            ])
        );

        $response->assertForbidden();
    }

    /**
     * Platform admins can preview unpublished content across centers.
     */
    public function test_platform_admin_can_view_unpublished_course_from_another_center(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        PlatformAdmin::create([
            'user_id' => $user->id,
        ]);

        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create([
                'status' => CourseModule::STATUS_DRAFT,
                'published_at' => null,
            ]);

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create([
                'status' => Lesson::STATUS_DRAFT,
                'published_at' => null,
            ]);

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
            'data.access_mode',
            'admin_preview'
        );

        $response->assertJsonPath(
            'data.course.id',
            $course->id
        );

        $response->assertJsonPath(
            'data.modules.0.id',
            $module->id
        );

        $response->assertJsonPath(
            'data.modules.0.lessons.0.id',
            $lesson->id
        );
    }

    /**
     * Platform administrators can inspect archived lessons.
     */
    public function test_platform_admin_can_retrieve_archived_lesson_for_preview(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        PlatformAdmin::create([
            'user_id' => $user->id,
        ]);

        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $course->update([
            'status' => 'archived',
            'published_at' => null,
        ]);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create([
                'status' => CourseModule::STATUS_ARCHIVED,
                'published_at' => null,
            ]);

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create([
                'status' => Lesson::STATUS_ARCHIVED,
                'published_at' => null,
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
            'data.access_mode',
            'admin_preview'
        );

        $response->assertJsonPath(
            'data.course.status',
            'archived'
        );

        $response->assertJsonPath(
            'data.module.status',
            'archived'
        );

        $response->assertJsonPath(
            'data.lesson.status',
            'archived'
        );
    }

    /**
     * Students cannot access an unpublished course.
     */
    public function test_student_cannot_access_draft_course_even_with_enrollment(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
        ] = $this->createStudentContext(
            publishedCourse: false
        );

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

    /**
     * Students cannot retrieve an unpublished lesson.
     */
    public function test_student_cannot_access_draft_lesson(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createStudentContext(
            publishedCourse: true,
            publishedModule: true,
            publishedLesson: false
        );

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
    }

    /**
     * Administrators must not modify student progress.
     *
     * The user has both roles to test the server-side protection,
     * not only the route-level role restriction.
     */
    public function test_administrator_with_student_role_cannot_update_progress(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createAdminContext(
            alsoStudent: true
        );

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route('api.centers.courses.learn.lessons.progress.update', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ]),
            [
                'completed' => true,
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseCount(
            'lesson_progresses',
            0
        );
    }
}
