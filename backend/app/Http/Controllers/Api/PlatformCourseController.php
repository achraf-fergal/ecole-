<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformCourseController extends Controller
{
    /**
     * Return lightweight course and curriculum metadata for the admin details page.
     * Lesson content and protected asset URLs are loaded separately on demand.
     */
    public function show(
        Center $center,
        string $courseSlug
    ): JsonResponse {
        $course = $this->courseForCenter($center, $courseSlug)
            ->load([
                'language:id,center_id,code,name,native_name',
                'level:id,center_id,code,name',
            ]);

        $modules = $course->modules()
            ->where('center_id', $center->id)
            ->with([
                'lessons' => function ($query) use ($center): void {
                    $query
                        ->forCenter($center->id)
                        ->withCount([
                            'assets as assets_count' => fn ($assets) => $assets
                                ->where('center_id', $center->id),
                            'exercises as exercises_count' => fn ($exercises) => $exercises
                                ->forCenter($center->id),
                        ]);
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $lessons = $modules->flatMap(
            static fn (CourseModule $module) => $module->lessons
        );

        $modulePayload = $modules->map(
            static function (CourseModule $module): array {
                return [
                    'id' => $module->id,
                    'title' => $module->title,
                    'description' => $module->description,
                    'sort_order' => $module->sort_order,
                    'status' => $module->status,
                    'published_at' => $module->published_at?->toIso8601String(),
                    'lesson_count' => $module->lessons->count(),
                    'published_lesson_count' => $module->lessons
                        ->where('status', Lesson::STATUS_PUBLISHED)
                        ->count(),
                    'lessons' => $module->lessons->map(
                        static fn (Lesson $lesson): array => [
                            'id' => $lesson->id,
                            'title' => $lesson->title,
                            'description' => $lesson->description,
                            'content_type' => $lesson->content_type,
                            'duration_minutes' => $lesson->duration_minutes,
                            'sort_order' => $lesson->sort_order,
                            'status' => $lesson->status,
                            'published_at' => $lesson->published_at?->toIso8601String(),
                            'updated_at' => $lesson->updated_at?->toIso8601String(),
                            'assets_count' => (int) $lesson->assets_count,
                            'exercises_count' => (int) $lesson->exercises_count,
                        ]
                    )->values(),
                ];
            }
        )->values();

        return response()->json([
            'data' => [
                'center' => [
                    'id' => $center->id,
                    'name' => $center->name,
                    'slug' => $center->slug,
                ],
                'course' => $this->coursePayload($course),
                'statistics' => [
                    'modules' => $modules->count(),
                    'lessons' => $lessons->count(),
                    'published_lessons' => $lessons
                        ->where('status', Lesson::STATUS_PUBLISHED)
                        ->count(),
                    'draft_lessons' => $lessons
                        ->where('status', Lesson::STATUS_DRAFT)
                        ->count(),
                    'archived_lessons' => $lessons
                        ->where('status', Lesson::STATUS_ARCHIVED)
                        ->count(),
                    'exercises' => (int) $lessons->sum('exercises_count'),
                    'assets' => (int) $lessons->sum('assets_count'),
                ],
                'modules' => $modulePayload,
                'language_options' => $center->languages()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'code', 'name', 'native_name'])
                    ->map(static fn ($language): array => [
                        'id' => $language->id,
                        'code' => $language->code,
                        'name' => $language->name,
                        'native_name' => $language->native_name,
                    ])
                    ->values(),
                'level_options' => $center->levels()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'code', 'name'])
                    ->map(static fn ($level): array => [
                        'id' => $level->id,
                        'code' => $level->code,
                        'name' => $level->name,
                    ])
                    ->values(),
            ],
        ]);
    }

    /** Update course metadata and its publication state for a platform admin. */
    public function update(
        Request $request,
        Center $center,
        string $courseSlug
    ): JsonResponse {
        $course = $this->courseForCenter($center, $courseSlug);

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
            'status' => ['sometimes', 'required', Rule::in(['draft', 'published', 'archived'])],
        ]);

        if (array_key_exists('status', $validated)) {
            if ($validated['status'] === 'published') {
                $validated['published_at'] = $course->status === 'published'
                    ? ($course->published_at ?? now())
                    : now();
            } else {
                $validated['published_at'] = null;
            }
        }

        $course->fill($validated)->save();

        return response()->json([
            'data' => $this->coursePayload(
                $course->fresh([
                    'language:id,center_id,code,name,native_name',
                    'level:id,center_id,code,name',
                ])
            ),
        ]);
    }

    private function courseForCenter(Center $center, string $courseSlug): Course
    {
        return $center->courses()
            ->where('slug', $courseSlug)
            ->firstOrFail();
    }

    private function coursePayload(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'short_description' => $course->short_description,
            'description' => $course->description,
            'source_locale' => $course->source_locale,
            'study_format' => $course->study_format,
            'duration_hours' => $course->duration_hours,
            'cover_image_url' => $course->cover_image_url,
            'cover_image_alt' => $course->cover_image_alt,
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
        ];
    }
}
