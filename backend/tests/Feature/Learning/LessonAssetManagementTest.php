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
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\LessonRevisionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

    private function configureCloudflare(): void
    {
        config([
            'services.cloudflare_stream.account_id' => 'test-account',
            'services.cloudflare_stream.api_token' => 'test-private-api-token',
            'services.cloudflare_stream.customer_code' => 'test-customer',
            'services.cloudflare_stream.allowed_origins' => [
                'http://localhost:3000',
                'https://learn.example.test',
            ],
            'services.cloudflare_stream.max_duration_seconds' => 3600,
            'services.cloudflare_stream.upload_expiry_minutes' => 30,
            'services.cloudflare_stream.max_upload_bytes' => 5368709120,
            'services.cloudflare_stream.tus_chunk_size_bytes' => 52428800,
            'services.cloudflare_stream.webhook_secret' => 'test-webhook-secret',
            'services.cloudflare_stream.webhook_tolerance_seconds' => 300,
        ]);
    }

    private function fakePng(
        string $name = 'learning.png'
    ): UploadedFile {
        $chunk = static function (
            string $type,
            string $data
        ): string {
            $payload = $type.$data;

            return pack('N', strlen($data))
                .$payload
                .pack('N', crc32($payload));
        };

        $header = pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0);

        $pixels = gzcompress("\x00\x22\x66\xAA\xFF");

        $png = "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', $header)
            .$chunk('IDAT', $pixels)
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent(
            $name,
            $png
        );
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
                'file' => $this->fakePng('greetings.png'),
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
                'file' => $this->fakePng('learning.png'),
            ]
        );

        $response->assertCreated();
    }

    public function test_center_admin_can_request_a_private_basic_video_upload(): void
    {
        $this->configureCloudflare();
        $context = $this->createContext();
        $uid = '0123456789abcdef0123456789abcdef';

        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/test-account/stream/direct_upload' => Http::response([
                'success' => true,
                'result' => [
                    'uid' => $uid,
                    'uploadURL' => 'https://upload.videodelivery.net/one-time-upload-token',
                ],
            ]),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->postJson(
            route(
                'api.centers.admin.learning.assets.video-upload',
                $this->assetRouteParameters($context)
            ),
            [
                'title' => 'English introduction',
                'original_filename' => 'introduction.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 12000000,
                'resumable' => false,
            ]
        );

        $response->assertCreated();
        $response->assertJsonPath('data.protocol', 'basic');
        $response->assertJsonPath('data.asset.status', LessonAsset::STATUS_PROCESSING);
        $response->assertJsonPath('data.asset.asset_type', LessonAsset::TYPE_VIDEO);
        $response->assertJsonPath('data.upload_url', 'https://upload.videodelivery.net/one-time-upload-token');
        $response->assertJsonMissingPath('data.asset.provider_asset_id');
        $response->assertJsonMissingPath('data.asset.storage_key');
        $this->assertStringNotContainsString($uid, $response->getContent());
        $this->assertStringNotContainsString('test-private-api-token', $response->getContent());

        $asset = LessonAsset::query()->firstOrFail();
        $this->assertSame($uid, $asset->provider_asset_id);
        $this->assertSame('cloudflare_stream', $asset->video_provider);
        $this->assertSame(12000000, $asset->size_bytes);
        $this->assertSame(LessonAsset::STATUS_PROCESSING, $asset->status);

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/stream/direct_upload')
                && $request->hasHeader('Authorization', 'Bearer test-private-api-token')
                && ($body['maxDurationSeconds'] ?? null) === 3600
                && ($body['requireSignedURLs'] ?? null) === true
                && ($body['allowedOrigins'] ?? null) === [
                    'http://localhost:3000',
                    'https://learn.example.test',
                ];
        });
    }

    public function test_unauthenticated_user_cannot_request_a_video_upload_url(): void
    {
        $context = $this->createContext();

        $response = $this->postJson(
            route(
                'api.centers.admin.learning.assets.video-upload',
                $this->assetRouteParameters($context)
            ),
            [
                'original_filename' => 'private-video.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 1000000,
            ]
        );

        $response->assertUnauthorized();
        $this->assertDatabaseCount('lesson_assets', 0);
    }

    public function test_large_video_uses_cloudflare_tus_and_documented_headers(): void
    {
        $this->configureCloudflare();
        $context = $this->createContext();
        $uid = 'abcdef0123456789abcdef0123456789';

        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/test-account/stream?direct_user=true' => Http::response('', 201, [
                'Location' => 'https://api.cloudflare.com/client/v4/accounts/test-account/stream/'.$uid,
                'stream-media-id' => $uid,
            ]),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->postJson(
            route(
                'api.centers.admin.learning.assets.video-upload',
                $this->assetRouteParameters($context)
            ),
            [
                'title' => 'Long lesson',
                'original_filename' => 'long-lesson.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 314572800,
                'resumable' => false,
            ]
        );

        $response->assertCreated();
        $response->assertJsonPath('data.protocol', 'tus');
        $response->assertJsonPath('data.chunk_size_bytes', 52428800);
        $response->assertJsonMissingPath('data.asset.provider_asset_id');

        Http::assertSent(function (ClientRequest $request): bool {
            $metadata = $request->header('Upload-Metadata')[0] ?? '';

            return str_contains($request->url(), '?direct_user=true')
                && $request->hasHeader('Authorization', 'Bearer test-private-api-token')
                && $request->hasHeader('Tus-Resumable', '1.0.0')
                && $request->hasHeader('Upload-Length', '314572800')
                && str_contains($metadata, 'maxDurationSeconds ')
                && str_contains($metadata, 'requiresignedurls')
                && str_contains($metadata, 'allowedorigins ');
        });
    }

    public function test_cloudflare_api_failure_does_not_create_a_video_asset(): void
    {
        $this->configureCloudflare();
        $context = $this->createContext();
        Http::fake([
            '*' => Http::response(['success' => false], 500),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->postJson(
            route(
                'api.centers.admin.learning.assets.video-upload',
                $this->assetRouteParameters($context)
            ),
            [
                'title' => 'Unavailable video',
                'original_filename' => 'unavailable.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 1000000,
            ]
        );

        $response->assertServiceUnavailable();
        $this->assertDatabaseCount('lesson_assets', 0);
    }

    public function test_video_status_polling_activates_only_fully_ready_cloudflare_media(): void
    {
        $this->configureCloudflare();
        $context = $this->createContext();
        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->video()
            ->create([
                'status' => LessonAsset::STATUS_PROCESSING,
                'size_bytes' => 15000000,
                'duration_seconds' => null,
            ]);

        Http::fake([
            '*' => Http::response([
                'success' => true,
                'result' => [
                    'uid' => $asset->provider_asset_id,
                    'readyToStream' => true,
                    'status' => ['state' => 'ready', 'pctComplete' => '100.000000'],
                    'size' => 14900000,
                    'duration' => 81.7,
                ],
            ]),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->getJson(
            route(
                'api.centers.admin.learning.assets.status',
                [
                    ...$this->assetRouteParameters($context),
                    'asset' => $asset->id,
                ]
            )
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', LessonAsset::STATUS_ACTIVE);
        $response->assertJsonPath('data.asset.status', LessonAsset::STATUS_ACTIVE);
        $response->assertJsonPath('data.progress', 100);
        $response->assertJsonMissingPath('data.asset.provider_asset_id');
        $this->assertStringNotContainsString($asset->provider_asset_id, $response->getContent());

        $asset->refresh();
        $this->assertSame(14900000, $asset->size_bytes);
        $this->assertSame(82, $asset->duration_seconds);
    }

    public function test_video_remains_processing_until_every_cloudflare_quality_is_ready(): void
    {
        $this->configureCloudflare();
        $context = $this->createContext();
        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->video()
            ->create(['status' => LessonAsset::STATUS_PROCESSING]);

        Http::fake([
            '*' => Http::response([
                'success' => true,
                'result' => [
                    'readyToStream' => true,
                    'status' => ['state' => 'ready', 'pctComplete' => '97.5'],
                ],
            ]),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->getJson(
            route(
                'api.centers.admin.learning.assets.status',
                [
                    ...$this->assetRouteParameters($context),
                    'asset' => $asset->id,
                ]
            )
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', LessonAsset::STATUS_PROCESSING);
        $response->assertJsonPath('data.progress', 97.5);
        $this->assertSame(LessonAsset::STATUS_PROCESSING, $asset->refresh()->status);
    }

    public function test_cloudflare_processing_error_marks_video_failed_with_sanitized_code(): void
    {
        $this->configureCloudflare();
        $context = $this->createContext();
        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->video()
            ->create(['status' => LessonAsset::STATUS_PROCESSING]);

        Http::fake([
            '*' => Http::response([
                'success' => true,
                'result' => [
                    'readyToStream' => false,
                    'status' => [
                        'state' => 'error',
                        'pctComplete' => '62',
                        'errorReasonCode' => 'ERR_MALFORMED_VIDEO',
                        'errorReasonText' => 'Do not expose this provider text.',
                    ],
                ],
            ]),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->getJson(
            route(
                'api.centers.admin.learning.assets.status',
                [
                    ...$this->assetRouteParameters($context),
                    'asset' => $asset->id,
                ]
            )
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', LessonAsset::STATUS_FAILED);
        $response->assertJsonPath('data.error_code', 'ERR_MALFORMED_VIDEO');
        $this->assertStringNotContainsString('Do not expose this provider text.', $response->getContent());
        $this->assertSame(LessonAsset::STATUS_FAILED, $asset->refresh()->status);
    }

    public function test_archived_cloudflare_video_is_restored_only_when_fully_ready(): void
    {
        $this->configureCloudflare();
        $context = $this->createContext();
        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->video()
            ->archived()
            ->create();

        Http::fake([
            '*' => Http::response([
                'success' => true,
                'result' => [
                    'readyToStream' => true,
                    'status' => ['state' => 'ready', 'pctComplete' => '100'],
                    'size' => 6000000,
                    'duration' => 31,
                ],
            ]),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->patchJson(
            route(
                'api.centers.admin.learning.assets.restore',
                [
                    ...$this->assetRouteParameters($context),
                    'asset' => $asset->id,
                ]
            )
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', LessonAsset::STATUS_ACTIVE);
        $response->assertJsonMissingPath('data.provider_asset_id');
        $this->assertSame(LessonAsset::STATUS_ACTIVE, $asset->refresh()->status);
        $this->assertSame(31, $asset->duration_seconds);
    }

    public function test_cloudflare_webhook_requires_a_fresh_valid_signature_and_is_idempotent(): void
    {
        $this->configureCloudflare();
        $context = $this->createContext();
        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->video()
            ->create(['status' => LessonAsset::STATUS_PROCESSING]);
        $rawBody = json_encode(['uid' => $asset->provider_asset_id], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$rawBody,
            'test-webhook-secret'
        );
        $url = route('api.webhooks.cloudflare.stream');

        $invalid = $this->call(
            'POST',
            $url,
            [],
            [],
            [],
            [
                'HTTP_WEBHOOK_SIGNATURE' => 'time='.$timestamp.',sig1='.str_repeat('0', 64),
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );
        $invalid->assertForbidden();
        $this->assertSame(LessonAsset::STATUS_PROCESSING, $asset->refresh()->status);

        $staleTimestamp = (string) now()->subSeconds(301)->timestamp;
        $staleSignature = hash_hmac(
            'sha256',
            $staleTimestamp.'.'.$rawBody,
            'test-webhook-secret'
        );
        $expired = $this->call(
            'POST',
            $url,
            [],
            [],
            [],
            [
                'HTTP_WEBHOOK_SIGNATURE' => 'time='.$staleTimestamp.',sig1='.$staleSignature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );
        $expired->assertForbidden();

        Http::fake([
            '*' => Http::response([
                'success' => true,
                'result' => [
                    'readyToStream' => true,
                    'status' => ['state' => 'ready', 'pctComplete' => '100'],
                    'size' => 7000000,
                    'duration' => 45,
                ],
            ]),
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $valid = $this->call(
                'POST',
                $url,
                [],
                [],
                [],
                [
                    'HTTP_WEBHOOK_SIGNATURE' => 'time='.$timestamp.',sig1='.$signature,
                    'CONTENT_TYPE' => 'application/json',
                ],
                $rawBody
            );
            $valid->assertOk();
            $valid->assertJsonPath('data.status', LessonAsset::STATUS_ACTIVE);
        }

        $this->assertSame(LessonAsset::STATUS_ACTIVE, $asset->refresh()->status);
        Http::assertSentCount(2);
    }

    public function test_processing_video_cannot_be_inserted_into_a_lesson_draft(): void
    {
        $context = $this->createContext();
        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->video()
            ->create(['status' => LessonAsset::STATUS_PROCESSING]);
        $revision = app(LessonRevisionService::class)->createDraft(
            $context['lesson'],
            $context['user']
        );

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->postJson(
            route('api.centers.admin.learning.content.blocks.media.store', [
                ...$this->assetRouteParameters($context),
                'revision' => $revision->id,
            ]),
            ['asset_id' => $asset->id]
        );

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('lesson_blocks', [
            'lesson_asset_id' => $asset->id,
        ]);
    }

    public function test_lesson_with_processing_video_cannot_be_published(): void
    {
        $context = $this->createContext();
        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->video()
            ->create(['status' => LessonAsset::STATUS_PROCESSING]);
        $revision = app(LessonRevisionService::class)->createDraft(
            $context['lesson'],
            $context['user']
        );

        LessonBlock::query()->create([
            'center_id' => $context['center']->id,
            'lesson_id' => $context['lesson']->id,
            'lesson_revision_id' => $revision->id,
            'block_type' => LessonBlock::TYPE_MEDIA,
            'sort_order' => 0,
            'content_json' => null,
            'lesson_asset_id' => $asset->id,
            'exercise_id' => null,
            'settings' => null,
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->tokenFor($context['user'])
        )->postJson(
            route(
                'api.centers.admin.learning.content.publish',
                $this->assetRouteParameters($context)
            )
        );

        $response->assertUnprocessable();
        $this->assertDatabaseHas('lesson_revisions', [
            'id' => $revision->id,
            'status' => LessonRevision::STATUS_DRAFT,
        ]);
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

    public function test_admin_cannot_modify_or_archive_asset_used_by_published_revision(): void
    {
        $context = $this->createContext();

        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->privateFile()
            ->create();

        $revisionService = app(LessonRevisionService::class);

        // Create a draft revision.
        $revision = $revisionService->createDraft(
            $context['lesson'],
            $context['user']
        );

        // Attach the asset to the draft.
        $revisionService->addMediaBlock(
            $revision,
            $asset->id
        );

        // Publish the revision.
        $revisionService->publish(
            $context['lesson'],
            $context['user']
        );

        $token = $this->tokenFor($context['user']);

        // Updating a published asset must be rejected.
        $updateResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.assets.update',
                [
                    ...$this->assetRouteParameters($context),
                    'asset' => $asset->id,
                ]
            ),
            [
                'title' => 'Modified published asset',
            ]
        );

        $updateResponse->assertUnprocessable();

        // Archiving a published asset must also be rejected.
        $archiveResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.assets.archive',
                [
                    ...$this->assetRouteParameters($context),
                    'asset' => $asset->id,
                ]
            )
        );

        $archiveResponse->assertUnprocessable();

        // Verify that the original asset remains unchanged.
        $this->assertDatabaseHas('lesson_assets', [
            'id' => $asset->id,
            'title' => $asset->title,
            'status' => LessonAsset::STATUS_ACTIVE,
        ]);
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

        $activeAsset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        LessonBlock::query()->create([
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'lesson_revision_id' => $revision->id,
            'block_type' => LessonBlock::TYPE_MEDIA,
            'sort_order' => 1,
            'content_json' => null,
            'lesson_asset_id' => $activeAsset->id,
            'exercise_id' => null,
            'settings' => null,
        ]);

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
