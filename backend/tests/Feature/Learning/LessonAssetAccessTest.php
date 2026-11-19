<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class LessonAssetAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Create a published course and an enrolled student.
     *
     * @return array<string, mixed>
     */
    private function createLearningContext(
        bool $withEnrollment = true
    ): array {
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

        $enrollment = null;

        if ($withEnrollment) {
            $enrollment = Enrollment::factory()
                ->forStudentProfile($studentProfile)
                ->forCohort($cohort)
                ->create([
                    'start_at' => now()->subDay(),
                    'end_at' => now()->addMonth(),
                ]);
        }

        $module = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create();

        $lesson = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create();

        $revision = LessonRevision::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'version_number' => 1,
            'status' => LessonRevision::STATUS_PUBLISHED,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'content_locale' => $lesson->content_locale,
            'duration_minutes' => $lesson->duration_minutes,
            'created_by_user_id' => null,
            'published_by_user_id' => null,
            'published_at' => $lesson->published_at,
        ]);

        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $revision->id,
            'block_type' => LessonBlock::TYPE_TEXT,
            'sort_order' => 0,
            'content_json' => [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Published lesson content.',
                            ],
                        ],
                    ],
                ],
            ],
            'lesson_asset_id' => null,
            'exercise_id' => null,
            'settings' => null,
        ]);

        $lesson->forceFill([
            'published_revision_id' => $revision->id,
        ])->save();

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

    private function tokenFor(User $user): string
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard->login($user);
    }

    private function assetAccessUrl(
        Center $center,
        Course $course,
        Lesson $lesson,
        LessonAsset $asset
    ): string {
        return route(
            'api.centers.courses.learn.lessons.assets.access',
            [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
                'asset' => $asset->id,
            ]
        );
    }

    private function linkAssetToPublishedRevision(
        Lesson $lesson,
        LessonAsset $asset
    ): void {
        $revision = LessonRevision::query()
            ->where('center_id', $lesson->center_id)
            ->where('lesson_id', $lesson->id)
            ->whereKey($lesson->published_revision_id)
            ->where('status', LessonRevision::STATUS_PUBLISHED)
            ->firstOrFail();

        LessonBlock::query()->create([
            'center_id' => $lesson->center_id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $revision->id,
            'block_type' => LessonBlock::TYPE_MEDIA,
            'sort_order' => 1,
            'content_json' => null,
            'lesson_asset_id' => $asset->id,
            'exercise_id' => null,
            'settings' => null,
        ]);
    }

    public function test_enrolled_student_can_get_private_asset_preview_url(): void
    {
        Storage::fake('private');

        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        $this->linkAssetToPublishedRevision($lesson, $asset);

        Storage::disk('private')->put(
            $asset->storage_key,
            'Test private learning document.'
        );

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertOk();
        $response->assertJsonPath('data.type', 'stored_asset');
        $response->assertJsonPath('data.asset_id', $asset->id);
        $response->assertJsonPath('data.can_download', true);
        $this->assertNotEmpty($response->json('data.url'));
        $this->assertNotNull($response->json('data.expires_at'));
        $response->assertJsonMissingPath('data.storage_key');
    }

    public function test_student_can_download_an_authorized_downloadable_asset(): void
    {
        Storage::fake('private');

        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create([
                'is_downloadable' => true,
                'original_filename' => 'worksheet.pdf',
            ]);

        $this->linkAssetToPublishedRevision($lesson, $asset);

        Storage::disk('private')->put(
            $asset->storage_key,
            'Test download content.'
        );

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->get(
            route('api.centers.courses.learn.lessons.assets.download', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
                'asset' => $asset->id,
            ])
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('Content-Disposition')
        );
    }

    public function test_student_cannot_download_asset_without_permission(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create([
                'is_downloadable' => false,
            ]);

        $this->linkAssetToPublishedRevision($lesson, $asset);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->get(
            route('api.centers.courses.learn.lessons.assets.download', [
                'center' => $center->slug,
                'course' => $course->slug,
                'lesson' => $lesson->id,
                'asset' => $asset->id,
            ])
        );

        $response->assertForbidden();
    }

    public function test_student_cannot_access_asset_from_another_lesson(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'module' => $module,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $otherLesson = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create();

        $otherAsset = LessonAsset::factory()
            ->forLesson($otherLesson)
            ->privateFile()
            ->create();

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $otherAsset
            )
        );

        $response->assertNotFound();
    }

    public function test_student_cannot_access_storage_path_with_traversal_segments(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create([
                'storage_key' => '\\..\\outside.pdf',
            ]);

        $this->linkAssetToPublishedRevision($lesson, $asset);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertNotFound();
    }

    public function test_student_receives_only_valid_https_external_links(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->externalLink()
            ->create();

        $this->linkAssetToPublishedRevision($lesson, $asset);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertOk();
        $response->assertJsonPath(
            'data.url',
            'https://example.com/learning-resource'
        );
    }

    public function test_student_cannot_access_http_external_links(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->externalLink()
            ->create([
                'external_url' => 'http://example.com/learning-resource',
            ]);

        $this->linkAssetToPublishedRevision($lesson, $asset);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertNotFound();
    }

    public function test_student_without_enrollment_cannot_access_asset(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext(false);

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertForbidden();
    }

    public function test_cloudflare_video_returns_signed_playback_urls(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        config([
            'services.cloudflare_stream.account_id' => 'test-account',
            'services.cloudflare_stream.api_token' => 'test-api-token',
            'services.cloudflare_stream.customer_code' => 'test-code',
            'services.cloudflare_stream.token_ttl_minutes' => 10,
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'api.cloudflare.com/client/v4/accounts/*/stream/*/token' => Http::response([
                'success' => true,
                'result' => [
                    'token' => 'test-signed-video-token',
                ],
                'errors' => [],
            ], 200),
        ]);

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->video()
            ->create();

        $this->linkAssetToPublishedRevision($lesson, $asset);

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertOk();
        $response->assertJsonPath('data.provider', 'cloudflare_stream');
        $response->assertJsonPath(
            'data.player_url',
            'https://customer-test-code.cloudflarestream.com/test-signed-video-token/iframe'
        );
        $response->assertJsonPath(
            'data.hls_url',
            'https://customer-test-code.cloudflarestream.com/test-signed-video-token/manifest/video.m3u8'
        );
        $this->assertNotNull($response->json('data.expires_at'));
        $response->assertJsonMissing([
            'provider_asset_id' => $asset->provider_asset_id,
        ]);
    }

    public function test_student_cannot_access_active_asset_not_referenced_by_current_published_revision(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertNotFound();
    }

    public function test_student_cannot_access_asset_referenced_only_by_superseded_revision(): void
    {
        [
            'center' => $center,
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        $this->linkAssetToPublishedRevision($lesson, $asset);

        $oldRevision = LessonRevision::query()
            ->findOrFail($lesson->published_revision_id);

        $oldRevision->update([
            'status' => LessonRevision::STATUS_SUPERSEDED,
        ]);

        $currentRevision = LessonRevision::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'version_number' => 2,
            'status' => LessonRevision::STATUS_PUBLISHED,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'content_locale' => $lesson->content_locale,
            'duration_minutes' => $lesson->duration_minutes,
            'created_by_user_id' => null,
            'published_by_user_id' => null,
            'published_at' => now(),
        ]);

        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $currentRevision->id,
            'block_type' => LessonBlock::TYPE_TEXT,
            'sort_order' => 0,
            'content_json' => [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Updated published content.',
                            ],
                        ],
                    ],
                ],
            ],
            'lesson_asset_id' => null,
            'exercise_id' => null,
            'settings' => null,
        ]);

        $lesson->forceFill([
            'published_revision_id' => $currentRevision->id,
        ])->save();

        $token = $this->tokenFor($user);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_access_lesson_asset(): void
    {
        [
            'center' => $center,
            'course' => $course,
            'lesson' => $lesson,
        ] = $this->createLearningContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        $response = $this->getJson(
            $this->assetAccessUrl(
                $center,
                $course,
                $lesson,
                $asset
            )
        );

        $response->assertUnauthorized();
    }
}
