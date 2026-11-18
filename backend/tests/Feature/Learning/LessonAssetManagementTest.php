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
use App\Models\LessonAsset;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class LessonAssetManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Create a center admin or platform admin and a lesson.
     *
     * @return array{center: Center, user: User, course: Course, module: CourseModule, lesson: Lesson}
     */
    private function createContext(
        bool $platformAdmin = false
    ): array {
        $center = Center::factory()->create();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        if ($platformAdmin) {
            PlatformAdmin::create([
                'user_id' => $user->id,
            ]);
        } else {
            $membership = CenterUser::query()->create([
                'center_id' => $center->id,
                'user_id' => $user->id,
                'status' => 'active',
            ]);

            $role = Role::query()
                ->where('key', 'center_admin')
                ->firstOrFail();

            $membership->roles()->attach($role->id);
        }

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = Level::factory()
            ->for($center)
            ->create();

        $course = Course::factory()
            ->forCenter($center)
            ->create([
                'language_id' => $language->id,
                'level_id' => $level->id,
            ]);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create();

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create();

        return compact(
            'center',
            'user',
            'course',
            'module',
            'lesson'
        );
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard
            ->claims([
                'ver' => (int) $user->token_version,
            ])
            ->login($user);
    }

    /**
     * @param  array{center: Center, course: Course, lesson: Lesson}  $context
     * @return array{center: string, course: string, lesson: int}
     */
    private function assetRouteParameters(array $context): array
    {
        return [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
        ];
    }

    private function configurePrivateStorage(): void
    {
        Storage::fake('private');

        config([
            'learning.media.upload_disk' => 'private',
            'learning.media.allowed_disks' => ['private'],
        ]);
    }

    public function test_center_admin_can_upload_private_image(): void
    {
        $this->configurePrivateStorage();

        $context = $this->createContext();

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->post(
            route(
                'api.centers.admin.learning.assets.store',
                $this->assetRouteParameters($context)
            ),
            [
                'asset_type' => 'image',
                'title' => 'English Greetings',
                'alt_text' => 'Two people greeting each other',
                'caption' => 'A simple greeting illustration.',
                'file' => UploadedFile::fake()->image(
                    'greetings.jpg',
                    800,
                    600
                ),
            ]
        );

        $response->assertCreated();

        $response->assertJsonPath(
            'data.asset_type',
            LessonAsset::TYPE_IMAGE
        );

        $response->assertJsonPath(
            'data.status',
            LessonAsset::STATUS_ACTIVE
        );

        $response->assertJsonMissingPath(
            'data.storage_key'
        );

        $response->assertJsonMissingPath(
            'data.storage_disk'
        );

        $asset = LessonAsset::query()->firstOrFail();

        $this->assertSame('private', $asset->storage_disk);

        $this->assertFalse($asset->is_downloadable);

        Storage::disk('private')->assertExists(
            $asset->storage_key
        );
    }

    public function test_center_admin_can_upload_private_pdf(): void
    {
        $this->configurePrivateStorage();

        $context = $this->createContext();

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->post(
            route(
                'api.centers.admin.learning.assets.store',
                $this->assetRouteParameters($context)
            ),
            [
                'asset_type' => 'file',
                'title' => 'English Worksheet',
                'file' => UploadedFile::fake()->create(
                    'worksheet.pdf',
                    200,
                    'application/pdf'
                ),
            ]
        );

        $response->assertCreated();

        $response->assertJsonPath(
            'data.asset_type',
            LessonAsset::TYPE_FILE
        );

        $asset = LessonAsset::query()->firstOrFail();

        $this->assertTrue($asset->is_downloadable);

        Storage::disk('private')->assertExists(
            $asset->storage_key
        );
    }

    public function test_platform_admin_can_upload_without_center_membership(): void
    {
        $this->configurePrivateStorage();

        $context = $this->createContext(
            platformAdmin: true
        );

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->post(
            route(
                'api.centers.admin.learning.assets.store',
                $this->assetRouteParameters($context)
            ),
            [
                'asset_type' => 'image',
                'alt_text' => 'Learning illustration',
                'file' => UploadedFile::fake()->image(
                    'learning.png'
                ),
            ]
        );

        $response->assertCreated();
    }

    public function test_svg_upload_is_rejected(): void
    {
        $this->configurePrivateStorage();

        $context = $this->createContext();

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->post(
            route(
                'api.centers.admin.learning.assets.store',
                $this->assetRouteParameters($context)
            ),
            [
                'asset_type' => 'image',
                'alt_text' => 'Unsafe image',
                'file' => UploadedFile::fake()->create(
                    'malicious.svg',
                    10,
                    'image/svg+xml'
                ),
            ]
        );

        $response->assertUnprocessable();

        $this->assertDatabaseCount(
            'lesson_assets',
            0
        );
    }

    public function test_admin_can_list_archived_assets_without_storage_paths(): void
    {
        $context = $this->createContext();

        $active = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->image()
            ->create([
                'status' => LessonAsset::STATUS_ACTIVE,
            ]);

        $archived = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->privateFile()
            ->archived()
            ->create([
                'sort_order' => 2,
            ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->getJson(
            route(
                'api.centers.admin.learning.assets.index',
                $this->assetRouteParameters($context)
            )
        );

        $response->assertOk();

        $response->assertJsonCount(2, 'data');

        $response->assertJsonFragment([
            'id' => $active->id,
            'status' => 'active',
        ]);

        $response->assertJsonFragment([
            'id' => $archived->id,
            'status' => 'archived',
        ]);

        $response->assertJsonMissingPath(
            'data.0.storage_key'
        );
    }

    public function test_admin_can_update_archive_and_restore_asset(): void
    {
        $this->configurePrivateStorage();

        $context = $this->createContext();

        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->privateFile()
            ->create();

        Storage::disk('private')->put(
            $asset->storage_key,
            'Test private document.'
        );

        $token = $this->tokenFor($context['user']);

        $updateResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.assets.update',
                [...$this->assetRouteParameters($context), 'asset' => $asset->id]
            ),
            [
                'title' => 'Updated Worksheet',
                'caption' => 'Updated learning material.',
                'sort_order' => 3,
                'is_downloadable' => true,
            ]
        );

        $updateResponse->assertOk();

        $updateResponse->assertJsonPath(
            'data.title',
            'Updated Worksheet'
        );

        $this->assertDatabaseHas('lesson_assets', [
            'id' => $asset->id,
            'title' => 'Updated Worksheet',
            'sort_order' => 3,
        ]);

        $archiveResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.assets.archive',
                [...$this->assetRouteParameters($context), 'asset' => $asset->id]
            )
        );

        $archiveResponse->assertOk();

        $archiveResponse->assertJsonPath(
            'data.status',
            'archived'
        );

        $this->assertDatabaseHas('lesson_assets', [
            'id' => $asset->id,
            'status' => LessonAsset::STATUS_ARCHIVED,
        ]);

        Storage::disk('private')->assertExists(
            $asset->storage_key
        );

        $restoreResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.assets.restore',
                [...$this->assetRouteParameters($context), 'asset' => $asset->id]
            )
        );

        $restoreResponse->assertOk();

        $restoreResponse->assertJsonPath(
            'data.status',
            'active'
        );
    }

    public function test_regular_user_cannot_manage_lesson_assets(): void
    {
        $context = $this->createContext();

        $regularUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($regularUser)
        )->getJson(
            route(
                'api.centers.admin.learning.assets.index',
                $this->assetRouteParameters($context)
            )
        );

        $response->assertForbidden();
    }

    public function test_center_admin_cannot_manage_assets_in_another_center(): void
    {
        $context = $this->createContext();

        $otherCenter = Center::factory()->create();

        $otherLanguage = Language::factory()
            ->for($otherCenter)
            ->create();

        $otherLevel = Level::factory()
            ->for($otherCenter)
            ->create();

        $otherCourse = Course::factory()
            ->forCenter($otherCenter)
            ->create([
                'language_id' => $otherLanguage->id,
                'level_id' => $otherLevel->id,
            ]);

        $otherModule = CourseModule::factory()
            ->forCourse($otherCourse)
            ->create();

        $otherLesson = Lesson::factory()
            ->forModule($otherModule)
            ->create();

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->getJson(
            route(
                'api.centers.admin.learning.assets.index',
                [
                    'center' => $otherCenter->slug,
                    'course' => $otherCourse->slug,
                    'lesson' => $otherLesson->id,
                ]
            )
        );

        $response->assertForbidden();
    }

    public function test_admin_can_see_archived_asset_metadata_but_cannot_play_it(): void
    {
        $context = $this->createContext();

        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->privateFile()
            ->archived()
            ->create();

        $token = $this->tokenFor($context['user']);

        $lessonResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
            ])
        );

        $lessonResponse->assertOk();

        $lessonResponse->assertJsonPath(
            'data.assets.0.id',
            $asset->id
        );

        $lessonResponse->assertJsonPath(
            'data.assets.0.status',
            'archived'
        );

        $lessonResponse->assertJsonMissingPath(
            'data.assets.0.storage_key'
        );

        $accessResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route(
                'api.centers.courses.learn.lessons.assets.access',
                [
                    'center' => $context['center']->slug,
                    'course' => $context['course']->slug,
                    'lesson' => $context['lesson']->id,
                    'asset' => $asset->id,
                ]
            )
        );

        $accessResponse->assertNotFound();
    }

    public function test_student_receives_only_active_asset_metadata(): void
    {
        $center = Center::factory()->create();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $membership = CenterUser::query()->create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

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

        Enrollment::factory()
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

        $activeAsset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->archived()
            ->create();

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($user)
        )->getJson(
            route('api.centers.courses.learn.lessons.show', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
            ])
        );

        $response->assertOk();

        $response->assertJsonCount(1, 'data.assets');

        $response->assertJsonPath(
            'data.assets.0.id',
            $activeAsset->id
        );

        $response->assertJsonPath(
            'data.assets.0.status',
            LessonAsset::STATUS_ACTIVE
        );
    }
}
