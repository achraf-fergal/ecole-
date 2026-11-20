<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonRevision;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Services\Learning\LessonRevisionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class LessonContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Create a center admin or platform admin with a lesson.
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

    private function contentRouteParameters(array $context): array
    {
        return [
            'center' => $context['center']->slug,
            'course' => $context['course']->slug,
            'lesson' => $context['lesson']->id,
        ];
    }

    private function revisionRouteParameters(
        array $context,
        int $revisionId
    ): array {
        return [
            ...$this->contentRouteParameters($context),
            'revision' => $revisionId,
        ];
    }

    private function blockRouteParameters(
        array $context,
        int $revisionId,
        int $blockId
    ): array {
        return [
            ...$this->revisionRouteParameters(
                $context,
                $revisionId
            ),
            'block' => $blockId,
        ];
    }

    private function document(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $text,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createDraftThroughApi(
        array $context,
        string $token
    ): int {
        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route(
                'api.centers.admin.learning.content.revisions.store',
                $this->contentRouteParameters($context)
            )
        );

        $response->assertOk();

        return (int) $response->json('data.id');
    }

    public function test_center_admin_can_create_and_retrieve_draft(): void
    {
        $context = $this->createContext();

        $token = $this->tokenFor($context['user']);

        $revisionId = $this->createDraftThroughApi(
            $context,
            $token
        );

        // Creating a second draft must return the same revision.
        $secondRevisionId = $this->createDraftThroughApi(
            $context,
            $token
        );

        $this->assertSame(
            $revisionId,
            $secondRevisionId
        );

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route(
                'api.centers.admin.learning.content.show',
                $this->contentRouteParameters($context)
            )
        );

        $response->assertOk();

        $response->assertJsonPath(
            'data.draft_revision.id',
            $revisionId
        );

        $response->assertJsonPath(
            'data.draft_revision.status',
            LessonRevision::STATUS_DRAFT
        );

        $this->assertDatabaseCount('lesson_revisions', 1);
    }

    public function test_center_admin_can_update_draft_metadata(): void
    {
        $context = $this->createContext();

        $token = $this->tokenFor($context['user']);

        $revisionId = $this->createDraftThroughApi(
            $context,
            $token
        );

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.content.revisions.update',
                $this->revisionRouteParameters(
                    $context,
                    $revisionId
                )
            ),
            [
                'title' => 'Updated English Lesson',
                'description' => 'Updated lesson description.',
                'content_locale' => 'en',
                'duration_minutes' => 25,
            ]
        );

        $response->assertOk();

        $response->assertJsonPath(
            'data.title',
            'Updated English Lesson'
        );

        $response->assertJsonPath(
            'data.description',
            'Updated lesson description.'
        );

        $this->assertDatabaseHas('lesson_revisions', [
            'id' => $revisionId,
            'title' => 'Updated English Lesson',
            'content_locale' => 'en',
            'duration_minutes' => 25,
        ]);
    }

    public function test_center_admin_can_manage_text_blocks(): void
    {
        $context = $this->createContext();

        $token = $this->tokenFor($context['user']);

        $revisionId = $this->createDraftThroughApi(
            $context,
            $token
        );
        // Capture existing legacy blocks migrated into the new draft.
        $initialContentResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route(
                'api.centers.admin.learning.content.show',
                $this->contentRouteParameters($context)
            )
        );
        
        $initialContentResponse->assertOk();
        
        $initialBlockIds = collect(
            $initialContentResponse->json(
                'data.draft_revision.blocks',
                []
            )
        )->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        // First text block.
        $firstResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route(
                'api.centers.admin.learning.content.blocks.text.store',
                $this->revisionRouteParameters(
                    $context,
                    $revisionId
                )
            ),
            [
                'content_json' => $this->document('First block'),
            ]
        );

        $firstResponse->assertCreated();

        $firstBlockId = (int) $firstResponse->json('data.id');

        // Second text block.
        $secondResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route(
                'api.centers.admin.learning.content.blocks.text.store',
                $this->revisionRouteParameters(
                    $context,
                    $revisionId
                )
            ),
            [
                'content_json' => $this->document('Second block'),
            ]
        );

        $secondResponse->assertCreated();

        $secondBlockId = (int) $secondResponse->json('data.id');

        // Update the first block.
        $updateResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.content.blocks.update',
                $this->blockRouteParameters(
                    $context,
                    $revisionId,
                    $firstBlockId
                )
            ),
            [
                'content_json' => $this->document('Updated first block'),
            ]
        );

        $updateResponse->assertOk();

        $updateResponse->assertJsonPath(
            'data.content_json.content.0.content.0.text',
            'Updated first block'
        );

        // Reverse block ordering.
        $reorderResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->putJson(
            route(
                'api.centers.admin.learning.content.blocks.reorder',
                $this->revisionRouteParameters(
                    $context,
                    $revisionId
                )
            ),
            [
                'ordered_block_ids' => array_merge(
                    [
                        $secondBlockId,
                        $firstBlockId,
                    ],
                    $initialBlockIds
                ),
            ]
        );

        $reorderResponse->assertOk();

        $reorderResponse->assertJsonPath(
            'data.0.id',
            $secondBlockId
        );

        $reorderResponse->assertJsonPath(
            'data.1.id',
            $firstBlockId
        );

        // Delete one block.
        $deleteResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->deleteJson(
            route(
                'api.centers.admin.learning.content.blocks.destroy',
                $this->blockRouteParameters(
                    $context,
                    $revisionId,
                    $firstBlockId
                )
            )
        );

        $deleteResponse->assertOk();

        $this->assertDatabaseMissing('lesson_blocks', [
            'id' => $firstBlockId,
        ]);

        $this->assertDatabaseHas('lesson_blocks', [
            'id' => $secondBlockId,
        ]);
    }

    public function test_center_admin_can_add_media_and_exercise_blocks(): void
    {
        $context = $this->createContext();

        $token = $this->tokenFor($context['user']);

        $revisionId = $this->createDraftThroughApi(
            $context,
            $token
        );

        $asset = LessonAsset::factory()
            ->forLesson($context['lesson'])
            ->privateFile()
            ->create();

        $exercise = Exercise::query()->create([
            'center_id' => $context['center']->id,
            'lesson_id' => $context['lesson']->id,
            'title' => 'Basic Grammar',
            'instructions' => 'Choose the correct answer.',
            'max_attempts' => 3,
            'passing_score' => 60,
            'show_correct_answers_after_submission' => false,
            'sort_order' => 0,
            'status' => Exercise::STATUS_DRAFT,
            'published_at' => null,
        ]);

        // Add media.
        $mediaResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route(
                'api.centers.admin.learning.content.blocks.media.store',
                $this->revisionRouteParameters(
                    $context,
                    $revisionId
                )
            ),
            [
                'asset_id' => $asset->id,
                'settings' => [
                    'alignment' => 'center',
                ],
            ]
        );

        $mediaResponse->assertCreated();

        $mediaResponse->assertJsonPath(
            'data.block_type',
            'media'
        );

        $mediaResponse->assertJsonPath(
            'data.asset.id',
            $asset->id
        );

        // Add exercise.
        $exerciseResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route(
                'api.centers.admin.learning.content.blocks.exercise.store',
                $this->revisionRouteParameters(
                    $context,
                    $revisionId
                )
            ),
            [
                'exercise_id' => $exercise->id,
            ]
        );

        $exerciseResponse->assertCreated();

        $exerciseResponse->assertJsonPath(
            'data.block_type',
            'exercise'
        );

        $exerciseResponse->assertJsonPath(
            'data.exercise.id',
            $exercise->id
        );

        // Verify the media block.
        $this->assertDatabaseHas('lesson_blocks', [
            'center_id' => $context['center']->id,
            'lesson_id' => $context['lesson']->id,
            'lesson_revision_id' => $revisionId,
            'block_type' => 'media',
            'lesson_asset_id' => $asset->id,
        ]);
        
        // Verify the exercise block.
        $this->assertDatabaseHas('lesson_blocks', [
            'center_id' => $context['center']->id,
            'lesson_id' => $context['lesson']->id,
            'lesson_revision_id' => $revisionId,
            'block_type' => 'exercise',
            'exercise_id' => $exercise->id,
        ]);    }

    public function test_platform_admin_can_manage_another_centers_lesson(): void
    {
        $context = $this->createContext(
            platformAdmin: true
        );

        $token = $this->tokenFor($context['user']);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route(
                'api.centers.admin.learning.content.revisions.store',
                $this->contentRouteParameters($context)
            )
        );

        $response->assertOk();

        $response->assertJsonPath(
            'data.status',
            LessonRevision::STATUS_DRAFT
        );
    }

    public function test_regular_user_cannot_access_content_management(): void
    {
        $context = $this->createContext();

        $regularUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $token = $this->tokenFor($regularUser);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(
            route(
                'api.centers.admin.learning.content.show',
                $this->contentRouteParameters($context)
            )
        );

        $response->assertForbidden();
    }

    public function test_revision_from_another_lesson_is_not_accessible(): void
    {
        $context = $this->createContext();

        $otherLesson = Lesson::factory()
            ->forModule($context['module'])
            ->create();

        $otherRevision = app(LessonRevisionService::class)
            ->createDraft(
                $otherLesson,
                $context['user']
            );

        $token = $this->tokenFor($context['user']);

        // Use the other lesson's revision against the original lesson.
        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.content.revisions.update',
                $this->revisionRouteParameters(
                    $context,
                    $otherRevision->id
                )
            ),
            [
                'title' => 'Unauthorized revision modification',
            ]
        );

        $response->assertNotFound();
    }

    public function test_published_revision_cannot_be_modified_through_api(): void
    {
        $context = $this->createContext();

        $token = $this->tokenFor($context['user']);

        $service = app(LessonRevisionService::class);

        $revision = $service->createDraft(
            $context['lesson'],
            $context['user']
        );

        $block = $service->addTextBlock(
            $revision,
            $this->document('Published content')
        );

        $publishedRevision = $service->publish(
            $context['lesson'],
            $context['user']
        );

        // Metadata modification must fail.
        $metadataResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.content.revisions.update',
                $this->revisionRouteParameters(
                    $context,
                    $publishedRevision->id
                )
            ),
            [
                'title' => 'Illegal modification',
            ]
        );

        $metadataResponse->assertUnprocessable();

        // Block modification must also fail.
        $blockResponse = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.content.blocks.update',
                $this->blockRouteParameters(
                    $context,
                    $publishedRevision->id,
                    $block->id
                )
            ),
            [
                'content_json' => $this->document('Illegal update'),
            ]
        );

        $blockResponse->assertUnprocessable();

        // Original published data must remain unchanged.
        $this->assertDatabaseHas('lesson_revisions', [
            'id' => $publishedRevision->id,
            'title' => $publishedRevision->title,
            'status' => LessonRevision::STATUS_PUBLISHED,
        ]);
    }

    public function test_center_admin_can_publish_draft_revision(): void
    {
        $context = $this->createContext();
        $token = $this->tokenFor($context['user']);

        /** @var LessonRevisionService $service */
        $service = app(LessonRevisionService::class);

        $draft = $service->createDraft(
            $context['lesson'],
            $context['user']
        );

        $service->addTextBlock(
            $draft,
            $this->document('Ready to publish')
        );

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route('api.centers.admin.learning.content.publish', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
            ])
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', LessonRevision::STATUS_PUBLISHED);
        $response->assertJsonPath('data.id', $draft->id);

        $freshLesson = $context['lesson']->fresh();
        $this->assertNull($freshLesson->draft_revision_id);
        $this->assertSame($draft->id, $freshLesson->published_revision_id);
    }

    public function test_publishing_empty_draft_revision_fails(): void
    {
        $context = $this->createContext();
        $context['lesson']->update(['body' => null]);
        $token = $this->tokenFor($context['user']);

        /** @var LessonRevisionService $service */
        $service = app(LessonRevisionService::class);

        $service->createDraft(
            $context['lesson'],
            $context['user']
        );

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route('api.centers.admin.learning.content.publish', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
            ])
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['blocks']);
    }

    public function test_publishing_without_current_draft_fails(): void
    {
        $context = $this->createContext();
        $token = $this->tokenFor($context['user']);

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route('api.centers.admin.learning.content.publish', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
            ])
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['revision']);
    }

    public function test_previous_published_revision_becomes_superseded_on_new_publication(): void
    {
        $context = $this->createContext();
        $token = $this->tokenFor($context['user']);

        /** @var LessonRevisionService $service */
        $service = app(LessonRevisionService::class);

        $draft1 = $service->createDraft(
            $context['lesson'],
            $context['user']
        );
        $service->addTextBlock($draft1, $this->document('First edition'));

        $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route('api.centers.admin.learning.content.publish', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
            ])
        )->assertOk();

        // Now create revision 2 draft.
        $draft2 = $service->createDraft(
            $context['lesson']->fresh(),
            $context['user']
        );
        $service->addTextBlock($draft2, $this->document('Second edition'));

        // Publish via revision specific route.
        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route('api.centers.admin.learning.content.revisions.publish', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
                'revision' => $draft2->id,
            ])
        );

        $response->assertOk();
        $response->assertJsonPath('data.id', $draft2->id);

        $draft1->refresh();
        $draft2->refresh();

        $this->assertSame(LessonRevision::STATUS_SUPERSEDED, $draft1->status);
        $this->assertSame(LessonRevision::STATUS_PUBLISHED, $draft2->status);
    }

    public function test_publishing_with_mismatched_revision_route_param_fails(): void
    {
        $context = $this->createContext();
        $token = $this->tokenFor($context['user']);

        /** @var LessonRevisionService $service */
        $service = app(LessonRevisionService::class);

        $draft1 = $service->createDraft(
            $context['lesson'],
            $context['user']
        );
        $service->addTextBlock($draft1, $this->document('First edition'));
        $service->publish($context['lesson'], $context['user']);

        // Create draft 2.
        $draft2 = $service->createDraft(
            $context['lesson']->fresh(),
            $context['user']
        );
        $service->addTextBlock($draft2, $this->document('Second edition'));

        // Attempt to publish using draft1 id (which is superseded/published, not current draft).
        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route('api.centers.admin.learning.content.revisions.publish', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
                'revision' => $draft1->id,
            ])
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['revision']);
    }

    public function test_platform_admin_can_publish_lesson(): void
    {
        $context = $this->createContext(true);
        $token = $this->tokenFor($context['user']);

        /** @var LessonRevisionService $service */
        $service = app(LessonRevisionService::class);

        $draft = $service->createDraft(
            $context['lesson'],
            $context['user']
        );
        $service->addTextBlock($draft, $this->document('Platform Admin Content'));

        $response = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route('api.centers.admin.learning.content.publish', [
                'center' => $context['center']->slug,
                'course' => $context['course']->slug,
                'lesson' => $context['lesson']->id,
            ])
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', LessonRevision::STATUS_PUBLISHED);
    }

    public function test_center_admin_cannot_access_or_publish_another_center_content(): void
    {
        $contextA = $this->createContext();
        $contextB = $this->createContext();

        $tokenA = $this->tokenFor($contextA['user']);

        /** @var LessonRevisionService $service */
        $service = app(LessonRevisionService::class);
        $draftB = $service->createDraft($contextB['lesson'], $contextB['user']);
        $service->addTextBlock($draftB, $this->document('Center B Content'));

        // Admin A attempts to publish Center B's lesson
        $response = $this->withHeader(
            'Authorization',
            "Bearer {$tokenA}"
        )->postJson(
            route('api.centers.admin.learning.content.publish', [
                'center' => $contextB['center']->slug,
                'course' => $contextB['course']->slug,
                'lesson' => $contextB['lesson']->id,
            ])
        );

        $response->assertForbidden();

        // Admin A attempts to view Center B's lesson content
        $showResponse = $this->withHeader(
            'Authorization',
            "Bearer {$tokenA}"
        )->getJson(
            route('api.centers.admin.learning.content.show', [
                'center' => $contextB['center']->slug,
                'course' => $contextB['course']->slug,
                'lesson' => $contextB['lesson']->id,
            ])
        );

        $showResponse->assertForbidden();
    }
}