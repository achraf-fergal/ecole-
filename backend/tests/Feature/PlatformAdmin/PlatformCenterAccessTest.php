<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\Language;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformCenterAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Generate a JWT for the given user.
     */
    private function tokenFor(User $user): string
    {
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = auth('api');

        return $guard
            ->claims(['ver' => (int) $user->token_version])
            ->login($user);
    }

    /**
     * Create a platform administrator without center memberships.
     */
    private function createPlatformAdmin(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        PlatformAdmin::create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Create a course using existing center-owned language and level.
     */
    private function createCourse(
        Center $center,
        Language $language,
        Level $level,
        string $status,
        string $title
    ): Course {
        $course = Course::factory()
            ->forCenter($center)
            ->withLanguage($language)
            ->withLevel($level)
            ->create([
                'title' => $title,
                'status' => $status,
                'published_at' => $status === 'published'
                    ? now()
                    : null,
            ]);

        return $course;
    }

    public function test_platform_admin_can_list_all_centers_without_memberships(): void
    {
        $user = $this->createPlatformAdmin();

        $centerA = Center::factory()->create([
            'name' => 'Alpha Language Center',
        ]);

        $centerB = Center::factory()->create([
            'name' => 'Beta Language Center',
        ]);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.platform.centers.index')
        );

        $response->assertOk();

        $response->assertJsonCount(2, 'data');

        $response->assertJsonFragment([
            'id' => $centerA->id,
            'slug' => $centerA->slug,
        ]);

        $response->assertJsonFragment([
            'id' => $centerB->id,
            'slug' => $centerB->slug,
        ]);

        // A platform admin does not need a membership in either center.
        $this->assertDatabaseCount('center_user', 0);
    }

    public function test_regular_user_cannot_list_platform_centers(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.platform.centers.index')
        );

        $response->assertForbidden();

        $response->assertJsonPath(
            'code',
            'SUPER_ADMIN_REQUIRED'
        );
    }

    public function test_unauthenticated_user_cannot_list_platform_centers(): void
    {
        $response = $this->getJson(
            route('api.platform.centers.index')
        );

        $response->assertUnauthorized();
    }

    public function test_platform_admin_can_search_and_filter_centers(): void
    {
        $user = $this->createPlatformAdmin();

        Center::factory()->create([
            'name' => 'Alpha Language Center',
            'status' => 'active',
        ]);

        Center::factory()->create([
            'name' => 'Beta Language Center',
            'status' => 'suspended',
        ]);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.platform.centers.index', [
                'search' => 'Alpha',
                'status' => 'active',
            ])
        );

        $response->assertOk();

        $response->assertJsonCount(1, 'data');

        $response->assertJsonPath(
            'data.0.name',
            'Alpha Language Center'
        );

        $response->assertJsonPath(
            'data.0.status',
            'active'
        );
    }

    public function test_platform_admin_can_list_all_course_states_in_selected_center(): void
    {
        $user = $this->createPlatformAdmin();

        $center = Center::factory()->create();

        $otherCenter = Center::factory()->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = Level::factory()
            ->for($center)
            ->create();

        $draft = $this->createCourse(
            $center,
            $language,
            $level,
            'draft',
            'Draft English A1'
        );

        $published = $this->createCourse(
            $center,
            $language,
            $level,
            'published',
            'Published English A2'
        );

        $archived = $this->createCourse(
            $center,
            $language,
            $level,
            'archived',
            'Archived English B1'
        );

        $otherLanguage = Language::factory()
            ->for($otherCenter)
            ->create();

        $otherLevel = Level::factory()
            ->for($otherCenter)
            ->create();

        $otherCourse = $this->createCourse(
            $otherCenter,
            $otherLanguage,
            $otherLevel,
            'published',
            'Other Center Course'
        );

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.platform.centers.courses.index', [
                'center' => $center->slug,
            ])
        );

        $response->assertOk();

        $response->assertJsonPath(
            'center.id',
            $center->id
        );

        $response->assertJsonCount(
            3,
            'courses.data'
        );

        $response->assertJsonFragment([
            'id' => $draft->id,
            'status' => 'draft',
        ]);

        $response->assertJsonFragment([
            'id' => $published->id,
            'status' => 'published',
        ]);

        $response->assertJsonFragment([
            'id' => $archived->id,
            'status' => 'archived',
        ]);

        $response->assertJsonMissing([
            'id' => $otherCourse->id,
        ]);

        // Verify tenant scoping at the database relationship level.
        $this->assertSame(
            3,
            $center->courses()->count()
        );
    }

    public function test_platform_admin_can_filter_courses_by_status(): void
    {
        $user = $this->createPlatformAdmin();

        $center = Center::factory()->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = Level::factory()
            ->for($center)
            ->create();

        $this->createCourse(
            $center,
            $language,
            $level,
            'draft',
            'Draft English A1'
        );

        $this->createCourse(
            $center,
            $language,
            $level,
            'archived',
            'Archived English B1'
        );

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.platform.centers.courses.index', [
                'center' => $center->slug,
                'status' => 'archived',
            ])
        );

        $response->assertOk();

        $response->assertJsonCount(
            1,
            'courses.data'
        );

        $response->assertJsonPath(
            'courses.data.0.status',
            'archived'
        );
    }

    public function test_regular_user_cannot_list_courses_through_platform_api(): void
    {
        $center = Center::factory()->create();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.platform.centers.courses.index', [
                'center' => $center->slug,
            ])
        );

        $response->assertForbidden();
    }

    public function test_platform_admin_can_view_course_details_and_content_counts(): void
    {
        $user = $this->createPlatformAdmin();
        $center = Center::factory()->create();
        $language = Language::factory()->for($center)->create();
        $level = Level::factory()->for($center)->create();
        $course = $this->createCourse(
            $center,
            $language,
            $level,
            'published',
            'Course Details Overview'
        );

        $module = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create(['title' => 'Module One']);

        $publishedLesson = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create(['title' => 'Published Lesson']);
        $draftLesson = Lesson::factory()
            ->forModule($module)
            ->create(['title' => 'Draft Lesson']);
        $archivedLesson = Lesson::factory()
            ->forModule($module)
            ->archived()
            ->create(['title' => 'Archived Lesson']);

        $asset = LessonAsset::factory()
            ->forLesson($publishedLesson)
            ->image()
            ->create(['storage_key' => 'private/course-cover-not-to-leak.jpg']);

        Exercise::factory()
            ->forLesson($publishedLesson)
            ->create();

        $token = $this->tokenFor($user);
        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.platform.centers.courses.show', [
                'center' => $center->slug,
                'courseSlug' => $course->slug,
            ])
        );

        $response->assertOk()
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonPath('data.course.language.id', $language->id)
            ->assertJsonPath('data.course.level.id', $level->id)
            ->assertJsonPath('data.statistics.modules', 1)
            ->assertJsonPath('data.statistics.lessons', 3)
            ->assertJsonPath('data.statistics.published_lessons', 1)
            ->assertJsonPath('data.statistics.draft_lessons', 1)
            ->assertJsonPath('data.statistics.archived_lessons', 1)
            ->assertJsonPath('data.statistics.assets', 1)
            ->assertJsonPath('data.statistics.exercises', 1)
            ->assertJsonPath('data.modules.0.lesson_count', 3)
            ->assertJsonPath('data.modules.0.published_lesson_count', 1)
            ->assertJsonPath('data.modules.0.lessons.0.id', $publishedLesson->id)
            ->assertJsonPath('data.modules.0.lessons.1.id', $draftLesson->id)
            ->assertJsonPath('data.modules.0.lessons.2.id', $archivedLesson->id)
            ->assertJsonPath('data.modules.0.lessons.0.assets_count', 1)
            ->assertJsonPath('data.modules.0.lessons.0.exercises_count', 1)
            ->assertJsonMissing(['storage_key' => $asset->storage_key]);
    }

    public function test_platform_admin_can_edit_course_and_change_publication_state(): void
    {
        $user = $this->createPlatformAdmin();
        $center = Center::factory()->create();
        $language = Language::factory()->for($center)->create();
        $level = Level::factory()->for($center)->create();
        $replacementLanguage = Language::factory()->french()->for($center)->create();
        $replacementLevel = Level::factory()->for($center)->create();
        $course = $this->createCourse(
            $center,
            $language,
            $level,
            'draft',
            'Course Before Edit'
        );
        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->putJson(
            route('api.platform.centers.courses.update', [
                'center' => $center->slug,
                'courseSlug' => $course->slug,
            ]),
            [
                'title' => 'Course After Edit',
                'slug' => 'course-after-edit',
                'short_description' => 'A concise course summary.',
                'description' => 'Full course description.',
                'language_id' => $replacementLanguage->id,
                'level_id' => $replacementLevel->id,
                'source_locale' => 'fr',
                'study_format' => 'hybrid',
                'duration_hours' => 24,
                'cover_image_url' => 'https://media.example.test/course.jpg',
                'cover_image_alt' => 'Students in a language classroom',
                'status' => 'published',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('data.title', 'Course After Edit')
            ->assertJsonPath('data.slug', 'course-after-edit')
            ->assertJsonPath('data.language.id', $replacementLanguage->id)
            ->assertJsonPath('data.level.id', $replacementLevel->id)
            ->assertJsonPath('data.status', 'published');

        $course->refresh();
        $this->assertNotNull($course->published_at);
        $this->assertSame('https://media.example.test/course.jpg', $course->cover_image_url);

        $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->putJson(
            route('api.platform.centers.courses.update', [
                'center' => $center->slug,
                'courseSlug' => $course->slug,
            ]),
            ['status' => 'draft']
        )->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null);

        $otherCenter = Center::factory()->create();
        $otherLanguage = Language::factory()->for($otherCenter)->create();
        $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->putJson(
            route('api.platform.centers.courses.update', [
                'center' => $center->slug,
                'courseSlug' => $course->slug,
            ]),
            ['language_id' => $otherLanguage->id]
        )->assertUnprocessable();
    }

    public function test_non_platform_admin_cannot_view_or_edit_course_details(): void
    {
        $center = Center::factory()->create();
        $language = Language::factory()->for($center)->create();
        $level = Level::factory()->for($center)->create();
        $course = $this->createCourse(
            $center,
            $language,
            $level,
            'draft',
            'Private Course'
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $token = $this->tokenFor($user);

        $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.platform.centers.courses.show', [
                'center' => $center->slug,
                'courseSlug' => $course->slug,
            ])
        )->assertForbidden();

        $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->putJson(
            route('api.platform.centers.courses.update', [
                'center' => $center->slug,
                'courseSlug' => $course->slug,
            ]),
            ['title' => 'Unauthorized edit']
        )->assertForbidden();
    }
}
