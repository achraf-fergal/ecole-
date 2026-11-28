<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseOutcome;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\Level;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\StudentProfile;
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

        // Rename & publish module via dedicated publish endpoint (PATCH cannot bypass publication)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/modules/{$m1Id}", ['title' => 'Module 1 Updated', 'status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Module 1 Updated')
            ->assertJsonPath('data.status', 'draft');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$m1Id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        // Reorder modules: partial array rejected, complete array succeeds
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("{$base}/modules/order", ['module_ids' => [$m1Id]])
            ->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("{$base}/modules/order", ['module_ids' => [$m2Id, $m1Id]])
            ->assertOk();

        $this->assertSame(0, CourseModule::find($m2Id)->sort_order);
        $this->assertSame(1, CourseModule::find($m1Id)->sort_order);

        // Create lessons in Module 1 (video + reading + exercise)
        $l1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$m1Id}/lessons", [
                'title' => 'Intro Video',
                'content_type' => 'video',
                'duration_minutes' => 10,
            ])
            ->assertStatus(201);
        $l1Id = $l1->json('data.id');
        $l1RevId = $l1->json('data.draft_revision_id');
        $this->assertNotNull($l1RevId);

        // Attach a video asset + block to l1
        $videoAsset = LessonAsset::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $l1Id,
            'title' => 'Welcome Clip',
            'asset_type' => LessonAsset::TYPE_VIDEO,
            'video_provider' => LessonAsset::VIDEO_PROVIDER_YOUTUBE,
            'external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'status' => LessonAsset::STATUS_ACTIVE,
        ]);
        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $l1Id,
            'lesson_revision_id' => $l1RevId,
            'block_type' => LessonBlock::TYPE_MEDIA,
            'sort_order' => 0,
            'lesson_asset_id' => $videoAsset->id,
        ]);

        // Duplicate video lesson -> preserves media reference on new lesson
        $dup = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/lessons/{$l1Id}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Intro Video (Copy)')
            ->assertJsonPath('data.summary.has_video', true);
        $dupId = $dup->json('data.id');
        $this->assertDatabaseHas('lesson_assets', [
            'lesson_id' => $dupId,
            'title' => 'Welcome Clip',
            'external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        // Create exercise lesson with question + option + student attempt, then duplicate
        $lEx = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$m1Id}/lessons", [
                'title' => 'Grammar Quiz',
                'content_type' => 'exercise',
            ])
            ->assertStatus(201);
        $lExId = $lEx->json('data.id');
        $lExRevId = $lEx->json('data.draft_revision_id');

        $exercise = Exercise::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lExId,
            'title' => 'Quiz 1',
            'passing_score' => 70,
            'status' => Exercise::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $question = Question::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'question_type' => Question::TYPE_TRUE_FALSE,
            'prompt' => 'Is 2+2=4?',
            'points' => 2,
            'sort_order' => 0,
        ]);
        QuestionOption::query()->create([
            'center_id' => $center->id,
            'question_id' => $question->id,
            'option_text' => 'True',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lExId,
            'lesson_revision_id' => $lExRevId,
            'block_type' => LessonBlock::TYPE_EXERCISE,
            'sort_order' => 0,
            'exercise_id' => $exercise->id,
        ]);
        $studentUser = User::factory()->create();
        CenterUser::query()->create([
            'center_id' => $center->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);
        $studentProfile = StudentProfile::query()->create([
            'center_id' => $center->id,
            'user_id' => $studentUser->id,
            'student_code' => 'STU-99',
            'status' => 'active',
        ]);
        ExerciseAttempt::query()->create([
            'center_id' => $center->id,
            'exercise_id' => $exercise->id,
            'student_profile_id' => $studentProfile->id,
            'attempt_number' => 1,
            'passing_score_snapshot' => 70,
            'started_at' => now(),
            'status' => ExerciseAttempt::STATUS_GRADED,
        ]);

        $dupEx = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/lessons/{$lExId}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('data.summary.question_count', 1);
        $dupExId = $dupEx->json('data.id');
        $clonedExercise = Exercise::query()->where('lesson_id', $dupExId)->firstOrFail();
        $this->assertSame(Exercise::STATUS_DRAFT, $clonedExercise->status);
        $this->assertSame(1, $clonedExercise->questions()->count());
        $this->assertSame(0, ExerciseAttempt::query()->where('exercise_id', $clonedExercise->id)->count());

        // Reorder lessons in Module 1 (partial rejected, full succeeds)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("{$base}/modules/{$m1Id}/lessons/order", ['lesson_ids' => [$dupId, $l1Id]])
            ->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("{$base}/modules/{$m1Id}/lessons/order", ['lesson_ids' => [$dupId, $lExId, $dupExId, $l1Id]])
            ->assertOk();
        $this->assertSame(0, Lesson::find($dupId)->sort_order);

        // Move lesson to Module 2 with placement = first
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/lessons/{$l1Id}/move", [
                'target_module_id' => $m2Id,
                'placement' => 'first',
            ])
            ->assertOk()
            ->assertJsonPath('data.course_module_id', $m2Id)
            ->assertJsonPath('data.sort_order', 0);

        // Archive Module 2 -> archives its lessons; Restore Module 2 -> restores to draft (never published)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/modules/{$m2Id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
        $this->assertSame(Lesson::STATUS_ARCHIVED, Lesson::find($l1Id)->status);

        // Cannot publish archived module directly or move lesson into archived module
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$m2Id}/publish")
            ->assertStatus(422);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/lessons/{$dupId}/move", ['target_module_id' => $m2Id])
            ->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$m2Id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
        $this->assertSame(Lesson::STATUS_DRAFT, Lesson::find($l1Id)->status);

        // Archive and restore single lesson
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/lessons/{$l1Id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/lessons/{$l1Id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
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

    public function test_locale_aware_outcomes_preserve_other_language_and_existing_ids(): void
    {
        [$center, $admin, $course] = $this->createCenterAdminCourse();
        $token = $this->tokenFor($admin);
        $base = "/api/centers/{$center->slug}/admin/courses/{$course->slug}";

        // Seed French outcome
        $frOutcome = CourseOutcome::query()->create([
            'center_id' => $center->id,
            'course_id' => $course->id,
            'locale' => 'fr',
            'content' => 'Comprendre la grammaire',
            'sort_order' => 0,
            'is_highlight' => true,
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Save English outcomes -> French outcome untouched
        $enSave = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/settings", [
                'outcomes_locale' => 'en',
                'outcomes' => [
                    ['content' => 'Understand grammar', 'is_highlight' => false],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('course_outcomes', ['id' => $frOutcome->id, 'locale' => 'fr', 'content' => 'Comprendre la grammaire']);
        $enOutcomeId = collect($enSave->json('data.outcomes'))->firstWhere('locale', 'en')['id'];

        // Re-save English outcome with its ID -> preserves same ID, no duplicates
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/settings", [
                'outcomes_locale' => 'en',
                'outcomes' => [
                    ['id' => $enOutcomeId, 'locale' => 'en', 'content' => 'Understand basic grammar', 'is_highlight' => true],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, CourseOutcome::query()->where('course_id', $course->id)->where('locale', 'en')->count());
        $this->assertDatabaseHas('course_outcomes', ['id' => $enOutcomeId, 'content' => 'Understand basic grammar']);
        $this->assertDatabaseHas('course_outcomes', ['id' => $frOutcome->id, 'locale' => 'fr']);

        // Invalid locale rejected
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/settings", [
                'outcomes_locale' => 'xx',
                'outcomes' => [['content' => 'Bad']],
            ])
            ->assertStatus(422);
    }

    public function test_module_restore_does_not_restore_independently_archived_lesson(): void
    {
        [$center, $admin, $course] = $this->createCenterAdminCourse();
        $token = $this->tokenFor($admin);
        $base = "/api/centers/{$center->slug}/admin/courses/{$course->slug}";

        $mod = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules", ['title' => 'Module X'])
            ->assertStatus(201);
        $modId = $mod->json('data.id');

        $lessonA = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$modId}/lessons", ['title' => 'Lesson A', 'content_type' => 'reading'])
            ->assertStatus(201)->json('data.id');
        $lessonB = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$modId}/lessons", ['title' => 'Lesson B', 'content_type' => 'reading'])
            ->assertStatus(201)->json('data.id');

        // Archive Lesson A independently first
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/lessons/{$lessonA}/archive")
            ->assertOk();

        // Archive Module X (cascades to Lesson B only)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/modules/{$modId}/archive")
            ->assertOk();

        // Restore Module X -> Lesson B becomes draft, Lesson A stays archived!
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$modId}/restore")
            ->assertOk();

        $this->assertSame(Lesson::STATUS_ARCHIVED, Lesson::find($lessonA)->status);
        $this->assertSame(Lesson::STATUS_DRAFT, Lesson::find($lessonB)->status);
    }

    public function test_preview_has_draft_changes_and_type_guard_and_pricing_validation(): void
    {
        [$center, $admin, $course] = $this->createCenterAdminCourse();
        $token = $this->tokenFor($admin);
        $base = "/api/centers/{$center->slug}/admin/courses/{$course->slug}";

        // Published module + published lesson + new draft module
        $pubMod = CourseModule::factory()->forCourse($course)->published()->create();
        $draftMod = CourseModule::factory()->forCourse($course)->create(['status' => CourseModule::STATUS_DRAFT]);

        $readingLesson = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/modules/{$pubMod->id}/lessons", ['title' => 'Reading 1', 'content_type' => 'reading'])
            ->assertStatus(201);
        $readingId = $readingLesson->json('data.id');
        $revId = $readingLesson->json('data.draft_revision_id');

        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $readingId,
            'lesson_revision_id' => $revId,
            'block_type' => LessonBlock::TYPE_TEXT,
            'sort_order' => 0,
            'content_json' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]],
        ]);

        // Incompatible lesson type change rejected
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/lessons/{$readingId}", ['content_type' => 'exercise'])
            ->assertStatus(422);

        // Published preview still detects pending draft changes from draftMod / draft lesson
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("{$base}/preview?version=published")
            ->assertOk()
            ->assertJsonPath('data.has_draft_changes', true)
            ->assertJsonCount(0, 'data.modules.0.lessons');

        // Fixed pricing with 0 price rejected
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/settings", ['pricing_mode' => 'fixed', 'price' => 0, 'currency' => 'MAD'])
            ->assertStatus(422);

        // Free pricing with non-zero price rejected
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("{$base}/settings", ['pricing_mode' => 'free', 'price' => 100])
            ->assertStatus(422);
    }
}
