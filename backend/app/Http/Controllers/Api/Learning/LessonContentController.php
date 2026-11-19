<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\User;
use App\Services\Learning\LessonRevisionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LessonContentController extends Controller
{
    public function __construct(
        protected LessonRevisionService $revisionService
    ) {}

    /**
     * Retrieve current draft and published content.
     */
    public function show(Request $request): JsonResponse
    {
        [$center, $course, $lesson] =
            $this->resolveLessonContext($request);

        $lesson->load([
            'draftRevision.blocks.asset',
            'draftRevision.blocks.exercise.questions.options',

            'publishedRevision.blocks.asset',
            'publishedRevision.blocks.exercise.questions.options',
        ]);

        return response()->json([
            'data' => [
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                ],

                'lesson' => [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'content_type' => $lesson->content_type,
                    'status' => $lesson->status,
                ],

                'draft_revision' => $this->revisionPayload(
                    $lesson->draftRevision
                ),

                'published_revision' => $this->revisionPayload(
                    $lesson->publishedRevision
                ),
            ],
        ]);
    }

    /**
     * Create or retrieve the current draft.
     */
    public function createDraft(Request $request): JsonResponse
    {
        $lessonContext = $this->resolveLessonContext($request);
        $lesson = $lessonContext[2];

        $actor = $request->user();

        abort_unless(
            $actor instanceof User,
            401,
            'Unauthenticated.'
        );

        $revision = $this->revisionService->createDraft(
            $lesson,
            $actor
        );

        $revision->load([
            'blocks.asset',
            'blocks.exercise.questions.options',
        ]);

        return response()->json([
            'message' => 'Draft revision is ready.',
            'data' => $this->revisionPayload($revision),
        ]);
    }

    /**
     * Update metadata on the current draft revision.
     */
    public function updateDraftMetadata(Request $request): JsonResponse
    {
        $lessonContext = $this->resolveLessonContext($request);
        $lesson = $lessonContext[2];

        $revision = $this->resolveRevision(
            $lesson,
            (string) $request->route('revision')
        );

        $validated = $request->validate([
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'content_locale' => [
                'sometimes',
                'required',
                'string',
                'max:35',
            ],

            'duration_minutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:65535',
            ],
        ]);

        if ($validated === []) {
            throw ValidationException::withMessages([
                'attributes' => 'At least one metadata attribute is required.',
            ]);
        }

        $revision = $this->revisionService->updateDraftMetadata(
            $revision,
            $validated
        );

        return response()->json([
            'message' => 'Draft metadata updated successfully.',
            'data' => $this->revisionPayload(
                $revision->load([
                    'blocks.asset',
                    'blocks.exercise.questions.options',
                ])
            ),
        ]);
    }

    /**
     * Add a rich-text block.
     */
    public function storeTextBlock(Request $request): JsonResponse
    {
        $lessonContext = $this->resolveLessonContext($request);
        $lesson = $lessonContext[2];

        $revision = $this->resolveRevision(
            $lesson,
            (string) $request->route('revision')
        );

        $validated = $request->validate([
            'content_json' => [
                'required',
                'array',
            ],

            'settings' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ]);

        $block = $this->revisionService->addTextBlock(
            $revision,
            $validated['content_json'],
            $validated['settings'] ?? null
        );

        return response()->json([
            'message' => 'Text block added successfully.',
            'data' => $this->loadBlockPayload($block),
        ], 201);
    }

    /**
     * Add an existing uploaded asset to the revision.
     */
    public function storeMediaBlock(Request $request): JsonResponse
    {
        $lessonContext = $this->resolveLessonContext($request);
        $lesson = $lessonContext[2];

        $revision = $this->resolveRevision(
            $lesson,
            (string) $request->route('revision')
        );

        $validated = $request->validate([
            'asset_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'settings' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ]);

        $block = $this->revisionService->addMediaBlock(
            $revision,
            (int) $validated['asset_id'],
            $validated['settings'] ?? null
        );

        return response()->json([
            'message' => 'Media block added successfully.',
            'data' => $this->loadBlockPayload($block),
        ], 201);
    }

    /**
     * Add an existing exercise to the revision.
     */
    public function storeExerciseBlock(Request $request): JsonResponse
    {
        $lessonContext = $this->resolveLessonContext($request);
        $lesson = $lessonContext[2];

        $revision = $this->resolveRevision(
            $lesson,
            (string) $request->route('revision')
        );

        $validated = $request->validate([
            'exercise_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'settings' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ]);

        $block = $this->revisionService->addExerciseBlock(
            $revision,
            (int) $validated['exercise_id'],
            $validated['settings'] ?? null
        );

        return response()->json([
            'message' => 'Exercise block added successfully.',
            'data' => $this->loadBlockPayload($block),
        ], 201);
    }

    /**
     * Update text content or block presentation settings.
     */
    public function updateBlock(Request $request): JsonResponse
    {
        $lessonContext = $this->resolveLessonContext($request);
        $lesson = $lessonContext[2];

        $revision = $this->resolveRevision(
            $lesson,
            (string) $request->route('revision')
        );

        $unexpected = array_diff(
            array_keys($request->all()),
            ['content_json', 'settings']
        );

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'attributes' => 'Unsupported block attributes.',
            ]);
        }

        $validated = $request->validate([
            'content_json' => [
                'sometimes',
                'array',
            ],

            'settings' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ]);

        if ($validated === []) {
            throw ValidationException::withMessages([
                'attributes' => 'At least one attribute is required.',
            ]);
        }

        $block = $this->revisionService->updateBlock(
            $revision,
            (int) $request->route('block'),
            $validated
        );

        return response()->json([
            'message' => 'Block updated successfully.',
            'data' => $this->loadBlockPayload($block),
        ]);
    }

    /**
     * Delete a block from the current draft.
     */
    public function deleteBlock(Request $request): JsonResponse
    {
        $lessonContext = $this->resolveLessonContext($request);
        $lesson = $lessonContext[2];

        $revision = $this->resolveRevision(
            $lesson,
            (string) $request->route('revision')
        );

        $this->revisionService->deleteBlock(
            $revision,
            (int) $request->route('block')
        );

        return response()->json([
            'message' => 'Block deleted successfully.',
        ]);
    }

    /**
     * Replace the complete block ordering.
     */
    public function reorderBlocks(Request $request): JsonResponse
    {
        $lessonContext = $this->resolveLessonContext($request);
        $lesson = $lessonContext[2];

        $revision = $this->resolveRevision(
            $lesson,
            (string) $request->route('revision')
        );

        $validated = $request->validate([
            'ordered_block_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'ordered_block_ids.*' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
        ]);

        $blocks = $this->revisionService->reorderBlocks(
            $revision,
            $validated['ordered_block_ids']
        );

        $blocks->load([
            'asset',
            'exercise.questions.options',
        ]);

        return response()->json([
            'message' => 'Block ordering updated successfully.',

            'data' => $blocks->map(
                fn (LessonBlock $block): array => $this->blockPayload($block)
            )->values(),
        ]);
    }

    /**
     * Resolve the authorized administrative lesson context.
     *
     * @return array{0: Center, 1: Course, 2: Lesson}
     */
    private function resolveLessonContext(
        Request $request
    ): array {
        $center = $request->attributes->get('current_center');

        abort_unless(
            $center instanceof Center,
            404,
            'Center not found.'
        );

        $user = $request->user();

        abort_unless(
            $user instanceof User,
            401,
            'Unauthenticated.'
        );

        $hasAdminAccess = $user->isPlatformAdmin()
            || $user->hasAnyRoleInCenter(
                $center,
                ['center_admin']
            );

        abort_unless(
            $hasAdminAccess,
            403,
            'Administrative learning access is required.'
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

        $lessonParam = (string) $request->route('lesson');

        abort_unless(
            ctype_digit($lessonParam),
            404,
            'Lesson not found.'
        );

        $lesson = Lesson::query()
            ->forCenter($center->id)
            ->whereKey((int) $lessonParam)
            ->whereHas(
                'courseModule',
                function (Builder $query) use (
                    $center,
                    $course
                ): void {
                    $query
                        ->forCenter($center->id)
                        ->where('course_id', $course->id);
                }
            )
            ->firstOrFail();

        return [$center, $course, $lesson];
    }

    /**
     * Resolve a revision strictly inside the current lesson.
     */
    private function resolveRevision(
        Lesson $lesson,
        string $revisionParam
    ): LessonRevision {
        abort_unless(
            ctype_digit($revisionParam),
            404,
            'Revision not found.'
        );

        return LessonRevision::query()
            ->forCenter($lesson->center_id)
            ->forLesson($lesson->id)
            ->whereKey((int) $revisionParam)
            ->firstOrFail();
    }

    /**
     * Serialize revision metadata and ordered blocks.
     */
    private function revisionPayload(
        ?LessonRevision $revision
    ): ?array {
        if (! $revision) {
            return null;
        }

        return [
            'id' => $revision->id,
            'version_number' => $revision->version_number,
            'status' => $revision->status,
            'title' => $revision->title,
            'description' => $revision->description,
            'content_locale' => $revision->content_locale,
            'duration_minutes' => $revision->duration_minutes,
            'created_at' => $revision->created_at?->toIso8601String(),
            'published_at' => $revision->published_at?->toIso8601String(),

            'blocks' => $revision->blocks->map(
                fn (LessonBlock $block): array => $this->blockPayload($block)
            )->values(),
        ];
    }

    /**
     * Load relations and return a safe block payload.
     */
    private function loadBlockPayload(
        LessonBlock $block
    ): array {
        $block->load([
            'asset',
            'exercise.questions.options',
        ]);

        return $this->blockPayload($block);
    }

    /**
     * Serialize block data.
     *
     * Storage keys and provider credentials are never exposed.
     */
    private function blockPayload(
        LessonBlock $block
    ): array {
        $asset = $block->asset;

        $exercise = $block->exercise;

        return [
            'id' => $block->id,
            'block_type' => $block->block_type,
            'sort_order' => $block->sort_order,
            'content_json' => $block->content_json,
            'settings' => $block->settings,

            'asset' => $asset ? [
                'id' => $asset->id,
                'title' => $asset->title,
                'asset_type' => $asset->asset_type,
                'original_filename' => $asset->original_filename,
                'mime_type' => $asset->mime_type,
                'size_bytes' => $asset->size_bytes,
                'duration_seconds' => $asset->duration_seconds,
                'alt_text' => $asset->alt_text,
                'caption' => $asset->caption,
                'is_downloadable' => $asset->is_downloadable,
                'status' => $asset->status,
            ] : null,

            // This endpoint is administrative.
            // Correct answers are never included in student responses.
            'exercise' => $exercise ? [
                'id' => $exercise->id,
                'title' => $exercise->title,
                'instructions' => $exercise->instructions,
                'max_attempts' => $exercise->max_attempts,
                'passing_score' => $exercise->passing_score,
                'status' => $exercise->status,

                'questions' => $exercise->questions->map(
                    function ($question): array {
                        return [
                            'id' => $question->id,
                            'question_type' => $question->question_type,
                            'prompt' => $question->prompt,
                            'explanation' => $question->explanation,
                            'points' => $question->points,

                            'options' => $question->options->map(
                                fn ($option): array => [
                                    'id' => $option->id,
                                    'option_text' => $option->option_text,
                                    'is_correct' => $option->is_correct,
                                ]
                            )->values(),
                        ];
                    }
                )->values(),
            ] : null,
        ];
    }
}
