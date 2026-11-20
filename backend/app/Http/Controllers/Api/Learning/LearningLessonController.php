<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Learning\LearningLessonResource;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonProgress;
use App\Models\StudentProfile;
use App\Services\Learning\LearningAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class LearningLessonController extends Controller
{
    public function __construct(
        protected LearningAccessService $learningAccess
    ) {}

    /**
     * Retrieve a lesson for a student or an administrative preview.
     */
    public function show(Request $request): LearningLessonResource
    {
        [
            $center,
            $course,
            $lesson,
            $studentProfile,
            $isAdminPreview,
        ] = $this->resolveLearningContext($request);

        $modules = $this->courseModulesForViewer(
            $center,
            $course,
            $isAdminPreview
        );

        /** @var EloquentCollection<int, Lesson> $lessonSequence */
        $lessonSequence = $modules
            ->flatMap(fn (CourseModule $module) => $module->lessons)
            ->values();

        $currentIndex = $lessonSequence->search(
            fn (Lesson $item) => $item->is($lesson)
        );

        $previousLesson = $currentIndex !== false && $currentIndex > 0
            ? $lessonSequence->get($currentIndex - 1)
            : null;

        $nextLesson = $currentIndex !== false
            ? $lessonSequence->get($currentIndex + 1)
            : null;

        // Administrative previews do not read personal student progress.
        $progress = $studentProfile !== null
            ? LessonProgress::query()
                ->where('center_id', $center->id)
                ->where('student_profile_id', $studentProfile->id)
                ->where('lesson_id', $lesson->id)
                ->first()
            : null;

        // Load content from the correct revision.
        $revisionRelations = [
            'publishedRevision.blocks.asset',
            'publishedRevision.blocks.exercise.questions.options',
        ];

        if ($isAdminPreview) {
            $revisionRelations[] = 'draftRevision.blocks.asset';
            $revisionRelations[] = 'draftRevision.blocks.exercise.questions.options';
        }

        $lesson->load($revisionRelations);

        // Students always read the current published revision.
        // Administrators preview the current draft when available.
        $revision = $isAdminPreview
            ? ($lesson->draftRevision ?? $lesson->publishedRevision)
            : $lesson->publishedRevision;

        if (! $isAdminPreview) {
            abort_unless(
                $revision !== null
                    && $revision->isPublished()
                    && $revision->center_id === $center->id
                    && $revision->lesson_id === $lesson->id,
                404,
                'Published lesson content not found.'
            );

            // Fail closed: a published lesson must have usable blocks without invalid or missing resources.
            $rawBlocks = $revision->blocks;

            if ($rawBlocks->isEmpty()) {
                Log::warning('Published lesson revision contains no blocks.', [
                    'center_id' => $center->id,
                    'course_id' => $course->id,
                    'lesson_id' => $lesson->id,
                    'revision_id' => $revision->id,
                ]);

                abort(404, 'Published lesson content not found.');
            }

            foreach ($rawBlocks as $block) {
                $isValid = match ($block->block_type) {
                    LessonBlock::TYPE_TEXT => is_array($block->content_json),

                    LessonBlock::TYPE_MEDIA => $block->asset instanceof LessonAsset
                        && $block->asset->status === LessonAsset::STATUS_ACTIVE
                        && $block->asset->center_id === $center->id
                        && $block->asset->lesson_id === $lesson->id,

                    LessonBlock::TYPE_EXERCISE => $block->exercise instanceof Exercise
                        && $block->exercise->isPublished()
                        && $block->exercise->center_id === $center->id
                        && $block->exercise->lesson_id === $lesson->id,

                    default => false,
                };

                if (! $isValid) {
                    Log::warning('Published lesson block references invalid or unavailable resource.', [
                        'center_id' => $center->id,
                        'course_id' => $course->id,
                        'lesson_id' => $lesson->id,
                        'revision_id' => $revision->id,
                        'block_id' => $block->id,
                        'block_type' => $block->block_type,
                    ]);

                    abort(404, 'Published lesson content not found.');
                }
            }

            $blocks = $rawBlocks->values();
        } else {
            abort_unless(
                $revision === null
                    || (
                        $revision->center_id === $center->id
                        && $revision->lesson_id === $lesson->id
                    ),
                404,
                'Lesson revision not found.'
            );

            // Blocks are ordered by sort_order and id through the revision relation.
            $blocks = ($revision?->blocks ?? collect())
                ->filter(function (LessonBlock $block) use ($isAdminPreview): bool {
                    return match ($block->block_type) {
                        LessonBlock::TYPE_TEXT => is_array($block->content_json),

                        LessonBlock::TYPE_MEDIA => $block->asset instanceof LessonAsset
                            && (
                                $isAdminPreview
                                || $block->asset->status === LessonAsset::STATUS_ACTIVE
                            ),

                        LessonBlock::TYPE_EXERCISE => $block->exercise instanceof Exercise
                            && (
                                $isAdminPreview
                                || $block->exercise->isPublished()
                            ),

                        default => false,
                    };
                })
                ->values();
        }

        // Administrative previews remain compatible with legacy lessons.
        if ($revision === null && $isAdminPreview) {
            $assets = $lesson->assets()
                ->forCenter($center->id)
                ->get([
                    'id',
                    'lesson_id',
                    'title',
                    'asset_type',
                    'original_filename',
                    'mime_type',
                    'size_bytes',
                    'duration_seconds',
                    'alt_text',
                    'caption',
                    'is_downloadable',
                    'sort_order',
                    'status',
                ]);

            $exercises = Exercise::query()
                ->forCenter($center->id)
                ->where('lesson_id', $lesson->id)
                ->with([
                    'questions' => function ($query) use ($center): void {
                        $query
                            ->forCenter($center->id)
                            ->with([
                                'options' => function ($optionQuery) use ($center): void {
                                    $optionQuery->forCenter($center->id);
                                },
                            ]);
                    },
                ])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        } else {
            // Compatibility arrays are derived only from the selected revision.
            $assets = $blocks
                ->filter(fn (LessonBlock $block): bool => $block->isMedia())
                ->map(fn (LessonBlock $block) => $block->asset)
                ->filter()
                ->unique('id')
                ->values();

            $exercises = $blocks
                ->filter(fn (LessonBlock $block): bool => $block->isExercise())
                ->map(fn (LessonBlock $block) => $block->exercise)
                ->filter()
                ->unique('id')
                ->values();
        }

        return new LearningLessonResource([
            'course' => $course,
            'lesson' => $lesson,
            'revision' => $revision,
            'blocks' => $blocks,
            'progress' => $progress,
            'assets' => $assets,
            'exercises' => $exercises,
            'previous_lesson' => $previousLesson,
            'next_lesson' => $nextLesson,
            'access_mode' => $isAdminPreview
                ? 'admin_preview'
                : 'student',
        ]);
    }

    /**
     * Update progress belonging to the authenticated student only.
     */
    public function updateProgress(Request $request): JsonResponse
    {
        [
            $center,
            $course,
            $lesson,
            $studentProfile,
            $isAdminPreview,
        ] = $this->resolveLearningContext($request);

        // Administrators must never modify student progress through this API.
        abort_unless(
            ! $isAdminPreview && $studentProfile instanceof StudentProfile,
            403,
            'Student progress updates are not available in administrative preview mode.'
        );

        $validated = $request->validate([
            'last_position_seconds' => [
                'sometimes',
                'integer',
                'min:0',
                'max:4294967295',
            ],
            'completed' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $now = now();

        $completedRequested = (bool) ($validated['completed'] ?? false);

        $progressValues = [
            'center_id' => $center->id,
            'student_profile_id' => $studentProfile->id,
            'lesson_id' => $lesson->id,
            'status' => LessonProgress::STATUS_IN_PROGRESS,
            'last_position_seconds' => $validated['last_position_seconds'] ?? 0,
            'started_at' => $now,
            'completed_at' => null,
            'last_accessed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Only update fields explicitly supplied by the student.
        $updateColumns = [
            'last_accessed_at',
            'updated_at',
        ];

        if (array_key_exists('last_position_seconds', $validated)) {
            $updateColumns[] = 'last_position_seconds';
        }

        DB::transaction(function () use (
            $progressValues,
            $updateColumns,
            $center,
            $studentProfile,
            $lesson,
            $completedRequested,
            $now
        ): void {
            // The unique database constraint protects simultaneous first writes.
            DB::table('lesson_progresses')->upsert(
                [$progressValues],
                [
                    'center_id',
                    'student_profile_id',
                    'lesson_id',
                ],
                $updateColumns
            );

            // Preserve the original completion timestamp.
            if ($completedRequested) {
                DB::table('lesson_progresses')
                    ->where('center_id', $center->id)
                    ->where('student_profile_id', $studentProfile->id)
                    ->where('lesson_id', $lesson->id)
                    ->whereNull('completed_at')
                    ->update([
                        'status' => LessonProgress::STATUS_COMPLETED,
                        'completed_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        });

        $progress = LessonProgress::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'lesson_id' => $lesson->id,
                'status' => $progress->status,
                'last_position_seconds' => $progress->last_position_seconds,
                'started_at' => $progress->started_at?->toIso8601String(),
                'completed_at' => $progress->completed_at?->toIso8601String(),
                'last_accessed_at' => $progress->last_accessed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resolve and authorize the requested course and lesson.
     *
     * @return array{
     *     0: Center,
     *     1: Course,
     *     2: Lesson,
     *     3: StudentProfile|null,
     *     4: bool
     * }
     */
    private function resolveLearningContext(Request $request): array
    {
        $center = $request->attributes->get('current_center');

        abort_unless(
            $center instanceof Center,
            404,
            'Center not found.'
        );

        $courseParam = (string) $request->route('course');

        $courseQuery = Course::query()
            ->forCenter($center->id);

        if (ctype_digit($courseParam)) {
            $courseQuery->whereKey((int) $courseParam);
        } else {
            $courseQuery->where('slug', $courseParam);
        }

        $course = $courseQuery->firstOrFail();

        Gate::authorize('viewLearning', [$course, $center]);

        $isAdminPreview = $this->learningAccess
            ->isAdministrativeViewer(
                $request->user(),
                $center
            );

        $lessonParam = (string) $request->route('lesson');

        abort_unless(
            ctype_digit($lessonParam),
            404,
            'Lesson not found.'
        );

        $lessonQuery = Lesson::query()
            ->forCenter($center->id)
            ->whereKey((int) $lessonParam);

        if (! $isAdminPreview) {
            $lessonQuery
                ->published()
                ->whereHas(
                    'courseModule',
                    function (Builder $query) use ($center, $course): void {
                        $query
                            ->forCenter($center->id)
                            ->where('course_id', $course->id)
                            ->published();
                    }
                )
                ->whereHas(
                    'publishedRevision',
                    function (Builder $query): void {
                        $query->published();
                    }
                );
        } else {
            // Administrative previews include unpublished module and lesson records.
            $lessonQuery->whereHas(
                'courseModule',
                function (Builder $query) use ($center, $course): void {
                    $query
                        ->forCenter($center->id)
                        ->where('course_id', $course->id);
                }
            );
        }

        $lesson = $lessonQuery
            ->with([
                'courseModule:id,center_id,course_id,title,description,sort_order,status,published_at',
            ])
            ->firstOrFail();

        Gate::authorize(
            'viewLearning',
            [$lesson, $course, $center]
        );

        $studentProfile = null;

        if (! $isAdminPreview) {
            $studentProfile = $this->learningAccess
                ->findActiveStudentProfile(
                    $request->user(),
                    $center
                );

            abort_unless(
                $studentProfile instanceof StudentProfile,
                403,
                'Active student profile required.'
            );
        }

        return [
            $center,
            $course,
            $lesson,
            $studentProfile,
            $isAdminPreview,
        ];
    }

    /**
     * Retrieve the course structure according to the viewer's permissions.
     *
     * @return EloquentCollection<int, CourseModule>
     */
    private function courseModulesForViewer(
        Center $center,
        Course $course,
        bool $isAdminPreview
    ): EloquentCollection {
        $query = CourseModule::query()
            ->forCenter($center->id)
            ->where('course_id', $course->id);

        if (! $isAdminPreview) {
            $query->published();
        }

        return $query
            ->with([
                'lessons' => function ($lessonQuery) use (
                    $center,
                    $isAdminPreview
                ): void {
                    $lessonQuery->forCenter($center->id);

                    if (! $isAdminPreview) {
                        $lessonQuery
                            ->published()
                            ->whereHas(
                                'publishedRevision',
                                function (Builder $query): void {
                                    $query->published();
                                }
                            );
                    }
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
