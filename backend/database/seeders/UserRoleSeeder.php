<?php

namespace Database\Seeders;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserRoleSeeder extends Seeder
{
    /**
     * Seed users for all platform and center roles.
     * Default password for all seeded users: Password123!@#
     */
    public function run(): void
    {
        // 1. Ensure primary demo center exists
        $demoCenter = Center::firstOrCreate(
            ['slug' => 'demo-center'],
            [
                'name' => 'Demo Language Center',
                'email' => 'contact@demo-center.test',
                'phone' => '+1234567890',
                'status' => 'active',
                'timezone' => 'Africa/Casablanca',
                'default_locale' => 'en',
            ]
        );

        // 2. Ensure secondary center exists for multi-tenant isolation testing
        $secondCenter = Center::firstOrCreate(
            ['slug' => 'casablanca-center'],
            [
                'name' => 'Casablanca Language Academy',
                'email' => 'contact@casablanca-center.test',
                'phone' => '+212522000000',
                'status' => 'active',
                'timezone' => 'Africa/Casablanca',
                'default_locale' => 'fr',
            ]
        );

        // Retrieve existing roles
        $centerAdminRole = Role::where('key', 'center_admin')->firstOrFail();
        $teacherRole = Role::where('key', 'teacher')->firstOrFail();
        $studentRole = Role::where('key', 'student')->firstOrFail();

        $defaultPassword = Hash::make('Password123!@#');

        // =========================================================================
        // 1. Platform Super Admin (Universal Access)
        // =========================================================================
        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Platform Super Admin',
                'password' => $defaultPassword,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'token_version' => 1,
            ]
        );
        PlatformAdmin::firstOrCreate(['user_id' => $superAdmin->id]);

        // =========================================================================
        // 2. Center Admin (Demo Center)
        // =========================================================================
        $centerAdmin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Center Administrator',
                'password' => $defaultPassword,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'token_version' => 1,
            ]
        );
        $membershipAdmin = CenterUser::firstOrCreate(
            ['center_id' => $demoCenter->id, 'user_id' => $centerAdmin->id],
            ['status' => 'active']
        );
        $membershipAdmin->roles()->syncWithoutDetaching([$centerAdminRole->id]);

        // =========================================================================
        // 3. Teacher (Demo Center)
        // =========================================================================
        $teacher = User::updateOrCreate(
            ['email' => 'teacher@example.com'],
            [
                'name' => 'Sarah Teacher',
                'password' => $defaultPassword,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'token_version' => 1,
            ]
        );
        $membershipTeacher = CenterUser::firstOrCreate(
            ['center_id' => $demoCenter->id, 'user_id' => $teacher->id],
            ['status' => 'active']
        );
        $membershipTeacher->roles()->syncWithoutDetaching([$teacherRole->id]);

        // =========================================================================
        // 4. Student (Demo Center)
        // =========================================================================
        $student = User::updateOrCreate(
            ['email' => 'student@example.com'],
            [
                'name' => 'Alex Student',
                'password' => $defaultPassword,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'token_version' => 1,
            ]
        );
        $membershipStudent = CenterUser::firstOrCreate(
            ['center_id' => $demoCenter->id, 'user_id' => $student->id],
            ['status' => 'active']
        );
        $membershipStudent->roles()->syncWithoutDetaching([$studentRole->id]);

        // =========================================================================
        // 5. Dual Role User: Teacher + Center Admin (Demo Center)
        // =========================================================================
        $leadInstructor = User::updateOrCreate(
            ['email' => 'lead@example.com'],
            [
                'name' => 'Dr. Marcus Lead',
                'password' => $defaultPassword,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'token_version' => 1,
            ]
        );
        $membershipLead = CenterUser::firstOrCreate(
            ['center_id' => $demoCenter->id, 'user_id' => $leadInstructor->id],
            ['status' => 'active']
        );
        $membershipLead->roles()->syncWithoutDetaching([$centerAdminRole->id, $teacherRole->id]);

        // =========================================================================
        // 6. Second Center Admin (Casablanca Center - Multi-Tenant Isolation)
        // =========================================================================
        $casaAdmin = User::updateOrCreate(
            ['email' => 'casa.admin@example.com'],
            [
                'name' => 'Youssef Casa Admin',
                'password' => $defaultPassword,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'token_version' => 1,
            ]
        );
        $membershipCasa = CenterUser::firstOrCreate(
            ['center_id' => $secondCenter->id, 'user_id' => $casaAdmin->id],
            ['status' => 'active']
        );
        $membershipCasa->roles()->syncWithoutDetaching([$centerAdminRole->id]);

        // =========================================================================
        // 7. Standard Registered User (No center, no teacher/student role - Role: user)
        // =========================================================================
        User::updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Karim Regular User',
                'password' => $defaultPassword,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'token_version' => 1,
            ]
        );
    }
}
