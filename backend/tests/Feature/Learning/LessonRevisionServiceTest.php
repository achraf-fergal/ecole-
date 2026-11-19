<?php

namespace Tests\Feature\Learning;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Services\Learning\LessonRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LessonRevisionServiceTest extends TestCase
{
    use RefreshDatabase;

    private LessonRevisionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LessonRevisionService::class);
    }

    /**
     * Create a complete lesson context.
     */
    private function createContext(
        bool $withLegacyBody = true
    ): array {
        $center = Center::factory()->create();

        $actor = User::factory()->create();

        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create();

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create([
                'title' => 'Original Lesson',
                'description' => 'Original description.',
                'content_locale' => 'en',
                'duration_minutes' => 20,
                'body' => $withLegacyBody
                    ? "First paragraph.\n\nSecond paragraph."
                    : null,
            ]);

        return compact(
            'center',
            'actor',
            'course',
            'module',
            'lesson'
        );
    }

    /**
     * Build a valid TipTap document.
     */
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

    public function test_it_creates_initial_draft_from_legacy_content(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $revision = $this->service->createDraft(
            $lesson,
            $actor
        );

        $this->assertSame(
            LessonRevision::STATUS_DRAFT,
            $revision->status
        );

        $this->assertSame(1, $revision->version_number);

        $this->assertSame(
            'Original Lesson',
            $revision->title
        );

        $this->assertSame(
            $actor->id,
            $revision->created_by_user_id
        );

        $this->assertCount(1, $revision->blocks);

        $block = $revision->blocks->first();

        $this->assertSame(
            LessonBlock::TYPE_TEXT,
            $block->block_type
        );

        $this->assertSame(
            'doc',
            $block->content_json['type']
        );

        $this->assertSame(
            'First paragraph.',
            $block->content_json['content'][0]['content'][0]['text']
        );

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'draft_revision_id' => $revision->id,
        ]);
    }

    public function test_creating_draft_twice_does_not_duplicate_revision(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $first = $this->service->createDraft(
            $lesson,
            $actor
        );

        $second = $this->service->createDraft(
            $lesson->fresh(),
            $actor
        );

        $this->assertSame($first->id, $second->id);

        $this->assertDatabaseCount(
            'lesson_revisions',
            1
        );
    }

    public function test_it_adds_text_media_and_exercise_blocks_in_order(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $revision = $this->service->createDraft(
            $lesson,
            $actor
        );

        $textBlock = $this->service->addTextBlock(
            $revision,
            $this->document('Introduction')
        );

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->image()
            ->create();

        $mediaBlock = $this->service->addMediaBlock(
            $revision,
            $asset->id,
            ['width' => 'full']
        );

        $exercise = Exercise::factory()
            ->forLesson($lesson)
            ->create();

        $exerciseBlock = $this->service->addExerciseBlock(
            $revision,
            $exercise->id
        );

        $blocks = LessonBlock::query()
            ->forRevision($revision->id)
            ->ordered()
            ->get();

        $this->assertCount(4, $blocks);

        $this->assertSame(
            LessonBlock::TYPE_TEXT,
            $textBlock->block_type
        );

        $this->assertSame(
            LessonBlock::TYPE_MEDIA,
            $mediaBlock->block_type
        );

        $this->assertSame(
            LessonBlock::TYPE_EXERCISE,
            $exerciseBlock->block_type
        );

        $this->assertSame(1, $textBlock->sort_order);
        $this->assertSame(2, $mediaBlock->sort_order);
        $this->assertSame(3, $exerciseBlock->sort_order);

        $this->assertSame(
            'full',
            $mediaBlock->settings['width']
        );

        $this->assertSame(
            $asset->id,
            $mediaBlock->lesson_asset_id
        );

        $this->assertSame(
            $exercise->id,
            $exerciseBlock->exercise_id
        );
    }

    public function test_it_reorders_blocks_correctly(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext(false);

        $revision = $this->service->createDraft(
            $lesson,
            $actor
        );

        $first = $this->service->addTextBlock(
            $revision,
            $this->document('First')
        );

        $second = $this->service->addTextBlock(
            $revision,
            $this->document('Second')
        );

        $third = $this->service->addTextBlock(
            $revision,
            $this->document('Third')
        );

        $actualBlockIds = LessonBlock::query()
            ->where('center_id', $lesson->center_id)
            ->where('lesson_id', $lesson->id)
            ->where('lesson_revision_id', $revision->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();



        $reordered = $this->service->reorderBlocks(
            $revision,
            [
                $third->id,
                $first->id,
                $second->id,
            ]
        );

        $this->assertSame(
            [
                $third->id,
                $first->id,
                $second->id,
            ],
            $reordered->pluck('id')->all()
        );

        $this->assertSame(
            [0, 1, 2],
            $reordered->pluck('sort_order')->all()
        );
    }

    public function test_reorder_rejects_duplicate_block_ids(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext(false);

        $revision = $this->service->createDraft(
            $lesson,
            $actor
        );

        $block = $this->service->addTextBlock(
            $revision,
            $this->document('Content')
        );

        $this->expectException(
            ValidationException::class
        );

        $this->service->reorderBlocks(
            $revision,
            [$block->id, $block->id]
        );
    }

    public function test_it_updates_text_block_content(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $revision = $this->service->createDraft(
            $lesson,
            $actor
        );

        $block = $this->service->addTextBlock(
            $revision,
            $this->document('Before')
        );

        $updated = $this->service->updateBlock(
            $revision,
            $block->id,
            [
                'content_json' => $this->document('After'),
                'settings' => [
                    'alignment' => 'center',
                ],
            ]
        );

        $this->assertSame(
            'After',
            $updated->content_json['content'][0]['content'][0]['text']
        );

        $this->assertSame(
            'center',
            $updated->settings['alignment']
        );
    }

    public function test_it_rejects_changing_block_references(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $revision = $this->service->createDraft(
            $lesson,
            $actor
        );

        $block = $this->service->addTextBlock(
            $revision,
            $this->document('Content')
        );

        $this->expectException(
            ValidationException::class
        );

        $this->service->updateBlock(
            $revision,
            $block->id,
            [
                'lesson_asset_id' => 999,
            ]
        );
    }

    public function test_it_deletes_blocks_from_current_draft(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $revision = $this->service->createDraft(
            $lesson,
            $actor
        );

        $block = $this->service->addTextBlock(
            $revision,
            $this->document('Temporary content')
        );

        $this->service->deleteBlock(
            $revision,
            $block->id
        );

        $this->assertDatabaseMissing('lesson_blocks', [
            'id' => $block->id,
        ]);
    }

    public function test_it_rejects_empty_revision_publication(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext(false);

        $this->service->createDraft(
            $lesson,
            $actor
        );

        $this->expectException(
            ValidationException::class
        );

        $this->service->publish(
            $lesson,
            $actor
        );
    }

    public function test_it_publishes_revisions_and_preserves_history(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $firstDraft = $this->service->createDraft(
            $lesson,
            $actor
        );

        $this->service->addTextBlock(
            $firstDraft,
            $this->document('Version one')
        );

        $firstPublished = $this->service->publish(
            $lesson,
            $actor
        );

        $this->assertSame(
            LessonRevision::STATUS_PUBLISHED,
            $firstPublished->status
        );

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'published_revision_id' => $firstPublished->id,
            'draft_revision_id' => null,
        ]);

        // Create the next version from the published revision.
        $secondDraft = $this->service->createDraft(
            $lesson->fresh(),
            $actor
        );

        $this->assertSame(2, $secondDraft->version_number);

        $this->assertCount(
            $firstPublished->blocks->count(),
            $secondDraft->blocks
        );
        $this->service->addTextBlock(
            $secondDraft,
            $this->document('Version two')
        );

        $secondPublished = $this->service->publish(
            $lesson->fresh(),
            $actor
        );

        $this->assertSame(
            LessonRevision::STATUS_SUPERSEDED,
            $firstPublished->fresh()->status
        );

        $this->assertSame(
            LessonRevision::STATUS_PUBLISHED,
            $secondPublished->fresh()->status
        );

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'published_revision_id' => $secondPublished->id,
            'draft_revision_id' => null,
            'status' => Lesson::STATUS_PUBLISHED,
        ]);

        $this->assertNotNull(
            $secondPublished->published_at
        );

        $this->assertSame(
            $actor->id,
            $secondPublished->published_by_user_id
        );
    }

    public function test_published_revision_cannot_be_modified(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $draft = $this->service->createDraft(
            $lesson,
            $actor
        );

        $block = $this->service->addTextBlock(
            $draft,
            $this->document('Published content')
        );

        $published = $this->service->publish(
            $lesson,
            $actor
        );

        $this->expectException(
            ValidationException::class
        );

        $this->service->updateBlock(
            $published,
            $block->id,
            [
                'content_json' => $this->document('Changed content'),
            ]
        );
    }

    public function test_published_asset_cannot_be_modified(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->image()
            ->create();

        $draft = $this->service->createDraft(
            $lesson,
            $actor
        );

        $this->service->addMediaBlock(
            $draft,
            $asset->id
        );

        // Draft-only references do not freeze the asset.
        $this->service->assertAssetCanBeModified($asset);

        $this->service->publish(
            $lesson,
            $actor
        );

        $this->expectException(
            ValidationException::class
        );

        $this->service->assertAssetCanBeModified(
            $asset->fresh()
        );
    }

    public function test_published_exercise_cannot_be_modified(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $exercise = Exercise::factory()
            ->forLesson($lesson)
            ->published()
            ->create();

        $question = Question::factory()
            ->forExercise($exercise)
            ->create();

        QuestionOption::factory()
            ->forQuestion($question)
            ->correct()
            ->create();

        QuestionOption::factory()
            ->forQuestion($question)
            ->create();

        $draft = $this->service->createDraft(
            $lesson,
            $actor
        );

        $this->service->addExerciseBlock(
            $draft,
            $exercise->id
        );

        $this->service->publish(
            $lesson,
            $actor
        );

        $this->expectException(
            ValidationException::class
        );

        $this->service->assertExerciseCanBeModified(
            $exercise->fresh()
        );
    }

    public function test_publication_rejects_unpublished_exercises(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $exercise = Exercise::factory()
            ->forLesson($lesson)
            ->create();

        $draft = $this->service->createDraft(
            $lesson,
            $actor
        );

        $this->service->addTextBlock(
            $draft,
            $this->document('Introduction')
        );

        $this->service->addExerciseBlock(
            $draft,
            $exercise->id
        );

        $this->expectException(
            ValidationException::class
        );

        $this->service->publish(
            $lesson,
            $actor
        );
    }

    public function test_it_rejects_unsupported_document_nodes(): void
    {
        [
            'lesson' => $lesson,
            'actor' => $actor,
        ] = $this->createContext();

        $draft = $this->service->createDraft(
            $lesson,
            $actor
        );

        $invalidDocument = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'script',
                    'content' => [],
                ],
            ],
        ];

        $this->expectException(
            ValidationException::class
        );

        $this->service->addTextBlock(
            $draft,
            $invalidDocument
        );
    }
}
