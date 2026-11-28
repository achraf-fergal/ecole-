<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\User;
use App\Services\Learning\AdminCurriculumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                    'cover_image_url' => $course->cover_image_url,
                    'cover_image_alt' => $course->cover_image_alt,
                    'source_locale' => $course->source_locale,
                    'study_format' => $course->study_format,
                    'duration_hours' => $course->duration_hours,
                    'status' => $course->status,
                    'published_at' => $course->published_at?->toIso8601String(),
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
                ],
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
            'status' => ['sometimes', 'required', 'string', Rule::in([
                CourseModule::STATUS_DRAFT,
                CourseModule::STATUS_PUBLISHED,
                CourseModule::STATUS_ARCHIVED,
            ])],
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
            'status' => ['sometimes', 'required', 'string', Rule::in([
                Lesson::STATUS_DRAFT,
                Lesson::STATUS_PUBLISHED,
                Lesson::STATUS_ARCHIVED,
            ])],
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
        ]);

        $targetModule = $this->resolveModule($center, $course, (string) $validated['target_module_id']);
        $moved = $this->curriculumService->moveLesson(
            $lesson,
            $targetModule,
            $validated['sort_order'] ?? null
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

    private function lessonPayload(Lesson $lesson): array
    {
        $activeRevision = $lesson->draftRevision ?? $lesson->publishedRevision;
        $blocks = $activeRevision?->blocks ?? collect();
        $effectiveKind = $this->curriculumService->classifyRevisionKind($activeRevision, $lesson);

        $videoBlock = $blocks->first(
            static fn (LessonBlock $b): bool => $b->block_type === LessonBlock::TYPE_MEDIA && $b->asset?->asset_type === LessonAsset::TYPE_VIDEO
        );
        $resourceCount = $blocks->filter(
            static fn (LessonBlock $b): bool => $b->block_type === LessonBlock::TYPE_MEDIA && $b->asset?->asset_type !== LessonAsset::TYPE_VIDEO
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
            'assets_count' => (int) ($lesson->assets_count ?? 0),
            'exercises_count' => (int) ($lesson->exercises_count ?? 0),
            'summary' => [
                'has_video' => $videoBlock !== null,
                'video_duration_seconds' => $videoBlock?->asset?->duration_seconds,
                'has_reading_content' => $hasReadingContent,
                'resources_count' => $resourceCount,
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
