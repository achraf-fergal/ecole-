<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentProfileDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_profiles_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('student_profiles', [
                'id',
                'center_id',
                'user_id',
                'student_code',
                'phone',
                'status',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_profile_has_correct_center_user_relationships(): void
    {
        $membership = CenterUser::factory()->create();

        $profile = StudentProfile::factory()
            ->forMembership($membership)
            ->create();

        $this->assertTrue(
            $profile->center->is($membership->center)
        );

        $this->assertTrue(
            $profile->user->is($membership->user)
        );

        $this->assertDatabaseHas('center_user', [
            'center_id' => $profile->center_id,
            'user_id' => $profile->user_id,
        ]);
    }

    public function test_profile_cannot_reference_nonexistent_center_membership(): void
    {
        $center = Center::factory()->create();

        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        StudentProfile::query()->create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'student_code' => 'STU-0001',
            'status' => StudentProfile::STATUS_ACTIVE,
        ]);
    }

    public function test_membership_cannot_have_duplicate_student_profiles(): void
    {
        $membership = CenterUser::factory()->create();

        StudentProfile::factory()
            ->forMembership($membership)
            ->create([
                'student_code' => 'STU-0001',
            ]);

        $this->expectException(QueryException::class);

        StudentProfile::factory()
            ->forMembership($membership)
            ->create([
                'student_code' => 'STU-0002',
            ]);
    }

    public function test_student_code_must_be_unique_within_same_center(): void
    {
        $center = Center::factory()->create();

        $membershipA = CenterUser::factory()
            ->for($center)
            ->create();

        $membershipB = CenterUser::factory()
            ->for($center)
            ->create();

        StudentProfile::factory()
            ->forMembership($membershipA)
            ->create([
                'student_code' => 'STU-1001',
            ]);

        $this->expectException(QueryException::class);

        StudentProfile::factory()
            ->forMembership($membershipB)
            ->create([
                'student_code' => 'STU-1001',
            ]);
    }

    public function test_user_can_have_profiles_in_multiple_centers(): void
    {
        $user = User::factory()->create();

        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $membershipA = CenterUser::factory()
            ->for($centerA)
            ->for($user)
            ->create();

        $membershipB = CenterUser::factory()
            ->for($centerB)
            ->for($user)
            ->create();

        StudentProfile::factory()
            ->forMembership($membershipA)
            ->create([
                'student_code' => 'STU-0001',
            ]);

        StudentProfile::factory()
            ->forMembership($membershipB)
            ->create([
                'student_code' => 'STU-0001',
            ]);

        $profiles = $user->fresh()->studentProfiles;

        $this->assertCount(2, $profiles);

        $this->assertSame(
            [
                $centerA->id,
                $centerB->id,
            ],
            $profiles->pluck('center_id')->sort()->values()->all()
        );
    }

    
    public function test_profile_status_and_archiving_are_preserved(): void
    {
        $membership = CenterUser::factory()->create();
    
        // Create one student profile for this membership.
        $profile = StudentProfile::factory()
            ->forMembership($membership)
            ->create([
                'status' => StudentProfile::STATUS_ACTIVE,
            ]);
    
        $this->assertTrue($profile->isActive());
    
        // Archive the existing profile instead of creating a duplicate.
        $profile->update([
            'status' => StudentProfile::STATUS_ARCHIVED,
        ]);
    
        $profile->refresh();
    
        $this->assertFalse($profile->isActive());
    
        $this->assertTrue($profile->isArchived());
    
        // Archived profiles must not appear in active queries.
        $this->assertCount(
            0,
            StudentProfile::query()
                ->forCenter($membership->center_id)
                ->active()
                ->get()
        );
    
        $this->assertDatabaseHas('student_profiles', [
            'id' => $profile->id,
            'center_id' => $membership->center_id,
            'user_id' => $membership->user_id,
            'status' => StudentProfile::STATUS_ARCHIVED,
        ]);
    }
    

    public function test_user_can_be_student_and_teacher_in_same_center(): void
    {
        $this->seed(RoleSeeder::class);

        $membership = CenterUser::factory()->create();

        $roles = Role::query()
            ->whereIn('key', ['student', 'teacher'])
            ->get();

        $this->assertCount(2, $roles);

        $membership->roles()->sync($roles->modelKeys());

        $profile = StudentProfile::factory()
            ->forMembership($membership)
            ->create();

        $this->assertCount(
            2,
            $membership->fresh()->roles
        );

        $this->assertTrue(
            $profile->user->is($membership->user)
        );
    }
}
