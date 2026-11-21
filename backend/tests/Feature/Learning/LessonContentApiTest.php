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
use App\Models\LessonBlock;
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

    private function assertJsonDocumentSame(
        array $expected,
        array $actual
    ): void {
        $this->assertSame(
            $this->normalizeJsonObjectKeys($expected),
            $this->normalizeJsonObjectKeys($actual)
        );
    }

    private function normalizeJsonObjectKeys(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->normalizeJsonObjectKeys($child);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
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
        ]);
    }

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

    public function test_all_supported_tiptap_json_round_trips_through_the_api(): void
    {
        $context = $this->createContext();
        $token = $this->tokenFor($context['user']);
        $revisionId = $this->createDraftThroughApi($context, $token);
        $document = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 2],
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Formatting preserved',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'italic'],
                            ['type' => 'strike'],
                            ['type' => 'link', 'attrs' => [
                                'href' => 'https://example.test/lesson',
                                'title' => 'Lesson reference',
                                'target' => '_blank',
                                'rel' => 'noopener noreferrer nofollow',
                                'class' => null,
                            ]],
                        ],
                    ]],
                ],
                [
                    'type' => 'orderedList',
                    'attrs' => ['start' => 3, 'type' => null],
                    'content' => [[
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [[
                                    'type' => 'text',
                                    'text' => 'First step',
                                    'marks' => [['type' => 'underline']],
                                ]],
                            ],
                            [
                                'type' => 'bulletList',
                                'content' => [[
                                    'type' => 'listItem',
                                    'content' => [[
                                        'type' => 'paragraph',
                                        'content' => [[
                                            'type' => 'text',
                                            'text' => 'Nested detail',
                                            'marks' => [[
                                                'type' => 'highlight',
                                                'attrs' => ['color' => '#fef08a'],
                                            ]],
                                        ]],
                                    ]],
                                ]],
                            ],
                        ],
                    ]],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Inline code',
                            'marks' => [['type' => 'code']],
                        ],
                        [
                            'type' => 'hardBreak',
                            'marks' => [['type' => 'bold']],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'After the hard break',
                        ],
                    ],
                ],
                [
                    'type' => 'blockquote',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => 'Quoted content',
                        ]],
                    ]],
                ],
                ['type' => 'horizontalRule'],
                [
                    'type' => 'codeBlock',
                    'attrs' => ['language' => 'javascript'],
                    'content' => [[
                        'type' => 'text',
                        'text' => 'const lesson = true;',
                    ]],
                ],
            ],
        ];

        $created = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route(
                'api.centers.admin.learning.content.blocks.text.store',
                $this->revisionRouteParameters($context, $revisionId)
            ),
            ['content_json' => $document]
        );

        $created->assertCreated();
        $blockId = (int) $created->json('data.id');
        $this->assertJsonDocumentSame($document, $created->json('data.content_json'));

        $updatedDocument = $document;
        $updatedDocument['content'][0]['content'][0]['text'] = 'Updated without losing marks';

        $updated = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->patchJson(
            route(
                'api.centers.admin.learning.content.blocks.update',
                $this->blockRouteParameters($context, $revisionId, $blockId)
            ),
            ['content_json' => $updatedDocument]
        );

        $updated->assertOk();
        $this->assertJsonDocumentSame($updatedDocument, $updated->json('data.content_json'));

        $reloaded = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(route(
            'api.centers.admin.learning.content.show',
            $this->contentRouteParameters($context)
        ));
        $reloaded->assertOk();
        $reloadedBlock = collect($reloaded->json('data.draft_revision.blocks'))
            ->firstWhere('id', $blockId);
        $this->assertJsonDocumentSame($updatedDocument, $reloadedBlock['content_json']);
    }

    public function test_empty_and_unsupported_tiptap_documents_are_rejected(): void
    {
        $context = $this->createContext();
        $token = $this->tokenFor($context['user']);
        $revisionId = $this->createDraftThroughApi($context, $token);
        $route = route(
            'api.centers.admin.learning.content.blocks.text.store',
            $this->revisionRouteParameters($context, $revisionId)
        );

        $empty = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson($route, [
            'content_json' => [
                'type' => 'doc',
                'content' => [['type' => 'paragraph']],
            ],
        ]);
        $empty->assertUnprocessable();
        $empty->assertJsonValidationErrors(['content_json']);

        $unsupported = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson($route, [
            'content_json' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'customEmbed',
                    'content' => [],
                ]],
            ],
        ]);
        $unsupported->assertUnprocessable();
        $unsupported->assertJsonValidationErrors(['content_json']);
    }

    public function test_tiptap_schema_rejects_unsupported_attributes_and_marks(): void
    {
        $context = $this->createContext();
        $token = $this->tokenFor($context['user']);
        $revisionId = $this->createDraftThroughApi($context, $token);
        $validDocument = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Preserve this existing block.',
                ]],
            ]],
        ];
        $validParagraph = $validDocument['content'][0];
        $textNode = [
            'type' => 'text',
            'text' => 'Invalid text placement.',
        ];
        $createdBlock = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(
            route(
                'api.centers.admin.learning.content.blocks.text.store',
                $this->revisionRouteParameters($context, $revisionId)
            ),
            ['content_json' => $validDocument]
        );
        $createdBlock->assertCreated();
        $blockId = (int) $createdBlock->json('data.id');

        $route = route(
            'api.centers.admin.learning.content.blocks.update',
            $this->blockRouteParameters($context, $revisionId, $blockId)
        );
        $tooDeepNode = $validParagraph;
        for ($depth = 0; $depth < 21; $depth++) {
            $tooDeepNode = [
                'type' => 'blockquote',
                'content' => [$tooDeepNode],
            ];
        }

        $invalidDocuments = [
            'text directly inside doc' => [
                'type' => 'doc',
                'content' => [$textNode],
            ],
            'nested paragraph inside paragraph' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [$validParagraph],
                ]],
            ],
            'text directly inside blockquote' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'blockquote',
                    'content' => [$textNode],
                ]],
            ],
            'empty blockquote' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'blockquote',
                    'content' => [],
                ]],
            ],
            'text directly inside bullet list' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'bulletList',
                    'content' => [$textNode],
                ]],
            ],
            'list item not beginning with paragraph' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'bulletList',
                    'content' => [[
                        'type' => 'listItem',
                        'content' => [[
                            'type' => 'blockquote',
                            'content' => [$validParagraph],
                        ]],
                    ]],
                ]],
            ],
            'invalid code block content' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'codeBlock',
                    'content' => [$validParagraph],
                ]],
            ],
            'mark inside code block' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'codeBlock',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'marked code',
                        'marks' => [['type' => 'bold']],
                    ]],
                ]],
            ],
            'hard break with children' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'hardBreak',
                        'content' => [$textNode],
                    ]],
                ]],
            ],
            'horizontal rule with children' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'horizontalRule',
                    'content' => [$validParagraph],
                ]],
            ],
            'empty text node' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => '']],
                ]],
            ],
            'unexpected root key' => [
                'type' => 'doc',
                'custom' => true,
                'content' => [$validParagraph],
            ],
            'heading level type' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'heading',
                    'attrs' => ['level' => '2'],
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Invalid heading',
                    ]],
                ]],
            ],
            'ordered list start' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'orderedList',
                    'attrs' => ['start' => 0, 'type' => null],
                    'content' => [[
                        'type' => 'listItem',
                        'content' => [$validParagraph],
                    ]],
                ]],
            ],
            'ordered list style type' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'orderedList',
                    'attrs' => ['start' => 1, 'type' => 1],
                    'content' => [[
                        'type' => 'listItem',
                        'content' => [$validParagraph],
                    ]],
                ]],
            ],
            'code language type' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'codeBlock',
                    'attrs' => ['language' => 42],
                    'content' => [[
                        'type' => 'text',
                        'text' => 'let value = true;',
                    ]],
                ]],
            ],
            'unknown node attribute' => [
                'type' => 'doc',
                'content' => [[
                    ...$validParagraph,
                    'attrs' => ['alignment' => 'center'],
                ]],
            ],
            'node attributes type' => [
                'type' => 'doc',
                'content' => [[
                    ...$validParagraph,
                    'attrs' => 'center',
                ]],
            ],
            'unknown mark' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Unknown mark',
                        'marks' => [['type' => 'customMark']],
                    ]],
                ]],
            ],
            'unknown mark attribute' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Invalid bold attributes',
                        'marks' => [[
                            'type' => 'bold',
                            'attrs' => ['class' => 'custom'],
                        ]],
                    ]],
                ]],
            ],
            'mark attribute type' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Bad highlight',
                        'marks' => [[
                            'type' => 'highlight',
                            'attrs' => ['color' => false],
                        ]],
                    ]],
                ]],
            ],
            'incompatible code mark set' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Conflicting marks',
                        'marks' => [['type' => 'bold'], ['type' => 'code']],
                    ]],
                ]],
            ],
            'link protocol' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Unsafe link',
                        'marks' => [[
                            'type' => 'link',
                            'attrs' => ['href' => 'javascript:alert(1)'],
                        ]],
                    ]],
                ]],
            ],
            'excessive nesting' => [
                'type' => 'doc',
                'content' => [$tooDeepNode],
            ],
        ];

        $revisionSnapshot = LessonRevision::query()
            ->forLesson($context['lesson']->id)
            ->orderBy('id')
            ->get()
            ->map(fn (LessonRevision $revision): array => $revision->getRawOriginal())
            ->all();

        foreach ($invalidDocuments as $case => $document) {
            $response = $this->withHeader(
                'Authorization',
                "Bearer {$token}"
            )->patchJson($route, [
                'content_json' => $document,
            ]);

            $this->assertSame(
                422,
                $response->status(),
                "The {$case} case should be rejected."
            );
            $response->assertJsonValidationErrors(['content_json']);
        }

        $this->assertSame(
            $revisionSnapshot,
            LessonRevision::query()
                ->forLesson($context['lesson']->id)
                ->orderBy('id')
                ->get()
                ->map(fn (LessonRevision $revision): array => $revision->getRawOriginal())
                ->all()
        );

        $reloaded = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(route(
            'api.centers.admin.learning.content.show',
            $this->contentRouteParameters($context)
        ));
        $reloaded->assertOk();
        $reloadedBlock = collect($reloaded->json('data.draft_revision.blocks'))
            ->firstWhere('id', $blockId);
        $this->assertJsonDocumentSame($validDocument, $reloadedBlock['content_json']);
    }

    public function test_admin_can_inspect_history_and_restore_as_a_new_draft(): void
    {
        $context = $this->createContext();
        $context['lesson']->update(['body' => null]);
        $token = $this->tokenFor($context['user']);
        $service = app(LessonRevisionService::class);
        $draft = $service->createDraft($context['lesson'], $context['user']);
        $document = $this->document('Published historical content');
        $sourceBlock = $service->addTextBlock($draft, $document);
        $published = $service->publish($context['lesson'], $context['user']);

        $history = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(route(
            'api.centers.admin.learning.content.revisions.history',
            $this->contentRouteParameters($context)
        ));
        $history->assertOk();
        $history->assertJsonPath('data.0.id', $published->id);
        $history->assertJsonPath('data.0.version_number', $published->version_number);
        $history->assertJsonPath('data.0.status', LessonRevision::STATUS_PUBLISHED);
        $history->assertJsonPath('data.0.author.name', $context['user']->name);

        $inspection = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->getJson(route(
            'api.centers.admin.learning.content.revisions.history.show',
            $this->revisionRouteParameters($context, $published->id)
        ));
        $inspection->assertOk();
        $this->assertJsonDocumentSame(
            $document,
            $inspection->json('data.blocks.0.content_json')
        );

        $restored = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(route(
            'api.centers.admin.learning.content.revisions.restore',
            $this->revisionRouteParameters($context, $published->id)
        ));
        $restored->assertCreated();
        $restored->assertJsonPath('data.status', LessonRevision::STATUS_DRAFT);
        $this->assertNotSame($published->id, $restored->json('data.id'));
        $this->assertSame($published->version_number + 1, $restored->json('data.version_number'));
        $this->assertJsonDocumentSame(
            $document,
            $restored->json('data.blocks.0.content_json')
        );

        $this->assertDatabaseHas('lesson_revisions', [
            'id' => $published->id,
            'status' => LessonRevision::STATUS_PUBLISHED,
        ]);
        $storedSourceBlock = LessonBlock::query()
            ->whereKey($sourceBlock->id)
            ->where('lesson_revision_id', $published->id)
            ->firstOrFail();

        $this->assertJsonDocumentSame(
            $document,
            $storedSourceBlock->content_json
        );

        $secondRestore = $this->withHeader(
            'Authorization',
            "Bearer {$token}"
        )->postJson(route(
            'api.centers.admin.learning.content.revisions.restore',
            $this->revisionRouteParameters($context, $published->id)
        ));
        $secondRestore->assertUnprocessable();
        $this->assertDatabaseHas('lessons', [
            'id' => $context['lesson']->id,
            'draft_revision_id' => $restored->json('data.id'),
        ]);
    }
}
