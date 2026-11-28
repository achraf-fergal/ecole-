<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseOutcome;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\User;
use App\Services\Learning\AdminCurriculumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCurriculumController extends Controller
{
    public function __construct(
        protected AdminCurriculumService $curriculumService
    ) {}

    public function curriculum(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $course->load([
            'language:id,center_id,code,name,native_name',
            'level:id,center_id,code,name',
            'outcomes' => fn ($q) => $q->where('center_id', $center->id)->orderBy('sort_order')->orderBy('id'),
        ]);

        $modules = $course->modules()
            ->where('center_id', $center->id)
            ->with([
                'lessons' => function ($query) use ($center): void {
                    $query
                        ->forCenter($center->id)
                        ->with([
                            'draftRevision.blocks.asset',
                            'publishedRevision.blocks.asset',
                            'exercises.questions:id,exercise_id,points',
                        ])
                        ->withCount([
                            'assets as assets_count' => fn ($assets) => $assets->where('center_id', $center->id),
                            'exercises as exercises_count' => fn ($exercises) => $exercises->forCenter($center->id),
                        ]);
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $allLessons = $modules->flatMap(static fn (CourseModule $m) => $m->lessons);

        $modulePayloads = $modules->map(function (CourseModule $module): array {
            return [
                'id' => $module->id,
                'title' => $module->title,
                'description' => $module->description,
                'sort_order' => $module->sort_order,
                'status' => $module->status,
                'published_at' => $module->published_at?->toIso8601String(),
                'lesson_count' => $module->lessons->count(),
                'published_lesson_count' => $module->lessons->where('status', Lesson::STATUS_PUBLISHED)->count(),
                'lessons' => $module->lessons->map(fn (Lesson $lesson): array => $this->lessonPayload($lesson))->values(),
            ];
        })->values();

        return response()->json([
            'data' => [
                'center' => [
                    'id' => $center->id,
                    'name' => $center->name,
                    'slug' => $center->slug,
                ],
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'short_description' => $course->short_description,
                    'description' => $course->description,
                    'prerequisites' => $course->prerequisites,
                    'target_audience' => $course->target_audience,
                    'cover_image_url' => $course->cover_image_url,
                    'cover_image_alt' => $course->cover_image_alt,
                    'source_locale' => $course->source_locale,
                    'study_format' => $course->study_format,
                    'duration_hours' => $course->duration_hours,
                    'price' => $course->price !== null ? (string) $course->price : null,
                    'currency' => $course->currency,
                    'pricing_mode' => $course->pricing_mode,
                    'is_curriculum_public' => (bool) $course->is_curriculum_public,
                    'meta_title' => $course->meta_title,
                    'meta_description' => $course->meta_description,
                    'status' => $course->status,
                    'published_at' => $course->published_at?->toIso8601String(),
                    'updated_at' => $course->updated_at?->toIso8601String(),
                    'language' => $course->language ? [
                        'id' => $course->language->id,
                        'code' => $course->language->code,
                        'name' => $course->language->name,
                        'native_name' => $course->language->native_name,
                    ] : null,
                    'level' => $course->level ? [
                        'id' => $course->level->id,
                        'code' => $course->level->code,
                        'name' => $course->level->name,
                    ] : null,
                    'outcomes' => $course->outcomes->map(static fn ($o): array => [
                        'id' => $o->id,
                        'locale' => $o->locale,
                        'content' => $o->content,
                        'is_highlight' => (bool) $o->is_highlight,
                        'sort_order' => (int) $o->sort_order,
                        'status' => $o->status,
                    ])->values(),
                ],
                'language_options' => $center->languages()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'code', 'name', 'native_name'])
                    ->map(static fn ($l): array => [
                        'id' => $l->id,
                        'code' => $l->code,
                        'name' => $l->name,
                        'native_name' => $l->native_name,
                    ])->values(),
                'level_options' => $center->levels()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'code', 'name'])
                    ->map(static fn ($lv): array => [
                        'id' => $lv->id,
                        'code' => $lv->code,
                        'name' => $lv->name,
                    ])->values(),
                'statistics' => [
                    'modules' => $modules->count(),
                    'lessons' => $allLessons->count(),
                    'published_lessons' => $allLessons->where('status', Lesson::STATUS_PUBLISHED)->count(),
                    'draft_lessons' => $allLessons->where('status', Lesson::STATUS_DRAFT)->count(),
                    'archived_lessons' => $allLessons->where('status', Lesson::STATUS_ARCHIVED)->count(),
                    'exercises' => (int) $allLessons->sum('exercises_count'),
                    'assets' => (int) $allLessons->sum('assets_count'),
                ],
                'modules' => $modulePayloads,
            ],
        ]);
    }

    public function storeModule(Request $request): JsonResponse
    {
        [, $course] = $this->resolveCourseContext($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', Rule::in([CourseModule::STATUS_DRAFT, CourseModule::STATUS_PUBLISHED])],
        ]);

        $module = $this->curriculumService->createModule($course, $validated);

        return response()->json([
            'data' => [
                'id' => $module->id,
                'title' => $module->title,
                'description' => $module->description,
                'sort_order' => $module->sort_order,
                'status' => $module->status,
                'published_at' => $module->published_at?->toIso8601String(),
                'lesson_count' => 0,
                'published_lesson_count' => 0,
                'lessons' => [],
            ],
        ], 201);
    }

    public function updateModule(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $module = $this->resolveModule($center, $course, (string) $request->route('module'));

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->curriculumService->updateModule($module, $validated);

        return response()->json([
            'data' => [
                'id' => $updated->id,
                'title' => $updated->title,
                'description' => $updated->description,
                'sort_order' => $updated->sort_order,
                'status' => $updated->status,
                'published_at' => $updated->published_at?->toIso8601String(),
            ],
        ]);
    }

    public function publishModule(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $module = $this->resolveModule($center, $course, (string) $request->route('module'));

        $published = $this->curriculumService->publishModule($module);

        return response()->json([
            'data' => [
                'id' => $published->id,
                'status' => $published->status,
                'published_at' => $published->published_at?->toIso8601String(),
            ],
        ]);
    }

    public function restoreModule(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $module = $this->resolveModule($center, $course, (string) $request->route('module'));

        $restored = $this->curriculumService->restoreModule($module);

        return response()->json([
            'data' => [
                'id' => $restored->id,
                'status' => $restored->status,
                'published_at' => $restored->published_at?->toIso8601String(),
            ],
        ]);
    }

    public function reorderModules(Request $request): JsonResponse
    {
        [, $course] = $this->resolveCourseContext($request);

        $validated = $request->validate([
            'module_ids' => ['required', 'array', 'min:1'],
            'module_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $this->curriculumService->reorderModules($course, $validated['module_ids']);

        return response()->json([
            'message' => 'Modules reordered successfully.',
        ]);
    }

    public function archiveModule(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $module = $this->resolveModule($center, $course, (string) $request->route('module'));

        $archived = $this->curriculumService->archiveModule($module);

        return response()->json([
            'data' => [
                'id' => $archived->id,
                'status' => $archived->status,
            ],
        ]);
    }

    public function storeLesson(Request $request): JsonResponse
    {
        [$center, $course, $actor] = $this->resolveCourseContext($request);
        $module = $this->resolveModule($center, $course, (string) $request->route('module'));

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'content_type' => ['required', 'string', Rule::in(Lesson::CANONICAL_KINDS)],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'content_locale' => ['nullable', 'string', 'max:35'],
        ]);

        $lesson = $this->curriculumService->createLesson($module, $validated, $actor);

        return response()->json([
            'data' => $this->lessonPayload($lesson),
        ], 201);
    }

    public function updateLesson(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $lesson = $this->resolveLesson($center, $course, (string) $request->route('lesson'));

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'content_type' => ['sometimes', 'required', 'string', Rule::in(array_merge(Lesson::CANONICAL_KINDS, [Lesson::KIND_LEGACY_MIXED]))],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $updated = $this->curriculumService->updateLesson($lesson, $validated);

        return response()->json([
            'data' => $this->lessonPayload($updated),
        ]);
    }

    public function moveLesson(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $lesson = $this->resolveLesson($center, $course, (string) $request->route('lesson'));

        $validated = $request->validate([
            'target_module_id' => ['required', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'placement' => ['nullable', 'string', Rule::in(['first', 'last', 'before', 'after'])],
            'anchor_lesson_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $targetModule = $this->resolveModule($center, $course, (string) $validated['target_module_id']);
        $moved = $this->curriculumService->moveLesson(
            $lesson,
            $targetModule,
            $validated['sort_order'] ?? null,
            (string) ($validated['placement'] ?? 'last'),
            isset($validated['anchor_lesson_id']) ? (int) $validated['anchor_lesson_id'] : null
        );

        return response()->json([
            'data' => $this->lessonPayload($moved),
        ]);
    }

    public function reorderLessons(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $module = $this->resolveModule($center, $course, (string) $request->route('module'));

        $validated = $request->validate([
            'lesson_ids' => ['required', 'array', 'min:1'],
            'lesson_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $this->curriculumService->reorderLessons($module, $validated['lesson_ids']);

        return response()->json([
            'message' => 'Lessons reordered successfully.',
        ]);
    }

    public function duplicateLesson(Request $request): JsonResponse
    {
        [$center, $course, $actor] = $this->resolveCourseContext($request);
        $lesson = $this->resolveLesson($center, $course, (string) $request->route('lesson'));

        $copy = $this->curriculumService->duplicateLesson($lesson, $actor);

        return response()->json([
            'data' => $this->lessonPayload($copy),
        ], 201);
    }

    public function archiveLesson(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $lesson = $this->resolveLesson($center, $course, (string) $request->route('lesson'));

        $archived = $this->curriculumService->archiveLesson($lesson);

        return response()->json([
            'data' => [
                'id' => $archived->id,
                'status' => $archived->status,
            ],
        ]);
    }

    public function restoreLesson(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $lesson = $this->resolveLesson($center, $course, (string) $request->route('lesson'));

        $restored = $this->curriculumService->restoreLesson($lesson);

        return response()->json([
            'data' => $this->lessonPayload($restored),
        ]);
    }

    /**
     * Authenticated private Draft/Published Course Preview for authorized admins.
     * Never weakens public endpoints and never sets public cache headers.
     */
    public function previewCourse(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);
        $course->load([
            'language:id,center_id,code,name,native_name',
            'level:id,center_id,code,name',
            'outcomes' => fn ($q) => $q->where('center_id', $center->id)->orderBy('sort_order')->orderBy('id'),
        ]);

        $version = $request->query('version', 'draft') === 'published' ? 'published' : 'draft';

        $modules = $course->modules()
            ->where('center_id', $center->id)
            ->when($version === 'published', fn ($q) => $q->published())
            ->where('status', '!=', CourseModule::STATUS_ARCHIVED)
            ->with([
                'lessons' => function ($query) use ($center, $version): void {
                    $query->forCenter($center->id)
                        ->where('status', '!=', Lesson::STATUS_ARCHIVED)
                        ->when($version === 'published', fn ($q) => $q->published());
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'preview_version' => $version,
                'has_draft_changes' => $course->status !== 'published' || $modules->contains(
                    fn (CourseModule $m): bool => $m->status !== CourseModule::STATUS_PUBLISHED
                        || $m->lessons->contains(fn (Lesson $l): bool => $l->status !== Lesson::STATUS_PUBLISHED || $l->draft_revision_id !== null)
                ),
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'short_description' => $course->short_description,
                    'description' => $course->description,
                    'prerequisites' => $course->prerequisites,
                    'target_audience' => $course->target_audience,
                    'study_format' => $course->study_format,
                    'duration_hours' => $course->duration_hours,
                    'cover_image_url' => $course->cover_image_url,
                    'cover_image_alt' => $course->cover_image_alt,
                    'price' => $course->price !== null ? (string) $course->price : null,
                    'currency' => $course->currency,
                    'pricing_mode' => $course->pricing_mode,
                    'is_curriculum_public' => (bool) $course->is_curriculum_public,
                    'status' => $course->status,
                    'language' => $course->language?->only(['id', 'code', 'name', 'native_name']),
                    'level' => $course->level?->only(['id', 'code', 'name']),
                    'outcomes' => $course->outcomes->map->only(['id', 'content', 'is_highlight'])->values(),
                ],
                'modules' => $modules->map(fn (CourseModule $m): array => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'description' => $m->description,
                    'status' => $m->status,
                    'lessons' => $m->lessons->map(fn (Lesson $l): array => [
                        'id' => $l->id,
                        'title' => $l->title,
                        'content_type' => $l->content_type,
                        'duration_minutes' => $l->duration_minutes,
                        'status' => $l->status,
                        'has_draft' => $l->draft_revision_id !== null,
                    ])->values(),
                ])->values(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function updateCourseSettings(Request $request): JsonResponse
    {
        [$center, $course] = $this->resolveCourseContext($request);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('courses', 'slug')
                    ->where('center_id', $center->id)
                    ->ignore($course->id),
            ],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'prerequisites' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'target_audience' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'language_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('languages', 'id')->where('center_id', $center->id),
            ],
            'level_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('levels', 'id')->where('center_id', $center->id),
            ],
            'source_locale' => ['sometimes', 'required', 'string', 'max:35'],
            'study_format' => ['sometimes', 'required', Rule::in(['online', 'in_person', 'hybrid'])],
            'duration_hours' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'cover_image_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'cover_image_alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pricing_mode' => ['sometimes', 'required', Rule::in([
                Course::PRICING_FIXED,
                Course::PRICING_FROM,
                Course::PRICING_UPON_REQUEST,
                Course::PRICING_FREE,
            ])],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'is_curriculum_public' => ['sometimes', 'boolean'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'published', 'archived'])],
            'outcomes' => ['sometimes', 'array', 'max:30'],
            'outcomes.*.content' => ['required_with:outcomes', 'string', 'max:500'],
            'outcomes.*.is_highlight' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($center, $course, $validated): void {
            $courseData = $validated;
            unset($courseData['outcomes']);

            if (array_key_exists('status', $courseData)) {
                if ($courseData['status'] === 'published') {
                    $courseData['published_at'] = $course->status === 'published'
                        ? ($course->published_at ?? now())
                        : now();
                } else {
                    $courseData['published_at'] = null;
                }
            }

            $course->fill($courseData)->save();

            if (array_key_exists('outcomes', $validated)) {
                $locale = $course->source_locale ?: 'en';
                CourseOutcome::query()
                    ->where('center_id', $center->id)
                    ->where('course_id', $course->id)
                    ->where('locale', $locale)
                    ->delete();

                foreach ($validated['outcomes'] as $index => $item) {
                    $text = trim((string) ($item['content'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    CourseOutcome::query()->create([
                        'center_id' => $center->id,
                        'course_id' => $course->id,
                        'locale' => $locale,
                        'content' => $text,
                        'is_highlight' => (bool) ($item['is_highlight'] ?? false),
                        'sort_order' => $index,
                        'status' => 'published',
                        'published_at' => now(),
                    ]);
                }
            }
        });

        $fresh = $course->fresh([
            'language:id,center_id,code,name,native_name',
            'level:id,center_id,code,name',
            'outcomes' => fn ($q) => $q->where('center_id', $center->id)->orderBy('sort_order')->orderBy('id'),
        ]);

        return response()->json([
            'data' => [
                'id' => $fresh->id,
                'title' => $fresh->title,
                'slug' => $fresh->slug,
                'short_description' => $fresh->short_description,
                'description' => $fresh->description,
                'prerequisites' => $fresh->prerequisites,
                'target_audience' => $fresh->target_audience,
                'cover_image_url' => $fresh->cover_image_url,
                'cover_image_alt' => $fresh->cover_image_alt,
                'source_locale' => $fresh->source_locale,
                'study_format' => $fresh->study_format,
                'duration_hours' => $fresh->duration_hours,
                'price' => $fresh->price !== null ? (string) $fresh->price : null,
                'currency' => $fresh->currency,
                'pricing_mode' => $fresh->pricing_mode,
                'is_curriculum_public' => (bool) $fresh->is_curriculum_public,
                'meta_title' => $fresh->meta_title,
                'meta_description' => $fresh->meta_description,
                'status' => $fresh->status,
                'published_at' => $fresh->published_at?->toIso8601String(),
                'updated_at' => $fresh->updated_at?->toIso8601String(),
                'language' => $fresh->language?->only(['id', 'code', 'name', 'native_name']),
                'level' => $fresh->level?->only(['id', 'code', 'name']),
                'outcomes' => $fresh->outcomes->map->only(['id', 'locale', 'content', 'is_highlight', 'sort_order', 'status'])->values(),
            ],
        ]);
    }

    private function lessonPayload(Lesson $lesson): array
    {
        $activeRevision = $lesson->draftRevision ?? $lesson->publishedRevision;
        $blocks = $activeRevision?->blocks ?? collect();
        $effectiveKind = $this->curriculumService->classifyRevisionKind($activeRevision, $lesson);

        $videoBlock = $blocks->first(
            static fn (LessonBlock $b): bool => $b->block_type === LessonBlock::TYPE_MEDIA
                && $b->asset !== null
                && $b->asset->status !== LessonAsset::STATUS_ARCHIVED
                && $b->asset->asset_type === LessonAsset::TYPE_VIDEO
        );
        $resourceCount = $blocks->filter(
            static fn (LessonBlock $b): bool => $b->block_type === LessonBlock::TYPE_MEDIA
                && $b->asset !== null
                && $b->asset->status !== LessonAsset::STATUS_ARCHIVED
                && $b->asset->asset_type !== LessonAsset::TYPE_VIDEO
        )->count();
        $brokenMediaCount = $blocks->filter(
            static fn (LessonBlock $b): bool => $b->block_type === LessonBlock::TYPE_MEDIA
                && ($b->asset === null || $b->asset->status === LessonAsset::STATUS_ARCHIVED)
        )->count();
        $hasReadingContent = $blocks->contains(
            static fn (LessonBlock $b): bool => $b->block_type === LessonBlock::TYPE_TEXT
        );

        $exerciseBlock = $blocks->first(static fn (LessonBlock $b): bool => $b->block_type === LessonBlock::TYPE_EXERCISE);
        $primaryExercise = $exerciseBlock && $lesson->relationLoaded('exercises')
            ? $lesson->exercises->firstWhere('id', $exerciseBlock->exercise_id)
            : ($lesson->relationLoaded('exercises') ? $lesson->exercises->first() : null);

        $questionCount = $primaryExercise?->questions?->count() ?? 0;
        $totalPoints = $primaryExercise?->questions
            ? (float) $primaryExercise->questions->sum(static fn ($q): float => (float) $q->points)
            : 0.0;

        return [
            'id' => $lesson->id,
            'course_module_id' => $lesson->course_module_id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'content_type' => $lesson->content_type,
            'effective_kind' => $effectiveKind,
            'duration_minutes' => $lesson->duration_minutes,
            'sort_order' => $lesson->sort_order,
            'status' => $lesson->status,
            'published_at' => $lesson->published_at?->toIso8601String(),
            'updated_at' => $lesson->updated_at?->toIso8601String(),
            'draft_revision_id' => $lesson->draft_revision_id,
            'published_revision_id' => $lesson->published_revision_id,
            'has_draft_changes' => $lesson->draft_revision_id !== null,
            'assets_count' => (int) ($lesson->assets_count ?? 0),
            'exercises_count' => (int) ($lesson->exercises_count ?? 0),
            'summary' => [
                'has_video' => $videoBlock !== null,
                'video_duration_seconds' => $videoBlock?->asset?->duration_seconds,
                'has_reading_content' => $hasReadingContent,
                'resources_count' => $resourceCount,
                'broken_media_count' => $brokenMediaCount,
                'question_count' => $questionCount,
                'total_points' => $totalPoints,
            ],
        ];
    }

    /**
     * @return array{0: Center, 1: Course, 2: User}
     */
    private function resolveCourseContext(Request $request): array
    {
        $center = $request->attributes->get('current_center');
        abort_unless($center instanceof Center, 404, 'Center not found.');

        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $hasAdminAccess = $user->isPlatformAdmin() || $user->hasAnyRoleInCenter($center, ['center_admin']);
        abort_unless($hasAdminAccess, 403, 'Administrative learning access is required.');

        $courseParam = (string) $request->route('course');
        $course = Course::query()
            ->forCenter($center->id)
            ->when(
                ctype_digit($courseParam),
                fn ($q) => $q->where('id', (int) $courseParam),
                fn ($q) => $q->where('slug', $courseParam)
            )
            ->firstOrFail();

        return [$center, $course, $user];
    }

    private function resolveModule(Center $center, Course $course, string $moduleParam): CourseModule
    {
        abort_unless(ctype_digit($moduleParam), 404, 'Module not found.');

        return CourseModule::query()
            ->forCenter($center->id)
            ->where('course_id', $course->id)
            ->whereKey((int) $moduleParam)
            ->firstOrFail();
    }

    private function resolveLesson(Center $center, Course $course, string $lessonParam): Lesson
    {
        abort_unless(ctype_digit($lessonParam), 404, 'Lesson not found.');

        return Lesson::query()
            ->forCenter($center->id)
            ->whereHas('courseModule', fn ($q) => $q->where('center_id', $center->id)->where('course_id', $course->id))
            ->whereKey((int) $lessonParam)
            ->firstOrFail();
    }
}
