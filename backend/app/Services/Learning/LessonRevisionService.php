<?php

namespace App\Services\Learning;

use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonRevisionService
{
    /*
    |--------------------------------------------------------------------------
    | Create Draft
    |--------------------------------------------------------------------------
    */

    /**
     * Create an editable draft from the current published revision.
     *
     * If a draft already exists, return it instead of creating a duplicate.
     */
    public function createDraft(
        Lesson $lesson,
        User $actor
    ): LessonRevision {
        return DB::transaction(function () use (
            $lesson,
            $actor
        ): LessonRevision {
            $lockedLesson = $this->lockLesson($lesson);

            // Return the existing current draft.
            if ($lockedLesson->draft_revision_id !== null) {
                $existingDraft = LessonRevision::query()
                    ->where('center_id', $lockedLesson->center_id)
                    ->where('lesson_id', $lockedLesson->id)
                    ->whereKey($lockedLesson->draft_revision_id)
                    ->where('status', LessonRevision::STATUS_DRAFT)
                    ->lockForUpdate()
                    ->first();

                if (! $existingDraft) {
                    $this->fail(
                        'revision',
                        'The current draft pointer is invalid.'
                    );
                }

                return $existingDraft->load('blocks');
            }

            // Resolve the currently published revision.
            $publishedRevision = null;

            if ($lockedLesson->published_revision_id !== null) {
                $publishedRevision = LessonRevision::query()
                    ->where('center_id', $lockedLesson->center_id)
                    ->where('lesson_id', $lockedLesson->id)
                    ->whereKey($lockedLesson->published_revision_id)
                    ->where('status', LessonRevision::STATUS_PUBLISHED)
                    ->lockForUpdate()
                    ->first();

                if (! $publishedRevision) {
                    $this->fail(
                        'revision',
                        'The current published revision pointer is invalid.'
                    );
                }
            }

            // Generate the next sequential version.
            $latestVersion = (int) LessonRevision::query()
                ->where('center_id', $lockedLesson->center_id)
                ->where('lesson_id', $lockedLesson->id)
                ->max('version_number');

            $revision = LessonRevision::query()->create([
                'center_id' => $lockedLesson->center_id,
                'lesson_id' => $lockedLesson->id,

                'version_number' => $latestVersion + 1,

                'status' => LessonRevision::STATUS_DRAFT,

                'title' => $publishedRevision?->title
                    ?? $lockedLesson->title,

                'description' => $publishedRevision?->description
                    ?? $lockedLesson->description,

                'content_locale' => $publishedRevision?->content_locale
                    ?? $lockedLesson->content_locale,

                'duration_minutes' => $publishedRevision?->duration_minutes
                    ?? $lockedLesson->duration_minutes,

                'created_by_user_id' => $actor->id,
                'published_by_user_id' => null,
                'published_at' => null,
            ]);

            if ($publishedRevision) {
                // Copy all blocks without changing the published revision.
                $sourceBlocks = LessonBlock::query()
                    ->forCenter($lockedLesson->center_id)
                    ->forRevision($publishedRevision->id)
                    ->ordered()
                    ->get();

                foreach ($sourceBlocks as $sourceBlock) {
                    LessonBlock::query()->create([
                        'center_id' => $lockedLesson->center_id,
                        'lesson_id' => $lockedLesson->id,
                        'lesson_revision_id' => $revision->id,

                        'block_type' => $sourceBlock->block_type,
                        'sort_order' => $sourceBlock->sort_order,

                        'content_json' => $sourceBlock->content_json,
                        'lesson_asset_id' => $sourceBlock->lesson_asset_id,
                        'exercise_id' => $sourceBlock->exercise_id,

                        'settings' => $sourceBlock->settings,
                    ]);
                }
            } elseif (filled($lockedLesson->body)) {
                // Compatibility for a new lesson without a revision.
                $legacyDocument = $this->legacyTextToDocument(
                    (string) $lockedLesson->body
                );

                LessonBlock::query()->create([
                    'center_id' => $lockedLesson->center_id,
                    'lesson_id' => $lockedLesson->id,
                    'lesson_revision_id' => $revision->id,

                    'block_type' => LessonBlock::TYPE_TEXT,
                    'sort_order' => 0,

                    'content_json' => $legacyDocument,
                    'lesson_asset_id' => null,
                    'exercise_id' => null,
                    'settings' => null,
                ]);
            }

            // Assign the current draft pointer.
            $lockedLesson->forceFill([
                'draft_revision_id' => $revision->id,
            ])->save();

            return $revision->load('blocks');
        }, 3);
    }

    /**
     * Restore historical content into a new draft revision.
     *
     * Historical revisions remain immutable and an existing draft is never replaced.
     */
    public function createDraftFromRevision(
        Lesson $lesson,
        LessonRevision $sourceRevision,
        User $actor
    ): LessonRevision {
        return DB::transaction(function () use (
            $lesson,
            $sourceRevision,
            $actor
        ): LessonRevision {
            $lockedLesson = $this->lockLesson($lesson);

            if ($lockedLesson->draft_revision_id !== null) {
                $this->fail(
                    'revision',
                    'Publish or finish the current draft before restoring a historical revision.'
                );
            }

            $lockedSource = LessonRevision::query()
                ->forCenter($lockedLesson->center_id)
                ->forLesson($lockedLesson->id)
                ->whereKey($sourceRevision->id)
                ->whereIn('status', [
                    LessonRevision::STATUS_PUBLISHED,
                    LessonRevision::STATUS_SUPERSEDED,
                ])
                ->lockForUpdate()
                ->firstOrFail();

            $latestVersion = (int) LessonRevision::query()
                ->forCenter($lockedLesson->center_id)
                ->forLesson($lockedLesson->id)
                ->max('version_number');

            $draft = LessonRevision::query()->create([
                'center_id' => $lockedLesson->center_id,
                'lesson_id' => $lockedLesson->id,
                'version_number' => $latestVersion + 1,
                'status' => LessonRevision::STATUS_DRAFT,
                'title' => $lockedSource->title,
                'description' => $lockedSource->description,
                'content_locale' => $lockedSource->content_locale,
                'duration_minutes' => $lockedSource->duration_minutes,
                'created_by_user_id' => $actor->id,
                'published_by_user_id' => null,
                'published_at' => null,
            ]);

            $sourceBlocks = LessonBlock::query()
                ->forCenter($lockedLesson->center_id)
                ->forRevision($lockedSource->id)
                ->ordered()
                ->get();

            foreach ($sourceBlocks as $sourceBlock) {
                LessonBlock::query()->create([
                    'center_id' => $lockedLesson->center_id,
                    'lesson_id' => $lockedLesson->id,
                    'lesson_revision_id' => $draft->id,
                    'block_type' => $sourceBlock->block_type,
                    'sort_order' => $sourceBlock->sort_order,
                    'content_json' => $sourceBlock->content_json,
                    'lesson_asset_id' => $sourceBlock->lesson_asset_id,
                    'exercise_id' => $sourceBlock->exercise_id,
                    'settings' => $sourceBlock->settings,
                ]);
            }

            $lockedLesson->forceFill([
                'draft_revision_id' => $draft->id,
            ])->save();

            return $draft->load('blocks');
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Update Draft Metadata
    |--------------------------------------------------------------------------
    */

    /**
     * Update metadata of the current draft revision.
     *
     * Published and superseded revisions cannot be modified.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraftMetadata(
        LessonRevision $revision,
        array $attributes
    ): LessonRevision {
        $allowed = [
            'title',
            'description',
            'content_locale',
            'duration_minutes',
        ];

        $unexpected = array_diff(
            array_keys($attributes),
            $allowed
        );

        if ($unexpected !== []) {
            $this->fail(
                'attributes',
                'Unsupported revision metadata attributes.'
            );
        }

        if ($attributes === []) {
            $this->fail(
                'attributes',
                'At least one metadata attribute is required.'
            );
        }

        return DB::transaction(function () use (
            $revision,
            $attributes
        ): LessonRevision {
            [, $draft] = $this->lockDraftContext($revision);

            $draft->fill($attributes);
            $draft->save();

            return $draft->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Add Blocks
    |--------------------------------------------------------------------------
    */

    /**
     * Add a rich-text block to a draft.
     */
    public function addTextBlock(
        LessonRevision $revision,
        array $document,
        ?array $settings = null
    ): LessonBlock {
        $this->validateDocument($document);
        $this->validateSettings($settings);

        return DB::transaction(function () use (
            $revision,
            $document,
            $settings
        ): LessonBlock {
            [$lesson, $draft] = $this->lockDraftContext($revision);

            return LessonBlock::query()->create([
                'center_id' => $lesson->center_id,
                'lesson_id' => $lesson->id,
                'lesson_revision_id' => $draft->id,

                'block_type' => LessonBlock::TYPE_TEXT,
                'sort_order' => $this->nextSortOrder($draft),

                'content_json' => $document,

                'lesson_asset_id' => null,
                'exercise_id' => null,

                'settings' => $settings,
            ]);
        }, 3);
    }

    /**
     * Add a media block using an existing lesson asset.
     */
    public function addMediaBlock(
        LessonRevision $revision,
        int $assetId,
        ?array $settings = null
    ): LessonBlock {
        $this->validateSettings($settings);

        return DB::transaction(function () use (
            $revision,
            $assetId,
            $settings
        ): LessonBlock {
            [$lesson, $draft] = $this->lockDraftContext($revision);

            $asset = LessonAsset::query()
                ->forCenter($lesson->center_id)
                ->where('lesson_id', $lesson->id)
                ->whereKey($assetId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUsableAsset($asset);

            // Preserve presentation metadata for this revision.
            $snapshot = array_merge([
                'asset_title' => $asset->title,
                'asset_type' => $asset->asset_type,
                'alt_text' => $asset->alt_text,
                'caption' => $asset->caption,
                'original_filename' => $asset->original_filename,
                'mime_type' => $asset->mime_type,
                'duration_seconds' => $asset->duration_seconds,
            ], $settings ?? []);

            return LessonBlock::query()->create([
                'center_id' => $lesson->center_id,
                'lesson_id' => $lesson->id,
                'lesson_revision_id' => $draft->id,

                'block_type' => LessonBlock::TYPE_MEDIA,
                'sort_order' => $this->nextSortOrder($draft),

                'content_json' => null,
                'lesson_asset_id' => $asset->id,
                'exercise_id' => null,

                'settings' => $snapshot,
            ]);
        }, 3);
    }

    /**
     * Add an existing exercise to a draft.
     *
     * The exercise must belong to the same lesson and center.
     */
    public function addExerciseBlock(
        LessonRevision $revision,
        int $exerciseId,
        ?array $settings = null
    ): LessonBlock {
        $this->validateSettings($settings);

        return DB::transaction(function () use (
            $revision,
            $exerciseId,
            $settings
        ): LessonBlock {
            [$lesson, $draft] = $this->lockDraftContext($revision);

            $exercise = Exercise::query()
                ->forCenter($lesson->center_id)
                ->where('lesson_id', $lesson->id)
                ->whereKey($exerciseId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($exercise->isArchived()) {
                $this->fail(
                    'exercise_id',
                    'Archived exercises cannot be added.'
                );
            }

            $snapshot = array_merge([
                'exercise_title' => $exercise->title,
                'instructions' => $exercise->instructions,
            ], $settings ?? []);

            return LessonBlock::query()->create([
                'center_id' => $lesson->center_id,
                'lesson_id' => $lesson->id,
                'lesson_revision_id' => $draft->id,

                'block_type' => LessonBlock::TYPE_EXERCISE,
                'sort_order' => $this->nextSortOrder($draft),

                'content_json' => null,
                'lesson_asset_id' => null,
                'exercise_id' => $exercise->id,

                'settings' => $snapshot,
            ]);
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Update Block
    |--------------------------------------------------------------------------
    */

    /**
     * Update an existing block.
     *
     * References cannot be changed through this method.
     * Replace a media or exercise block by deleting it and adding another.
     */
    public function updateBlock(
        LessonRevision $revision,
        int $blockId,
        array $attributes
    ): LessonBlock {
        return DB::transaction(function () use (
            $revision,
            $blockId,
            $attributes
        ): LessonBlock {
            [$lesson, $draft] = $this->lockDraftContext($revision);

            $block = $this->findDraftBlock(
                $lesson,
                $draft,
                $blockId
            );

            $allowed = $block->isText()
                ? ['content_json', 'settings']
                : ['settings'];

            $unexpected = array_diff(
                array_keys($attributes),
                $allowed
            );

            if ($unexpected !== []) {
                $this->fail(
                    'attributes',
                    'Unsupported block attributes: '.implode(', ', $unexpected)
                );
            }

            if (
                array_key_exists('content_json', $attributes)
                && $block->isText()
            ) {
                if (! is_array($attributes['content_json'])) {
                    $this->fail(
                        'content_json',
                        'Content must be a valid TipTap document.'
                    );
                }

                $this->validateDocument(
                    $attributes['content_json']
                );
            }

            if (array_key_exists('settings', $attributes)) {
                $this->validateSettings($attributes['settings']);
            }

            $block->fill($attributes);
            $block->save();

            return $block->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Block
    |--------------------------------------------------------------------------
    */

    public function deleteBlock(
        LessonRevision $revision,
        int $blockId
    ): void {
        DB::transaction(function () use (
            $revision,
            $blockId
        ): void {
            [$lesson, $draft] = $this->lockDraftContext($revision);

            $block = $this->findDraftBlock(
                $lesson,
                $draft,
                $blockId
            );

            $block->delete();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Reorder Blocks
    |--------------------------------------------------------------------------
    */

    /**
     * Replace the complete block ordering.
     *
     * Every existing block ID must be submitted exactly once.
     *
     * @return Collection<int, LessonBlock>
     */
    public function reorderBlocks(
        LessonRevision $revision,
        array $orderedBlockIds
    ): Collection {
        return DB::transaction(function () use (
            $revision,
            $orderedBlockIds
        ): Collection {
            [$lesson, $draft] = $this->lockDraftContext($revision);

            $ids = array_map(
                static fn ($id): int => filter_var($id, FILTER_VALIDATE_INT)
                    !== false ? (int) $id : 0,
                $orderedBlockIds
            );

            if (
                in_array(0, $ids, true)
                || count($ids) !== count(array_unique($ids))
            ) {
                $this->fail(
                    'block_ids',
                    'Block IDs must be valid and unique.'
                );
            }

            $blocks = LessonBlock::query()
                ->forCenter($lesson->center_id)
                ->where('lesson_id', $lesson->id)
                ->forRevision($draft->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $existingIds = $blocks
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $expected = $existingIds;
            $submitted = $ids;

            sort($expected);
            sort($submitted);

            if ($expected !== $submitted) {
                $this->fail(
                    'block_ids',
                    'You must submit every block exactly once.'
                );
            }

            $position = 0;

            foreach ($ids as $id) {
                LessonBlock::query()
                    ->where('center_id', $lesson->center_id)
                    ->where('lesson_id', $lesson->id)
                    ->where('lesson_revision_id', $draft->id)
                    ->whereKey($id)
                    ->update([
                        'sort_order' => $position,
                        'updated_at' => now(),
                    ]);

                $position++;
            }

            return LessonBlock::query()
                ->forCenter($lesson->center_id)
                ->forRevision($draft->id)
                ->ordered()
                ->get();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Publish
    |--------------------------------------------------------------------------
    */

    /**
     * Publish the current draft atomically.
     *
     * The calling controller must authorize the user first.
     */
    public function publish(
        Lesson $lesson,
        User $actor
    ): LessonRevision {
        return DB::transaction(function () use (
            $lesson,
            $actor
        ): LessonRevision {
            $lockedLesson = $this->lockLesson($lesson);

            if ($lockedLesson->status === Lesson::STATUS_ARCHIVED) {
                $this->fail(
                    'lesson',
                    'Archived lessons cannot be published.'
                );
            }

            if ($lockedLesson->draft_revision_id === null) {
                $this->fail(
                    'revision',
                    'There is no current draft to publish.'
                );
            }

            $draft = LessonRevision::query()
                ->where('center_id', $lockedLesson->center_id)
                ->where('lesson_id', $lockedLesson->id)
                ->whereKey($lockedLesson->draft_revision_id)
                ->where('status', LessonRevision::STATUS_DRAFT)
                ->lockForUpdate()
                ->firstOrFail();

            $blocks = LessonBlock::query()
                ->forCenter($lockedLesson->center_id)
                ->forRevision($draft->id)
                ->ordered()
                ->get();

            if ($blocks->isEmpty()) {
                $this->fail(
                    'blocks',
                    'A lesson needs at least one content block before publication.'
                );
            }

            // Lock referenced exercises in deterministic order (sorted by ID)
            $exerciseIds = $blocks
                ->where('block_type', LessonBlock::TYPE_EXERCISE)
                ->pluck('exercise_id')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $lockedExercises = ! empty($exerciseIds)
                ? Exercise::query()
                    ->forCenter($lockedLesson->center_id)
                    ->where('lesson_id', $lockedLesson->id)
                    ->whereIn('id', $exerciseIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->with(['questions.options'])
                    ->get()
                    ->keyBy('id')
                : collect();

            // Lock referenced media assets in deterministic order (sorted by ID)
            $assetIds = $blocks
                ->where('block_type', LessonBlock::TYPE_MEDIA)
                ->pluck('lesson_asset_id')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $lockedAssets = ! empty($assetIds)
                ? LessonAsset::query()
                    ->forCenter($lockedLesson->center_id)
                    ->where('lesson_id', $lockedLesson->id)
                    ->whereIn('id', $assetIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id')
                : collect();

            $this->validateBlocksForPublication(
                $lockedLesson,
                $blocks,
                $lockedExercises,
                $lockedAssets
            );

            // Lock and resolve the previous published revision.
            $previous = null;

            if ($lockedLesson->published_revision_id !== null) {
                $previous = LessonRevision::query()
                    ->where('center_id', $lockedLesson->center_id)
                    ->where('lesson_id', $lockedLesson->id)
                    ->whereKey($lockedLesson->published_revision_id)
                    ->where('status', LessonRevision::STATUS_PUBLISHED)
                    ->lockForUpdate()
                    ->first();

                if (! $previous) {
                    $this->fail(
                        'revision',
                        'The existing published revision pointer is invalid.'
                    );
                }
            }

            $publishedAt = now();

            // Preserve the old revision as immutable history.
            if ($previous) {
                $previous->update([
                    'status' => LessonRevision::STATUS_SUPERSEDED,
                ]);
            }

            // Publish the new revision.
            $draft->update([
                'status' => LessonRevision::STATUS_PUBLISHED,
                'published_by_user_id' => $actor->id,
                'published_at' => $publishedAt,
            ]);

            // Synchronize legacy lesson metadata, not the legacy body.
            $lockedLesson->forceFill([
                'title' => $draft->title,
                'description' => $draft->description,
                'content_locale' => $draft->content_locale,
                'duration_minutes' => $draft->duration_minutes,

                'status' => Lesson::STATUS_PUBLISHED,
                'published_at' => $publishedAt,

                'draft_revision_id' => null,
                'published_revision_id' => $draft->id,
            ])->save();

            return $draft->refresh()->load('blocks');
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Published Resource Protection
    |--------------------------------------------------------------------------
    */

    /**
     * Call before changing or archiving a lesson asset.
     *
     * Superseded revisions are protected as historical content too.
     */
    public function assertAssetCanBeModified(
        LessonAsset $asset
    ): void {
        $isReferenced = LessonBlock::query()
            ->where('center_id', $asset->center_id)
            ->where('lesson_id', $asset->lesson_id)
            ->where('lesson_asset_id', $asset->id)
            ->whereHas('revision', function ($query) {
                $query->whereIn('status', [
                    LessonRevision::STATUS_PUBLISHED,
                    LessonRevision::STATUS_SUPERSEDED,
                ]);
            })
            ->exists();

        if ($isReferenced) {
            $this->fail(
                'asset',
                'This asset is used by published or historical lesson content. Create a replacement asset instead.'
            );
        }
    }

    /**
     * Call before editing or archiving an exercise.
     */
    public function assertExerciseCanBeModified(
        Exercise $exercise
    ): void {
        $isReferenced = LessonBlock::query()
            ->where('center_id', $exercise->center_id)
            ->where('lesson_id', $exercise->lesson_id)
            ->where('exercise_id', $exercise->id)
            ->whereHas('revision', function ($query) {
                $query->whereIn('status', [
                    LessonRevision::STATUS_PUBLISHED,
                    LessonRevision::STATUS_SUPERSEDED,
                ]);
            })
            ->exists();

        if ($isReferenced) {
            $this->fail(
                'exercise',
                'This exercise is used by published or historical lesson content. Create a replacement exercise instead.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internal Helpers
    |--------------------------------------------------------------------------
    */

    private function lockLesson(Lesson $lesson): Lesson
    {
        return Lesson::query()
            ->forCenter((int) $lesson->center_id)
            ->whereKey($lesson->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock and validate the current editable draft.
     *
     * @return array{0: Lesson, 1: LessonRevision}
     */
    private function lockDraftContext(
        LessonRevision $revision
    ): array {
        $lesson = Lesson::query()
            ->forCenter((int) $revision->center_id)
            ->whereKey($revision->lesson_id)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            (int) $lesson->draft_revision_id
            !== (int) $revision->id
        ) {
            $this->fail(
                'revision',
                'Only the current draft revision can be edited.'
            );
        }

        $draft = LessonRevision::query()
            ->where('center_id', $lesson->center_id)
            ->where('lesson_id', $lesson->id)
            ->whereKey($revision->id)
            ->where('status', LessonRevision::STATUS_DRAFT)
            ->lockForUpdate()
            ->firstOrFail();

        return [$lesson, $draft];
    }

    private function findDraftBlock(
        Lesson $lesson,
        LessonRevision $revision,
        int $blockId
    ): LessonBlock {
        return LessonBlock::query()
            ->forCenter($lesson->center_id)
            ->where('lesson_id', $lesson->id)
            ->forRevision($revision->id)
            ->whereKey($blockId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function nextSortOrder(
        LessonRevision $revision
    ): int {
        $latest = LessonBlock::query()
            ->forCenter($revision->center_id)
            ->forRevision($revision->id)
            ->max('sort_order');

        return $latest === null ? 0 : ((int) $latest + 1);
    }

    private function assertUsableAsset(
        LessonAsset $asset
    ): void {
        if ($asset->status !== LessonAsset::STATUS_ACTIVE) {
            $this->fail(
                'asset_id',
                'Only active assets can be added.'
            );
        }

        if (! in_array($asset->asset_type, LessonAsset::ASSET_TYPES, true)) {
            $this->fail(
                'asset_id',
                'Unsupported media type.'
            );
        }

        if (
            in_array(
                $asset->asset_type,
                [LessonAsset::TYPE_IMAGE, LessonAsset::TYPE_FILE],
                true
            )
            && ! $asset->isPrivateStorageAsset()
        ) {
            $this->fail(
                'asset_id',
                'The uploaded file is missing its private storage reference.'
            );
        }

        if (
            $asset->asset_type === LessonAsset::TYPE_VIDEO
            && ! $asset->isProviderVideo()
        ) {
            $this->fail(
                'asset_id',
                'The video provider asset is not ready for use.'
            );
        }

        if ($asset->asset_type === LessonAsset::TYPE_EXTERNAL_LINK) {
            $scheme = parse_url(
                (string) $asset->external_url,
                PHP_URL_SCHEME
            );

            if (
                ! filled($asset->external_url)
                || strtolower((string) $scheme) !== 'https'
            ) {
                $this->fail(
                    'asset_id',
                    'External media must use a valid HTTPS URL.'
                );
            }
        }
    }

    private function validateBlocksForPublication(
        Lesson $lesson,
        Collection $blocks,
        $lockedExercises = null,
        $lockedAssets = null
    ): void {
        foreach ($blocks as $block) {
            if ($block->isText()) {
                if (! is_array($block->content_json)) {
                    $this->fail(
                        'blocks',
                        "Text block {$block->id} has invalid content."
                    );
                }

                $this->validateDocument($block->content_json);

                continue;
            }

            if ($block->isMedia()) {
                $asset = ($lockedAssets && $lockedAssets->has($block->lesson_asset_id))
                    ? $lockedAssets->get($block->lesson_asset_id)
                    : LessonAsset::query()
                        ->forCenter($lesson->center_id)
                        ->where('lesson_id', $lesson->id)
                        ->whereKey($block->lesson_asset_id)
                        ->first();

                if (! $asset) {
                    $this->fail(
                        'blocks',
                        "Media block {$block->id} references a missing asset."
                    );
                }

                $this->assertUsableAsset($asset);

                continue;
            }

            if ($block->isExercise()) {
                $exercise = ($lockedExercises && $lockedExercises->has($block->exercise_id))
                    ? $lockedExercises->get($block->exercise_id)
                    : Exercise::query()
                        ->forCenter($lesson->center_id)
                        ->where('lesson_id', $lesson->id)
                        ->whereKey($block->exercise_id)
                        ->with(['questions.options'])
                        ->first();

                if (! $exercise || ! $exercise->isPublished()) {
                    $this->fail(
                        'blocks',
                        "Exercise block {$block->id} requires a published exercise."
                    );
                }

                if ($exercise->questions->isEmpty()) {
                    $this->fail(
                        'blocks',
                        "Exercise {$exercise->id} must contain at least one question."
                    );
                }

                foreach ($exercise->questions as $question) {
                    if (! $question->hasValidOptionConfiguration()) {
                        $this->fail(
                            'blocks',
                            "Exercise {$exercise->id} contains an invalid question configuration."
                        );
                    }
                }

                continue;
            }

            $this->fail(
                'blocks',
                "Block {$block->id} has an unsupported type."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TipTap Document Validation
    |--------------------------------------------------------------------------
    */

    private function validateDocument(array $document): void
    {
        if (
            ($document['type'] ?? null) !== 'doc'
            || ! isset($document['content'])
            || ! is_array($document['content'])
            || $document['content'] === []
        ) {
            $this->fail(
                'content_json',
                'A valid TipTap document with at least one block is required.'
            );
        }

        try {
            $json = json_encode(
                $document,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            $this->fail(
                'content_json',
                'Content contains invalid JSON data.'
            );
        }

        if (strlen($json) > 512000) {
            $this->fail(
                'content_json',
                'The text document cannot exceed 500 KB.'
            );
        }

        if (count($document['content']) > 200) {
            $this->fail(
                'content_json',
                'A document cannot contain more than 200 top-level nodes.'
            );
        }

        foreach ($document['content'] as $node) {
            if (! is_array($node)) {
                $this->fail(
                    'content_json',
                    'Invalid document node.'
                );
            }

            $this->validateNode($node);
        }

        if (! $this->hasMeaningfulContent($document['content'])) {
            $this->fail(
                'content_json',
                'Text content cannot be empty.'
            );
        }
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private function hasMeaningfulContent(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (
                ($node['type'] ?? null) === 'horizontalRule'
                || (
                    ($node['type'] ?? null) === 'text'
                    && is_string($node['text'] ?? null)
                    && trim($node['text']) !== ''
                )
            ) {
                return true;
            }

            if (
                is_array($node['content'] ?? null)
                && $this->hasMeaningfulContent($node['content'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate supported TipTap nodes and marks recursively.
     */
    private function validateNode(array $node, int $depth = 0): void
    {
        if ($depth > 20) {
            $this->fail(
                'content_json',
                'Document nesting is too deep.'
            );
        }

        $allowedNodes = [
            'paragraph',
            'heading',
            'blockquote',
            'bulletList',
            'orderedList',
            'listItem',
            'text',
            'hardBreak',
            'codeBlock',
            'horizontalRule',
        ];

        $type = $node['type'] ?? null;

        if (! is_string($type) || ! in_array($type, $allowedNodes, true)) {
            $this->fail(
                'content_json',
                'Unsupported content node type.'
            );
        }

        if ($type === 'text') {
            if (
                ! isset($node['text'])
                || ! is_string($node['text'])
            ) {
                $this->fail(
                    'content_json',
                    'Text nodes must contain a valid string.'
                );
            }

            if (mb_strlen($node['text']) > 50000) {
                $this->fail(
                    'content_json',
                    'A single text node is too long.'
                );
            }
        }

        if (isset($node['content'])) {
            if (! is_array($node['content'])) {
                $this->fail(
                    'content_json',
                    'Node content must be an array.'
                );
            }

            foreach ($node['content'] as $child) {
                if (! is_array($child)) {
                    $this->fail(
                        'content_json',
                        'Invalid nested content node.'
                    );
                }

                $this->validateNode($child, $depth + 1);
            }
        }

        if (isset($node['marks'])) {
            if (! is_array($node['marks'])) {
                $this->fail(
                    'content_json',
                    'Invalid text marks.'
                );
            }

            $allowedMarks = [
                'bold',
                'italic',
                'underline',
                'strike',
                'code',
                'highlight',
                'link',
            ];

            foreach ($node['marks'] as $mark) {
                if (
                    ! is_array($mark)
                    || ! in_array(
                        $mark['type'] ?? null,
                        $allowedMarks,
                        true
                    )
                ) {
                    $this->fail(
                        'content_json',
                        'Unsupported text mark.'
                    );
                }

                if (($mark['type'] ?? null) === 'link') {
                    $href = $mark['attrs']['href'] ?? null;
                    $scheme = is_string($href)
                        ? parse_url($href, PHP_URL_SCHEME)
                        : null;

                    if (
                        ! is_string($href)
                        || ! in_array(
                            strtolower((string) $scheme),
                            ['https', 'http', 'mailto'],
                            true
                        )
                    ) {
                        $this->fail(
                            'content_json',
                            'Links must use HTTP, HTTPS or mailto.'
                        );
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Settings Validation
    |--------------------------------------------------------------------------
    */

    private function validateSettings(?array $settings): void
    {
        if ($settings === null) {
            return;
        }

        try {
            $json = json_encode(
                $settings,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            $this->fail(
                'settings',
                'Invalid block settings.'
            );
        }

        if (strlen($json) > 16384) {
            $this->fail(
                'settings',
                'Block settings cannot exceed 16 KB.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Legacy Text Compatibility
    |--------------------------------------------------------------------------
    */

    private function legacyTextToDocument(string $body): array
    {
        $normalized = str_replace(
            ["\r\n", "\r"],
            "\n",
            trim($body)
        );

        if (
            preg_match('/<\/?[a-z][^>]*>/i', $normalized) === 1
        ) {
            $this->fail(
                'body',
                'Legacy HTML requires a dedicated safe conversion.'
            );
        }

        $paragraphs = preg_split(
            '/\n[ \t]*\n+/u',
            $normalized
        ) ?: [];

        $content = [];

        foreach ($paragraphs as $paragraphText) {
            $lines = explode("\n", $paragraphText);
            $children = [];

            foreach ($lines as $index => $line) {
                if ($index > 0) {
                    $children[] = [
                        'type' => 'hardBreak',
                    ];
                }

                if ($line !== '') {
                    $children[] = [
                        'type' => 'text',
                        'text' => $line,
                    ];
                }
            }

            $paragraph = [
                'type' => 'paragraph',
            ];

            if ($children !== []) {
                $paragraph['content'] = $children;
            }

            $content[] = $paragraph;
        }

        return [
            'type' => 'doc',
            'content' => $content ?: [
                ['type' => 'paragraph'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Error Helper
    |--------------------------------------------------------------------------
    */

    private function fail(
        string $field,
        string $message
    ): never {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }
}
