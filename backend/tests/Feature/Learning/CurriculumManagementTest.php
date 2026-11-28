<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\LessonRevision;
use App\Models\Level;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class CurriculumManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard->login($user);
    }

    private function createCenterAdminCourse(): array
    {
        $center = Center::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $membership = CenterUser::query()->create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->where('key', 'center_admin')->firstOrFail();
        $membership->roles()->attach($role->id);

        $language = Language::factory()->for($center)->create();
        $level = Level::factory()->for($center)->create();
        $course = Course::factory()->forCenter($center)->published()->create([
            'language_id' => $language->id,
            'level_id' => $level->id,
        ]);

        return [$center, $user, $course];
    }

    public function test_module_and_lesson_crud_reorder_move_duplicate_and_archive(): void
    {
        [$center, $admin, $course] = $this->createCenterAdminCourse();
        $token = $this->tokenFor($admin);
        $base = "/api/centers/{$center->slug}/admin/courses/{$course->slug}";

        // Create 2 modules
        $m1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules", ['title' => 'Module One'])
            ->assertStatus(201);
        $m2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules", ['title' => 'Module Two'])
            ->assertStatus(201);

        $m1Id = $m1->json('data.id');
        $m2Id = $m2->json('data.id');

        // Rename & reorder modules
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/modules/{$m1Id}", ['title' => 'Module 1 Updated', 'status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Module 1 Updated')
            ->assertJsonPath('data.status', 'published');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("{$base}/modules/order", ['module_ids' => [$m2Id, $m1Id]])
            ->assertOk();

        $this->assertSame(0, CourseModule::find($m2Id)->sort_order);
        $this->assertSame(1, CourseModule::find($m1Id)->sort_order);

        // Create lessons in Module 1 (video + reading)
        $l1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$m1Id}/lessons", [
                'title' => 'Intro Video',
                'content_type' => 'video',
                'duration_minutes' => 10,
            ])
            ->assertStatus(201);
        $l1Id = $l1->json('data.id');
        $this->assertNotNull($l1->json('data.draft_revision_id'));
        $this->assertDatabaseHas('lesson_revisions', [
            'lesson_id' => $l1Id,
            'status' => LessonRevision::STATUS_DRAFT,
            'version_number' => 1,
        ]);

        // Duplicate lesson
        $dup = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/lessons/{$l1Id}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Intro Video (Copy)');
        $dupId = $dup->json('data.id');

        // Reorder lessons in Module 1
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("{$base}/modules/{$m1Id}/lessons/order", ['lesson_ids' => [$dupId, $l1Id]])
            ->assertOk();
        $this->assertSame(0, Lesson::find($dupId)->sort_order);
        $this->assertSame(1, Lesson::find($l1Id)->sort_order);

        // Move lesson to Module 2
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/lessons/{$l1Id}/move", ['target_module_id' => $m2Id])
            ->assertOk()
            ->assertJsonPath('data.course_module_id', $m2Id);

        // Archive Module 2 -> archives its lessons without deleting revisions
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/modules/{$m2Id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertSame(Lesson::STATUS_ARCHIVED, Lesson::find($l1Id)->status);
        $this->assertDatabaseHas('lesson_revisions', ['lesson_id' => $l1Id]);
    }

    public function test_rejects_cross_center_access_to_curriculum_endpoints(): void
    {
        [$centerA, , $courseA] = $this->createCenterAdminCourse();
        [, $adminB] = $this->createCenterAdminCourse();
        $tokenB = $this->tokenFor($adminB);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson("/api/centers/{$centerA->slug}/admin/courses/{$courseA->slug}/curriculum")
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->postJson("/api/centers/{$centerA->slug}/admin/courses/{$courseA->slug}/modules", ['title' => 'Hack'])
            ->assertStatus(403);
    }
}
