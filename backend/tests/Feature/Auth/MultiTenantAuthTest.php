<?php

namespace Tests\Feature\Auth;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Language;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantAuthTest extends TestCase
{
    use RefreshDatabase;

    protected Role $centerAdminRole;

    protected Role $teacherRole;

    protected Role $studentRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centerAdminRole = Role::create([
            'key' => 'center_admin',
            'name' => 'Center Admin',
            'is_system' => true,
        ]);

        $this->teacherRole = Role::create([
            'key' => 'teacher',
            'name' => 'Teacher',
            'is_system' => true,
        ]);

        $this->studentRole = Role::create([
            'key' => 'student',
            'name' => 'Student',
            'is_system' => true,
        ]);
    }

    public function test_user_cannot_access_unauthorized_center(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();

        $user = User::factory()->create();

        // User is member of Center A only.
        $membershipA = CenterUser::create([
            'center_id' => $centerA->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membershipA->roles()->attach($this->studentRole->id);

        $token = auth('api')->claims(['ver' => $user->token_version])->login($user);

        // Access Center A succeeds.
        $responseA = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.context', ['center' => $centerA->slug]));

        $responseA->assertOk();

        // Access Center B is forbidden (403).
        $responseB = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.context', ['center' => $centerB->slug]));

        $responseB->assertStatus(403);
        $responseB->assertJson([
            'message' => 'Unauthorized access to this center.',
        ]);
    }

    public function test_center_admin_cannot_access_another_centers_admin_area(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();

        $adminA = User::factory()->create();

        // Admin of Center A only.
        $membershipA = CenterUser::create([
            'center_id' => $centerA->id,
            'user_id' => $adminA->id,
            'status' => 'active',
        ]);
        $membershipA->roles()->attach($this->centerAdminRole->id);

        $token = auth('api')->claims(['ver' => $adminA->token_version])->login($adminA);

        // Accessing Center A admin area succeeds.
        $responseA = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.admin.dashboard', ['center' => $centerA->slug]));

        $responseA->assertOk();

        // Accessing Center B admin area is forbidden.
        $responseB = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.admin.dashboard', ['center' => $centerB->slug]));

        $responseB->assertStatus(403);
    }

    public function test_teacher_without_teacher_role_cannot_access_teacher_area(): void
    {
        $center = Center::factory()->create();
        $studentUser = User::factory()->create();

        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach($this->studentRole->id);

        $token = auth('api')->claims(['ver' => $studentUser->token_version])->login($studentUser);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.teacher.cohorts', ['center' => $center->slug]));

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Forbidden. Insufficient role permissions.',
        ]);
    }

    public function test_student_cannot_access_course_without_active_enrollment(): void
    {
        $center = Center::factory()->create();
        $language = Language::factory()->for($center)->create();
        $level = Level::factory()->for($center)->create();

        $course = Course::factory()->forCenter($center)->create([
            'language_id' => $language->id,
            'level_id' => $level->id,
        ]);

        $cohort = Cohort::create([
            'center_id' => $center->id,
            'course_id' => $course->id,
            'name' => 'Cohort 1',
            'capacity' => 20,
            'starts_at' => now()->subDay(),
            'status' => Cohort::STATUS_OPEN,
        ]);

        $studentUser = User::factory()->create();

        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach($this->studentRole->id);

        $studentProfile = StudentProfile::create([
            'center_id' => $center->id,
            'user_id' => $studentUser->id,
            'student_code' => 'STU1001',
            'status' => StudentProfile::STATUS_ACTIVE,
        ]);

        $token = auth('api')->claims(['ver' => $studentUser->token_version])->login($studentUser);

        // 1. Without enrollment: access is rejected (403).
        $noEnrollmentResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.courses.learn.player', [
                'center' => $center->slug,
                'course' => $course->slug,
            ]));

        $noEnrollmentResponse->assertStatus(403);
        $noEnrollmentResponse->assertJson([
            'message' => 'Active course enrollment required.',
        ]);

        // 2. With active enrollment: access succeeds.
        Enrollment::create([
            'center_id' => $center->id,
            'student_profile_id' => $studentProfile->id,
            'cohort_id' => $cohort->id,
            'status' => Enrollment::STATUS_ACTIVE,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonths(3),
        ]);

        $enrolledResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.courses.learn.player', [
                'center' => $center->slug,
                'course' => $course->slug,
            ]));

        $enrolledResponse->assertOk();
        $enrolledResponse->assertJson([
            'message' => 'Course player access granted.',
        ]);
    }

    public function test_user_with_multiple_center_memberships_operates_correctly(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();

        $user = User::factory()->create();

        // User is teacher in Center A, student in Center B.
        $membershipA = CenterUser::create([
            'center_id' => $centerA->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membershipA->roles()->attach($this->teacherRole->id);

        $membershipB = CenterUser::create([
            'center_id' => $centerB->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membershipB->roles()->attach($this->studentRole->id);

        $token = auth('api')->claims(['ver' => $user->token_version])->login($user);

        // Center A: Teacher area accessible.
        $responseA = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.teacher.cohorts', ['center' => $centerA->slug]));
        $responseA->assertOk();

        // Center B: Teacher area NOT accessible (only student role in Center B).
        $responseB = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.teacher.cohorts', ['center' => $centerB->slug]));
        $responseB->assertStatus(403);
    }

    public function test_platform_super_admin_has_universal_access(): void
    {
        $center = Center::factory()->create();

        $superAdmin = User::factory()->create();
        PlatformAdmin::create(['user_id' => $superAdmin->id]);

        $token = auth('api')->claims(['ver' => $superAdmin->token_version])->login($superAdmin);

        // Super admin can access platform stats.
        $platformResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.platform-admin.stats'));
        $platformResponse->assertOk();

        // Super admin can access tenant context without explicit membership.
        $tenantResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.context', ['center' => $center->slug]));
        $tenantResponse->assertOk();

        // Super admin can access tenant admin area.
        $adminResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.admin.dashboard', ['center' => $center->slug]));
        $adminResponse->assertOk();
    }
}
