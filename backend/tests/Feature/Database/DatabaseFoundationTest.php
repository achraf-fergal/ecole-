<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\Level;
use App\Models\LevelTemplate;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LevelTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_database_foundation_tables_exist(): void
    {
        $tables = [
            'users',
            'centers',
            'roles',
            'center_user',
            'center_user_role',
            'platform_admins',
            'languages',
            'level_templates',
            'levels',
            'courses',
            'cohorts',
            'cohort_schedules',
            'student_profiles',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "The {$table} table should exist."
            );
        }
    }

    public function test_role_seeder_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('roles', 3);

        $this->assertDatabaseHas('roles', [
            'key' => 'center_admin',
        ]);

        $this->assertDatabaseHas('roles', [
            'key' => 'teacher',
        ]);

        $this->assertDatabaseHas('roles', [
            'key' => 'student',
        ]);
    }

    public function test_level_template_seeder_is_idempotent(): void
    {
        $this->seed(LevelTemplateSeeder::class);
        $this->seed(LevelTemplateSeeder::class);

        $this->assertDatabaseCount('level_templates', 6);

        $this->assertDatabaseHas('level_templates', [
            'code' => 'A1',
        ]);

        $this->assertDatabaseHas('level_templates', [
            'code' => 'B2',
        ]);

        $this->assertDatabaseHas('level_templates', [
            'code' => 'C2',
        ]);
    }

    public function test_user_can_have_multiple_roles_in_same_center(): void
    {
        $this->seed(RoleSeeder::class);

        $center = Center::factory()->create();

        $user = User::factory()->create();

        $membership = CenterUser::factory()
            ->for($center)
            ->for($user)
            ->create();

        $roles = Role::query()
            ->whereIn('key', ['teacher', 'student'])
            ->get();

        $this->assertCount(2, $roles);

        $membership->roles()->sync($roles->modelKeys());

        $this->assertCount(
            2,
            $membership->fresh()->roles
        );

        $this->assertSame(
            $center->id,
            $user->centers()->firstOrFail()->id
        );
    }

    public function test_center_can_use_default_and_custom_levels(): void
    {
        $this->seed(LevelTemplateSeeder::class);

        $center = Center::factory()->create();

        $template = LevelTemplate::query()
            ->where('code', 'A1')
            ->firstOrFail();

        $defaultLevel = Level::factory()
            ->for($center)
            ->fromTemplate($template)
            ->create();

        $customLevel = Level::factory()
            ->for($center)
            ->create();

        $this->assertFalse($defaultLevel->isCustom());

        $this->assertTrue($customLevel->isCustom());

        $this->assertSame(
            $template->id,
            $defaultLevel->level_template_id
        );

        $this->assertNull(
            $customLevel->level_template_id
        );
    }

    public function test_course_factory_creates_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $this->assertSame(
            $center->id,
            $course->center->id
        );

        $this->assertSame(
            $center->id,
            $course->language->center_id
        );

        $this->assertSame(
            $center->id,
            $course->level->center_id
        );
    }
}
