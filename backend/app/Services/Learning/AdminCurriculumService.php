<?php

namespace App\Services\Learning;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCurriculumService
{
    public function createModule(Course $course, array $data): CourseModule
    {
        return DB::transaction(function () use ($course, $data): CourseModule {
            $maxOrder = CourseModule::query()
                ->forCenter((int) $course->center_id)
                ->where('course_id', $course->id)
                ->max('sort_order');

            $status = $data['status'] ?? CourseModule::STATUS_DRAFT;

            return CourseModule::query()->create([
                'center_id' => $course->center_id,
                'course_id' => $course->id,
                'title' => trim((string) $data['title']),
                'description' => isset($data['description']) ? trim((string) $data['description']) ?: null : null,
                'sort_order' => $maxOrder !== null ? ((int) $maxOrder + 1) : 0,
                'status' => $status,
                'published_at' => $status === CourseModule::STATUS_PUBLISHED ? now() : null,
            ]);
        });
    }

    public function updateModule(CourseModule $module, array $data): CourseModule
    {
        return DB::transaction(function () use ($module, $data): CourseModule {
            $locked = CourseModule::query()
                ->forCenter((int) $module->center_id)
                ->where('course_id', $module->course_id)
                ->whereKey($module->id)
                ->lockForUpdate()
                ->firstOrFail();

            $updates = [];
            if (array_key_exists('title', $data)) {
                $updates['title'] = trim((string) $data['title']);
            }
            if (array_key_exists('description', $data)) {
                $updates['description'] = $data['description'] !== null ? (trim((string) $data['description']) ?: null) : null;
            }

            if ($updates !== []) {
                $locked->update($updates);
            }

            return $locked->fresh();
        });
    }

