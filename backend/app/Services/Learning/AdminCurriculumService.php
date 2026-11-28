<?php

namespace App\Services\Learning;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
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
            if (array_key_exists('status', $data)) {
                $updates['status'] = $data['status'];
                if ($data['status'] === CourseModule::STATUS_PUBLISHED && $locked->published_at === null) {
                    $updates['published_at'] = now();
                }
            }

            if ($updates !== []) {
                $locked->update($updates);
            }

            return $locked->fresh();
        });
    }

    public function reorderModules(Course $course, array $moduleIds): void
    {
        DB::transaction(function () use ($course, $moduleIds): void {
            $existingIds = CourseModule::query()
                ->forCenter((int) $course->center_id)
                ->where('course_id', $course->id)
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
                if (! in_array($id, $existingIds, true)) {
                    throw ValidationException::withMessages([
                        'module_ids' => ["Module ID {$id} does not belong to this course."],
                    ]);
                }
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

            Lesson::query()
                ->forCenter((int) $locked->center_id)
                ->where('course_module_id', $locked->id)
                ->where('status', '!=', Lesson::STATUS_ARCHIVED)
                ->update(['status' => Lesson::STATUS_ARCHIVED]);

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
            if (array_key_exists('content_type', $data)) {
                $updates['content_type'] = $data['content_type'];
            }
            if (array_key_exists('duration_minutes', $data)) {
                $updates['duration_minutes'] = $data['duration_minutes'] !== null ? (int) $data['duration_minutes'] : null;
            }
            if (array_key_exists('status', $data)) {
                $updates['status'] = $data['status'];
                if ($data['status'] === Lesson::STATUS_PUBLISHED && $locked->published_at === null) {
                    $updates['published_at'] = now();
                }
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

    public function moveLesson(Lesson $lesson, CourseModule $targetModule, ?int $sortOrder = null): Lesson
    {
        return DB::transaction(function () use ($lesson, $targetModule, $sortOrder): Lesson {
            $locked = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sourceCourseId = (int) $locked->courseModule()->value('course_id');
            if ((int) $targetModule->center_id !== (int) $locked->center_id || (int) $targetModule->course_id !== $sourceCourseId) {
                throw ValidationException::withMessages([
                    'target_module_id' => ['Target module must belong to the same course and center.'],
                ]);
            }

            $nextSort = $sortOrder;
            if ($nextSort === null) {
                $maxOrder = Lesson::query()
                    ->forCenter((int) $locked->center_id)
                    ->where('course_module_id', $targetModule->id)
                    ->max('sort_order');
                $nextSort = $maxOrder !== null ? ((int) $maxOrder + 1) : 0;
            }

            $locked->update([
                'course_module_id' => $targetModule->id,
                'sort_order' => $nextSort,
            ]);

            return $locked->fresh();
        });
    }

    public function reorderLessons(CourseModule $module, array $lessonIds): void
    {
        DB::transaction(function () use ($module, $lessonIds): void {
            $existingIds = Lesson::query()
                ->forCenter((int) $module->center_id)
                ->where('course_module_id', $module->id)
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
                if (! in_array($id, $existingIds, true)) {
                    throw ValidationException::withMessages([
                        'lesson_ids' => ["Lesson ID {$id} does not belong to this module."],
                    ]);
                }
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
                ->with(['draftRevision.blocks', 'publishedRevision.blocks'])
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $maxOrder = Lesson::query()
                ->forCenter((int) $locked->center_id)
                ->where('course_module_id', $locked->course_module_id)
                ->max('sort_order');

            $copy = Lesson::query()->create([
                'center_id' => $locked->center_id,
                'course_module_id' => $locked->course_module_id,
                'title' => $locked->title . ' (Copy)',
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

            if ($sourceRevision) {
                foreach ($sourceRevision->blocks as $block) {
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
                    }
                }
            }

            $copy->draft_revision_id = $draftRevision->id;
            $copy->save();

            return $copy->fresh(['draftRevision']);
        });
    }

    public function archiveLesson(Lesson $lesson): Lesson
    {
        return DB::transaction(function () use ($lesson): Lesson {
            $locked = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'status' => Lesson::STATUS_ARCHIVED,
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

            if ($videoCount === 1 && $nonVideoCount === 0) {
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
