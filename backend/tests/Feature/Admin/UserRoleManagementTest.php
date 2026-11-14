<?php

namespace Tests\Feature\Admin;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createCenterWithAdmin(string $slug = 'center-a'): array
    {
        $center = Center::factory()->create(['slug' => $slug]);
        $admin = User::factory()->create(['email_verified_at' => now()]);

        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $adminRole = Role::where('key', 'center_admin')->first();
        $membership->roles()->attach($adminRole->id);

        return [$center, $admin];
    }

    public function test_center_admin_can_list_users_in_their_center(): void
    {
        [$center, $admin] = $this->createCenterWithAdmin('center-alpha');

        // Create a student in the same center
        $student = User::factory()->create(['name' => 'John Student']);
        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        $studentRole = Role::where('key', 'student')->first();
        $membership->roles()->attach($studentRole->id);

        $token = auth('api')->login($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/centers/{$center->slug}/admin/users");

        $response->assertOk();
        $response->assertJsonFragment([
            'email' => $student->email,
            'name' => 'John Student',
        ]);
    }

    public function test_center_admin_can_change_roles_of_a_user_in_their_center(): void
    {
        [$center, $admin] = $this->createCenterWithAdmin('center-beta');

        $user = User::factory()->create();
        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $studentRole = Role::where('key', 'student')->first();
        $membership->roles()->attach($studentRole->id);

        $token = auth('api')->login($admin);

        // Promote to Teacher
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/centers/{$center->slug}/admin/users/{$user->id}/roles", [
                'roles' => ['teacher'],
            ]);

        $response->assertOk();
        $response->assertJsonPath('user.roles.0.key', 'teacher');

        $this->assertTrue($user->hasRoleInCenter($center, 'teacher'));
        $this->assertFalse($user->hasRoleInCenter($center, 'student'));
    }

    public function test_center_admin_cannot_access_or_modify_users_in_another_center(): void
    {
        [$centerA, $adminA] = $this->createCenterWithAdmin('center-one');
        [$centerB, $adminB] = $this->createCenterWithAdmin('center-two');

        $userB = User::factory()->create();
        $membershipB = CenterUser::create([
            'center_id' => $centerB->id,
            'user_id' => $userB->id,
            'status' => 'active',
        ]);
        $studentRole = Role::where('key', 'student')->first();
        $membershipB->roles()->attach($studentRole->id);

        // Admin A tries to modify user in Center B
        $tokenA = auth('api')->login($adminA);

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->putJson("/api/centers/{$centerB->slug}/admin/users/{$userB->id}/roles", [
                'roles' => ['teacher'],
            ]);

        // Forbidden because adminA has no center_admin role in centerB
        $response->assertForbidden();
    }

    public function test_regular_student_cannot_access_admin_user_management(): void
    {
        [$center, $admin] = $this->createCenterWithAdmin('center-student');

        $student = User::factory()->create(['email_verified_at' => now()]);
        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        $studentRole = Role::where('key', 'student')->first();
        $membership->roles()->attach($studentRole->id);

        $token = auth('api')->login($student);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/centers/{$center->slug}/admin/users");

        $response->assertForbidden();
    }

    public function test_super_admin_can_toggle_platform_admin_status(): void
    {
        $superAdmin = User::factory()->create(['email_verified_at' => now()]);
        PlatformAdmin::create(['user_id' => $superAdmin->id]);

        $candidate = User::factory()->create(['email_verified_at' => now()]);

        $token = auth('api')->login($superAdmin);

        // Grant Super Admin
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/platform-admin/users/{$candidate->id}/super-admin", [
                'is_super_admin' => true,
            ]);

        $response->assertOk();
        $this->assertTrue($candidate->isPlatformAdmin());

        // Revoke Super Admin
        $responseRevoke = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/platform-admin/users/{$candidate->id}/super-admin", [
                'is_super_admin' => false,
            ]);

        $responseRevoke->assertOk();
        $this->assertFalse($candidate->isPlatformAdmin());
    }

    public function test_non_super_admin_cannot_access_platform_admin_routes(): void
    {
        $regularUser = User::factory()->create(['email_verified_at' => now()]);
        $token = auth('api')->login($regularUser);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/platform-admin/users');

        $response->assertForbidden();
        $response->assertJson([
            'code' => 'SUPER_ADMIN_REQUIRED',
        ]);
    }

    public function test_center_admin_can_add_new_member_to_center(): void
    {
        [$center, $admin] = $this->createCenterWithAdmin('center-add');
        $token = auth('api')->login($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/centers/{$center->slug}/admin/users", [
                'email' => 'new.teacher@example.com',
                'name' => 'New Teacher',
                'roles' => ['teacher'],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('user.email', 'new.teacher@example.com');
        $response->assertJsonPath('user.roles.0.key', 'teacher');

        $this->assertDatabaseHas('users', ['email' => 'new.teacher@example.com']);
    }

    public function test_center_admin_cannot_demote_the_last_center_admin(): void
    {
        [$center, $admin] = $this->createCenterWithAdmin('center-sole');
        $token = auth('api')->login($admin);

        // Try demoting self to only student
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/centers/{$center->slug}/admin/users/{$admin->id}/roles", [
                'roles' => ['student'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['roles']);
    }
}
