<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class LearningAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Create a fully enrolled student and a published lesson.
     *
     * @return array<string, mixed>
     */
    private function createLearningContext(): array
    {
        $center = Center::factory()->create();
        $user = User::factory()->create();

        $membership = CenterUser::factory()
            ->for($center)
            ->for($user)
            ->create();

        $studentRole = Role::query()
            ->where('key', 'student')
            ->firstOrFail();

        $membership->roles()->attach($studentRole->id);

        $studentProfile = StudentProfile::factory()
            ->forMembership($membership)
            ->create();

        $course = Course::factory()
            ->forCenter($center)
            ->published()
            ->create();

        $cohort = Cohort::factory()
            ->forCourse($course)
            ->inProgress()
            ->create([
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addMonths(2),
            ]);

        $enrollment = Enrollment::factory()
            ->forStudentProfile($studentProfile)
            ->forCohort($cohort)
            ->create([
                'start_at' => now()->subDay(),
                'end_at' => now()->addMonth(),
            ]);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create();

        $lesson = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create();

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

    public function test_enrolled_student_can_access_published_course_and_lesson(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $this->assertTrue(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );

        $this->assertTrue(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$lesson, $course, $center]
            )
        );
    }

    public function test_user_without_student_role_cannot_access_learning_content(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'membership' => $membership,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $teacherRole = Role::query()
            ->where('key', 'teacher')
            ->firstOrFail();

        $membership->roles()->sync([$teacherRole->id]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$lesson, $course, $center]
            )
        );
    }

    public function test_inactive_membership_cannot_access_learning_content(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'membership' => $membership,
            'course' => $course,
        ] = $this->createLearningContext();

        $membership->update(['status' => 'suspended']);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );
    }

    public function test_inactive_student_profile_cannot_access_learning_content(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'studentProfile' => $studentProfile,
            'course' => $course,
        ] = $this->createLearningContext();

        $studentProfile->update([
            'status' => StudentProfile::STATUS_SUSPENDED,
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );
    }

    public function test_inactive_center_cannot_grant_learning_access(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
        ] = $this->createLearningContext();

        $center->update(['status' => 'suspended']);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );
    }

    public function test_student_without_enrollment_cannot_access_course(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'enrollment' => $enrollment,
            'course' => $course,
        ] = $this->createLearningContext();

        $enrollment->delete();

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );
    }

    public function test_future_expired_and_suspended_enrollments_do_not_grant_access(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'enrollment' => $enrollment,
            'course' => $course,
        ] = $this->createLearningContext();

        $enrollment->update([
            'start_at' => now()->addDay(),
            'end_at' => null,
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );

        $enrollment->update([
            'start_at' => now()->subMonths(2),
            'end_at' => now()->subDay(),
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );

        $enrollment->update([
            'start_at' => now()->subDay(),
            'end_at' => null,
            'status' => Enrollment::STATUS_SUSPENDED,
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );
    }

    public function test_cancelled_cohort_does_not_grant_learning_access(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'cohort' => $cohort,
            'course' => $course,
        ] = $this->createLearningContext();

        $cohort->update([
            'status' => Cohort::STATUS_CANCELLED,
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );
    }

    public function test_disabled_user_cannot_access_learning_content(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
        ] = $this->createLearningContext();

        $user->update([
            'status' => User::STATUS_DISABLED,
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );
    }

    public function test_unpublished_course_cannot_be_accessed_even_with_active_enrollment(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
        ] = $this->createLearningContext();

        $course->update([
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$course, $center]
            )
        );
    }

    public function test_unpublished_module_cannot_expose_its_lesson(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'module' => $module,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $module->update([
            'status' => CourseModule::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$lesson, $course, $center]
            )
        );
    }

    public function test_unpublished_lesson_cannot_be_accessed(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $lesson->update([
            'status' => Lesson::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$lesson, $course, $center]
            )
        );
    }

    public function test_lesson_from_another_course_cannot_be_accessed_through_this_course(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
        ] = $this->createLearningContext();

        // Reuse the center's existing language and level.
        $otherCourse = Course::factory()
            ->forCenter($center)
            ->withLanguage($course->language)
            ->withLevel($course->level)
            ->published()
            ->create();

        $otherModule = CourseModule::factory()
            ->forCourse($otherCourse)
            ->published()
            ->create();

        $otherLesson = Lesson::factory()
            ->forModule($otherModule)
            ->published()
            ->create();

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$otherLesson, $course, $center]
            )
        );
    }

    public function test_student_cannot_access_a_course_owned_by_another_center(): void
    {
        [
            'center' => $center,
            'user' => $user,
        ] = $this->createLearningContext();

        $otherCenter = Center::factory()->create();

        $otherCourse = Course::factory()
            ->forCenter($otherCenter)
            ->published()
            ->create();

        $this->assertFalse(
            Gate::forUser($user)->allows(
                'viewLearning',
                [$otherCourse, $center]
            )
        );
    }
}