    public function publishModule(CourseModule $module): CourseModule
    {
        return DB::transaction(function () use ($module): CourseModule {
            $locked = CourseModule::query()
                ->forCenter((int) $module->center_id)
                ->where('course_id', $module->course_id)
                ->whereKey($module->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isArchived()) {
                throw ValidationException::withMessages([
                    'status' => ['Archived modules must be restored to draft before publishing.'],
                ]);
            }

            $locked->update([
                'status' => CourseModule::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function restoreModule(CourseModule $module): CourseModule
    {
        return DB::transaction(function () use ($module): CourseModule {
            $locked = CourseModule::query()
                ->forCenter((int) $module->center_id)
                ->where('course_id', $module->course_id)
                ->whereKey($module->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isArchived()) {
                throw ValidationException::withMessages([
                    'status' => ['Only archived modules can be restored.'],
                ]);
            }

            $locked->update([
                'status' => CourseModule::STATUS_DRAFT,
                'published_at' => null,
            ]);

            // Restore archived child lessons only if they were archived by this module operation.
            $moduleArchivedLessons = Lesson::query()
                ->forCenter((int) $locked->center_id)
                ->where('course_module_id', $locked->id)
                ->where('status', Lesson::STATUS_ARCHIVED)
                ->where('archive_origin', 'module')
                ->lockForUpdate()
                ->get();

            foreach ($moduleArchivedLessons as $lesson) {
                $lesson->update([
                    'status' => Lesson::STATUS_DRAFT,
                    'archive_origin' => null,
                    'published_at' => null,
                ]);
            }

            return $locked->fresh();
        });
    }

    public function reorderModules(Course $course, array $moduleIds): void
    {
        DB::transaction(function () use ($course, $moduleIds): void {
            $activeIds = CourseModule::query()
                ->forCenter((int) $course->center_id)
                ->where('course_id', $course->id)
                ->where('status', '!=', CourseModule::STATUS_ARCHIVED)
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $requestedIds = array_values(array_map('intval', $moduleIds));
            if (count($requestedIds) !== count(array_unique($requestedIds))) {
                throw ValidationException::withMessages([
                    'module_ids' => ['Duplicate module IDs are not allowed.'],
                ]);
            }

            foreach ($requestedIds as $id) {
                if (! in_array($id, $activeIds, true)) {
                    throw ValidationException::withMessages([
                        'module_ids' => ["Module ID {$id} is not an active module in this course."],
                    ]);
                }
            }

            if (count($requestedIds) !== count($activeIds)) {
                throw ValidationException::withMessages([
                    'module_ids' => ['Reorder payload must include all active modules in this course.'],
                ]);
            }

            foreach ($requestedIds as $index => $id) {
                CourseModule::query()
                    ->forCenter((int) $course->center_id)
                    ->where('course_id', $course->id)
                    ->whereKey($id)
                    ->update(['sort_order' => $index]);
            }
        });
    }

    public function archiveModule(CourseModule $module): CourseModule
    {
        return DB::transaction(function () use ($module): CourseModule {
            $locked = CourseModule::query()
                ->forCenter((int) $module->center_id)
                ->where('course_id', $module->course_id)
                ->whereKey($module->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'status' => CourseModule::STATUS_ARCHIVED,
            ]);

            $lessonsToArchive = Lesson::query()
                ->forCenter((int) $locked->center_id)
                ->where('course_module_id', $locked->id)
                ->where('status', '!=', Lesson::STATUS_ARCHIVED)
                ->lockForUpdate()
                ->get();

            foreach ($lessonsToArchive as $lesson) {
                $lesson->update([
                    'status' => Lesson::STATUS_ARCHIVED,
                    'archive_origin' => 'module',
                ]);
            }

            return $locked->fresh();
        });
    }

    public function createLesson(CourseModule $module, array $data, User $actor): Lesson
    {
        return DB::transaction(function () use ($module, $data, $actor): Lesson {
            $lockedModule = CourseModule::query()
                ->forCenter((int) $module->center_id)
                ->whereKey($module->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedModule->isArchived()) {
                throw ValidationException::withMessages([
                    'module' => ['Cannot add lessons to an archived module.'],
                ]);
            }

            $maxOrder = Lesson::query()
                ->forCenter((int) $lockedModule->center_id)
                ->where('course_module_id', $lockedModule->id)
                ->max('sort_order');

            $title = trim((string) $data['title']);
            $description = isset($data['description']) ? (trim((string) $data['description']) ?: null) : null;
            $locale = trim((string) ($data['content_locale'] ?? $lockedModule->course?->source_locale ?? 'en'));
            $durationMinutes = isset($data['duration_minutes']) && $data['duration_minutes'] !== null
                ? (int) $data['duration_minutes']
                : null;

            $lesson = Lesson::query()->create([
                'center_id' => $lockedModule->center_id,
                'course_module_id' => $lockedModule->id,
                'title' => $title,
                'description' => $description,
                'content_type' => $data['content_type'],
                'content_locale' => $locale,
                'duration_minutes' => $durationMinutes,
                'sort_order' => $maxOrder !== null ? ((int) $maxOrder + 1) : 0,
                'status' => Lesson::STATUS_DRAFT,
                'published_at' => null,
            ]);

            $draftRevision = LessonRevision::query()->create([
                'center_id' => $lockedModule->center_id,
                'lesson_id' => $lesson->id,
                'version_number' => 1,
                'status' => LessonRevision::STATUS_DRAFT,
                'title' => $title,
                'description' => $description,
                'content_locale' => $locale,
                'duration_minutes' => $durationMinutes,
                'created_by_user_id' => $actor->id,
                'published_by_user_id' => null,
                'published_at' => null,
            ]);

            $lesson->draft_revision_id = $draftRevision->id;
            $lesson->save();

            return $lesson->fresh(['draftRevision']);
        });
    }

    public function updateLesson(Lesson $lesson, array $data): Lesson
    {
        return DB::transaction(function () use ($lesson, $data): Lesson {
            $locked = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $updates = [];
            if (array_key_exists('title', $data)) {
                $updates['title'] = trim((string) $data['title']);
            }
            if (array_key_exists('description', $data)) {
                $updates['description'] = $data['description'] !== null ? (trim((string) $data['description']) ?: null) : null;
            }
            if (array_key_exists('content_type', $data) && $data['content_type'] !== $locked->content_type) {
                $requestedKind = $this->mapLegacyTypeToKind((string) $data['content_type']);
                $revisions = collect([
                    $locked->draftRevision()->with('blocks.asset')->first(),
                    $locked->publishedRevision()->with('blocks.asset')->first(),
                ])->filter();

                foreach ($revisions as $revision) {
                    if ($revision->blocks->isEmpty()) {
                        continue;
                    }

                    $actualKind = $this->classifyRevisionKind($revision, $locked);
                    if ($actualKind !== $requestedKind) {
                        throw ValidationException::withMessages([
                            'content_type' => [
                                "Cannot change lesson type from '{$actualKind}' to '{$requestedKind}' while the lesson contains incompatible content blocks.",
                            ],
                        ]);
                    }
                }

                $updates['content_type'] = $data['content_type'];
            }
            if (array_key_exists('duration_minutes', $data)) {
                $updates['duration_minutes'] = $data['duration_minutes'] !== null ? (int) $data['duration_minutes'] : null;
            }

            if ($updates !== []) {
                $locked->update($updates);
            }

            if ($locked->draft_revision_id !== null) {
                $draftUpdates = array_intersect_key($updates, array_flip([
                    'title',
                    'description',
                    'duration_minutes',
                ]));
                if ($draftUpdates !== []) {
                    LessonRevision::query()
                        ->forCenter((int) $locked->center_id)
                        ->where('lesson_id', $locked->id)
                        ->whereKey($locked->draft_revision_id)
                        ->where('status', LessonRevision::STATUS_DRAFT)
                        ->update($draftUpdates);
                }
            }

            return $locked->fresh();
        });
    }

    public function moveLesson(
        Lesson $lesson,
        CourseModule $targetModule,
        ?int $sortOrder = null,
        string $placement = 'last',
        ?int $anchorLessonId = null
    ): Lesson {
        return DB::transaction(function () use ($lesson, $targetModule, $sortOrder, $placement, $anchorLessonId): Lesson {
            $locked = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sourceModule = CourseModule::query()
                ->forCenter((int) $locked->center_id)
                ->whereKey($locked->course_module_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTarget = CourseModule::query()
                ->forCenter((int) $locked->center_id)
                ->where('course_id', $sourceModule->course_id)
                ->whereKey($targetModule->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedTarget) {
                throw ValidationException::withMessages([
                    'target_module_id' => ['Target module must belong to the same course and center.'],
                ]);
            }

            if ($sourceModule->isArchived() || $lockedTarget->isArchived()) {
                throw ValidationException::withMessages([
                    'target_module_id' => ['Cannot move lessons from or into an archived module.'],
                ]);
            }

            if ($locked->status === Lesson::STATUS_ARCHIVED) {
                throw ValidationException::withMessages([
                    'lesson' => ['Archived lessons must be restored before moving.'],
                ]);
            }

            $sourceModuleId = (int) $locked->course_module_id;

            // Collect ordered active lesson IDs in target module (excluding moved lesson).
            $targetLessonIds = Lesson::query()
                ->forCenter((int) $locked->center_id)
                ->where('course_module_id', $lockedTarget->id)
                ->where('status', '!=', Lesson::STATUS_ARCHIVED)
                ->whereKeyNot($locked->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($sortOrder !== null) {
                $insertIndex = max(0, min($sortOrder, count($targetLessonIds)));
            } elseif ($placement === 'first') {
                $insertIndex = 0;
            } elseif (($placement === 'before' || $placement === 'after') && $anchorLessonId !== null) {
                $anchorIdx = array_search($anchorLessonId, $targetLessonIds, true);
                if ($anchorIdx === false) {
                    throw ValidationException::withMessages([
                        'anchor_lesson_id' => ['Anchor lesson does not belong to the target module.'],
                    ]);
                }
                $insertIndex = $placement === 'before' ? $anchorIdx : ($anchorIdx + 1);
            } else {
                $insertIndex = count($targetLessonIds);
            }

            array_splice($targetLessonIds, $insertIndex, 0, [(int) $locked->id]);

            $locked->update([
                'course_module_id' => $lockedTarget->id,
                'sort_order' => $insertIndex,
            ]);

            foreach ($targetLessonIds as $idx => $id) {
                Lesson::query()
                    ->forCenter((int) $locked->center_id)
                    ->whereKey($id)
                    ->update(['sort_order' => $idx]);
            }

            // Re-index source module deterministically if moved across modules.
            if ($sourceModuleId !== (int) $lockedTarget->id) {
                $sourceIds = Lesson::query()
                    ->forCenter((int) $locked->center_id)
                    ->where('course_module_id', $sourceModuleId)
                    ->where('status', '!=', Lesson::STATUS_ARCHIVED)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                foreach ($sourceIds as $idx => $id) {
                    Lesson::query()
                        ->forCenter((int) $locked->center_id)
                        ->whereKey($id)
                        ->update(['sort_order' => $idx]);
                }
            }

            return $locked->fresh();
        });
    }

    public function reorderLessons(CourseModule $module, array $lessonIds): void
    {
        DB::transaction(function () use ($module, $lessonIds): void {
            $activeIds = Lesson::query()
                ->forCenter((int) $module->center_id)
                ->where('course_module_id', $module->id)
                ->where('status', '!=', Lesson::STATUS_ARCHIVED)
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $requestedIds = array_values(array_map('intval', $lessonIds));
            if (count($requestedIds) !== count(array_unique($requestedIds))) {
                throw ValidationException::withMessages([
                    'lesson_ids' => ['Duplicate lesson IDs are not allowed.'],
                ]);
            }

            foreach ($requestedIds as $id) {
                if (! in_array($id, $activeIds, true)) {
                    throw ValidationException::withMessages([
                        'lesson_ids' => ["Lesson ID {$id} is not an active lesson in this module."],
                    ]);
                }
            }

            if (count($requestedIds) !== count($activeIds)) {
                throw ValidationException::withMessages([
                    'lesson_ids' => ['Reorder payload must include all active lessons in this module.'],
                ]);
            }

            foreach ($requestedIds as $index => $id) {
                Lesson::query()
                    ->forCenter((int) $module->center_id)
                    ->where('course_module_id', $module->id)
                    ->whereKey($id)
                    ->update(['sort_order' => $index]);
            }
        });
    }

    public function duplicateLesson(Lesson $lesson, User $actor): Lesson
    {
        return DB::transaction(function () use ($lesson, $actor): Lesson {
            $locked = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->with([
                    'draftRevision.blocks.asset',
                    'draftRevision.blocks.exercise.questions.options',
                    'publishedRevision.blocks.asset',
                    'publishedRevision.blocks.exercise.questions.options',
                ])
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $parentModule = CourseModule::query()
                ->forCenter((int) $locked->center_id)
                ->whereKey($locked->course_module_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($parentModule->isArchived()) {
                throw ValidationException::withMessages([
                    'module' => ['Cannot duplicate a lesson inside an archived module.'],
                ]);
            }

            $maxOrder = Lesson::query()
                ->forCenter((int) $locked->center_id)
                ->where('course_module_id', $locked->course_module_id)
                ->max('sort_order');

            $copy = Lesson::query()->create([
                'center_id' => $locked->center_id,
                'course_module_id' => $locked->course_module_id,
                'title' => $locked->title.' (Copy)',
                'description' => $locked->description,
                'content_type' => $locked->content_type,
                'content_locale' => $locked->content_locale,
                'duration_minutes' => $locked->duration_minutes,
                'sort_order' => $maxOrder !== null ? ((int) $maxOrder + 1) : 0,
                'status' => Lesson::STATUS_DRAFT,
                'published_at' => null,
            ]);

            $sourceRevision = $locked->draftRevision ?? $locked->publishedRevision;

            $draftRevision = LessonRevision::query()->create([
                'center_id' => $locked->center_id,
                'lesson_id' => $copy->id,
                'version_number' => 1,
                'status' => LessonRevision::STATUS_DRAFT,
                'title' => $copy->title,
                'description' => $sourceRevision?->description ?? $locked->description,
                'content_locale' => $sourceRevision?->content_locale ?? $locked->content_locale,
                'duration_minutes' => $sourceRevision?->duration_minutes ?? $locked->duration_minutes,
                'created_by_user_id' => $actor->id,
                'published_by_user_id' => null,
                'published_at' => null,
            ]);

            $clonedAssetMap = [];
            $clonedExerciseMap = [];

            if ($sourceRevision) {
                $blocks = $sourceRevision->blocks->sortBy(static fn (LessonBlock $b): array => [$b->sort_order, $b->id]);

                foreach ($blocks as $block) {
                    if ($block->block_type === LessonBlock::TYPE_TEXT) {
                        LessonBlock::query()->create([
                            'center_id' => $locked->center_id,
                            'lesson_id' => $copy->id,
                            'lesson_revision_id' => $draftRevision->id,
                            'block_type' => LessonBlock::TYPE_TEXT,
                            'sort_order' => $block->sort_order,
                            'content_json' => $block->content_json,
                            'settings' => $block->settings,
                        ]);

                        continue;
                    }

                    if ($block->block_type === LessonBlock::TYPE_MEDIA) {
                        $sourceAsset = $block->asset;
                        if (! $sourceAsset || $sourceAsset->status === LessonAsset::STATUS_ARCHIVED) {
                            throw ValidationException::withMessages([
                                'blocks' => ['Cannot duplicate lesson because a media block references a missing or archived asset.'],
                            ]);
                        }

                        if (! isset($clonedAssetMap[$sourceAsset->id])) {
                            $newAsset = LessonAsset::query()->create([
                                'center_id' => $locked->center_id,
                                'lesson_id' => $copy->id,
                                'title' => $sourceAsset->title,
                                'asset_type' => $sourceAsset->asset_type,
                                'storage_disk' => $sourceAsset->storage_disk,
                                'storage_key' => $sourceAsset->storage_key,
                                'original_filename' => $sourceAsset->original_filename,
                                'mime_type' => $sourceAsset->mime_type,
                                'size_bytes' => $sourceAsset->size_bytes,
                                'video_provider' => $sourceAsset->video_provider,
                                'provider_asset_id' => $sourceAsset->provider_asset_id,
                                'external_url' => $sourceAsset->external_url,
                                'duration_seconds' => $sourceAsset->duration_seconds,
                                'alt_text' => $sourceAsset->alt_text,
                                'caption' => $sourceAsset->caption,
                                'is_downloadable' => $sourceAsset->is_downloadable,
                                'sort_order' => $sourceAsset->sort_order,
                                'status' => $sourceAsset->status,
                            ]);
                            $clonedAssetMap[$sourceAsset->id] = $newAsset->id;
                        }

                        LessonBlock::query()->create([
                            'center_id' => $locked->center_id,
                            'lesson_id' => $copy->id,
                            'lesson_revision_id' => $draftRevision->id,
                            'block_type' => LessonBlock::TYPE_MEDIA,
                            'sort_order' => $block->sort_order,
                            'lesson_asset_id' => $clonedAssetMap[$sourceAsset->id],
                            'settings' => $block->settings,
                        ]);

                        continue;
                    }

                    if ($block->block_type === LessonBlock::TYPE_EXERCISE) {
                        $sourceExercise = $block->exercise;
                        if (! $sourceExercise) {
                            throw ValidationException::withMessages([
                                'blocks' => ['Cannot duplicate lesson because an exercise block references a missing exercise.'],
                            ]);
                        }

                        if (! isset($clonedExerciseMap[$sourceExercise->id])) {
                            $newExercise = Exercise::query()->create([
                                'center_id' => $locked->center_id,
                                'lesson_id' => $copy->id,
                                'title' => $sourceExercise->title,
                                'instructions' => $sourceExercise->instructions,
                                'total_points' => $sourceExercise->total_points,
                                'max_attempts' => $sourceExercise->max_attempts,
                                'passing_score' => $sourceExercise->passing_score,
                                'show_correct_answers_after_submission' => $sourceExercise->show_correct_answers_after_submission,
                                'result_settings' => $sourceExercise->result_settings,
                                'sort_order' => $sourceExercise->sort_order,
                                'status' => Exercise::STATUS_DRAFT,
                                'published_at' => null,
                            ]);

                            $questions = $sourceExercise->questions->sortBy(static fn (Question $q): array => [$q->sort_order, $q->id]);
                            foreach ($questions as $sourceQuestion) {
                                $newQuestion = Question::query()->create([
                                    'center_id' => $locked->center_id,
                                    'exercise_id' => $newExercise->id,
                                    'question_type' => $sourceQuestion->question_type,
                                    'prompt' => $sourceQuestion->prompt,
                                    'explanation' => $sourceQuestion->explanation,
                                    'answer_config' => $sourceQuestion->answer_config,
                                    'points' => $sourceQuestion->points,
                                    'sort_order' => $sourceQuestion->sort_order,
                                ]);

                                $options = $sourceQuestion->options->sortBy(static fn (QuestionOption $o): array => [$o->sort_order, $o->id]);
                                foreach ($options as $sourceOption) {
                                    QuestionOption::query()->create([
                                        'center_id' => $locked->center_id,
                                        'question_id' => $newQuestion->id,
                                        'option_text' => $sourceOption->option_text,
                                        'is_correct' => $sourceOption->is_correct,
                                        'sort_order' => $sourceOption->sort_order,
                                    ]);
                                }
                            }

                            $clonedExerciseMap[$sourceExercise->id] = $newExercise->id;
                        }

                        LessonBlock::query()->create([
                            'center_id' => $locked->center_id,
                            'lesson_id' => $copy->id,
                            'lesson_revision_id' => $draftRevision->id,
                            'block_type' => LessonBlock::TYPE_EXERCISE,
                            'sort_order' => $block->sort_order,
                            'exercise_id' => $clonedExerciseMap[$sourceExercise->id],
                            'settings' => $block->settings,
                        ]);
                    }
                }
            }

            $copy->draft_revision_id = $draftRevision->id;
            $copy->save();

            return $copy->fresh(['draftRevision.blocks.asset', 'exercises.questions']);
        });
    }

    public function archiveLesson(Lesson $lesson): Lesson
    {
        return DB::transaction(function () use ($lesson): Lesson {
            $parentModule = CourseModule::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->course_module_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === Lesson::STATUS_ARCHIVED) {
                return $locked->fresh();
            }

            if ($parentModule->isArchived()) {
                throw ValidationException::withMessages([
                    'module' => ['Restore the parent module before archiving this lesson independently.'],
                ]);
            }

            $locked->update([
                'status' => Lesson::STATUS_ARCHIVED,
                'archive_origin' => 'self',
            ]);

            return $locked->fresh();
        });
    }

    public function restoreLesson(Lesson $lesson): Lesson
    {
        return DB::transaction(function () use ($lesson): Lesson {
            $parentModule = CourseModule::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->course_module_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== Lesson::STATUS_ARCHIVED) {
                throw ValidationException::withMessages([
                    'status' => ['Only archived lessons can be restored.'],
                ]);
            }

            if ($parentModule->isArchived()) {
                throw ValidationException::withMessages([
                    'module' => ['Restore the parent module before restoring this lesson.'],
                ]);
            }

            $locked->update([
                'status' => Lesson::STATUS_DRAFT,
                'archive_origin' => null,
                'published_at' => null,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Classify a lesson revision independently by its actual blocks.
     */
    public function classifyRevisionKind(?LessonRevision $revision, Lesson $lesson): string
    {
        if (! $revision) {
            return $this->mapLegacyTypeToKind($lesson->content_type);
        }

        $blocks = $revision->relationLoaded('blocks')
            ? $revision->blocks
            : $revision->blocks()->with('asset')->get();

        if ($blocks->isEmpty()) {
            return $this->mapLegacyTypeToKind($lesson->content_type);
        }

        $types = $blocks->pluck('block_type')->unique()->values()->all();

        if ($types === [LessonBlock::TYPE_TEXT]) {
            return Lesson::KIND_READING;
        }

        // Reading lesson may contain embedded/supporting images without becoming mixed.
        if (! in_array(LessonBlock::TYPE_EXERCISE, $types, true)) {
            $mediaBlocks = $blocks->where('block_type', LessonBlock::TYPE_MEDIA);
            $hasText = in_array(LessonBlock::TYPE_TEXT, $types, true);

            if ($hasText && $mediaBlocks->every(static fn (LessonBlock $b): bool => $b->asset?->asset_type === LessonAsset::TYPE_IMAGE)) {
                return Lesson::KIND_READING;
            }

            $videoCount = $mediaBlocks->filter(static fn (LessonBlock $b): bool => $b->asset?->asset_type === LessonAsset::TYPE_VIDEO)->count();
            if ($videoCount === 1 && ! $hasText) {
                return Lesson::KIND_VIDEO;
            }
        }

        if ($types === [LessonBlock::TYPE_EXERCISE]) {
            return $blocks->count() === 1 ? Lesson::KIND_EXERCISE : Lesson::KIND_LEGACY_MIXED;
        }

        if ($types === [LessonBlock::TYPE_MEDIA]) {
            $assetTypes = $blocks
                ->map(static fn (LessonBlock $b): ?string => $b->asset?->asset_type)
                ->filter()
                ->values();

            $videoCount = $assetTypes->filter(static fn (string $t): bool => $t === LessonAsset::TYPE_VIDEO)->count();
            $nonVideoCount = $assetTypes->filter(static fn (string $t): bool => $t !== LessonAsset::TYPE_VIDEO)->count();

            if ($videoCount === 1) {
                return Lesson::KIND_VIDEO;
            }
            if ($videoCount === 0 && $nonVideoCount > 0) {
                return Lesson::KIND_RESOURCES;
            }

            return Lesson::KIND_LEGACY_MIXED;
        }

        return Lesson::KIND_LEGACY_MIXED;
    }

    public function mapLegacyTypeToKind(?string $contentType): string
    {
        return match ($contentType) {
            Lesson::KIND_VIDEO => Lesson::KIND_VIDEO,
            Lesson::KIND_READING, Lesson::TYPE_TEXT => Lesson::KIND_READING,
            Lesson::KIND_RESOURCES, Lesson::TYPE_FILE, Lesson::TYPE_IMAGE => Lesson::KIND_RESOURCES,
            Lesson::KIND_EXERCISE, Lesson::TYPE_QUIZ => Lesson::KIND_EXERCISE,
            Lesson::KIND_LEGACY_MIXED, Lesson::TYPE_MIXED => Lesson::KIND_LEGACY_MIXED,
            default => Lesson::KIND_READING,
        };
    }
}
