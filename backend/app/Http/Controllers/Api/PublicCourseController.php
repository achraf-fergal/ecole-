<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Models\\Center;
use App\\Models\\Course;
use Illuminate\\Database\\Eloquent\\Builder;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Validation\\Rule;

class PublicCourseController extends Controller
{
    public function __invoke(Request $request, Center $center): JsonResponse
    {
        abort_unless($center->isActive(), 404);

        $validated = $request->validate([
            'locale' => ['sometimes', 'string', Rule::in(['en', 'fr'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', Rule::in(['recommended', 'name_asc'])],
            'search' => ['sometimes', 'string', 'max:120'],
            'language' => ['sometimes', 'string', 'max:20'],
            'level' => ['sometimes', 'string', 'max:40'],
            'format' => ['sometimes', 'string', Rule::in(['online', 'in_person', 'hybrid'])],
        ]);

        $locale = $validated['locale'] ?? $center->default_locale ?? 'en';
        $perPage = (int) ($validated['per_page'] ?? 12);

        $query = Course::query()
            ->where('center_id', $center->id)
            ->published()
            ->whereHas('language', fn (Builder $q) => $q->where('is_active', true))
            ->whereHas('level', fn (Builder $q) => $q->where('is_active', true))
            ->with([
                'language:id,center_id,code,name,native_name',
                'level:id,center_id,code,name',
            ]);

        if (! empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function (Builder $q) use ($term): void {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('short_description', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        if (! empty($validated['language'])) {
            $query->whereHas('language', fn (Builder $q) =>
                $q->where('code', $validated['language'])
            );
        }

        if (! empty($validated['level'])) {
            $query->whereHas('level', fn (Builder $q) =>
                $q->where('code', $validated['level'])
            );
        }

        if (! empty($validated['format'])) {
            $query->where('study_format', $validated['format']);
        }

        if (($validated['sort'] ?? 'recommended') === 'name_asc') {
            $query->orderBy('title')->orderBy('id');
        } else {
            $query->orderBy('sort_order')->orderByDesc('published_at')->orderBy('id');
        }

        $courses = $query->paginate($perPage)->withQueryString();

        $languages = $center->languages()
            ->where('is_active', true)
            ->whereHas('courses', fn (Builder $q) => $q->published())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'native_name'])
            ->map(fn ($language) => [
                'value' => $language->code,
                'label' => $language->name,
            ])
            ->values();

        $levels = $center->levels()
            ->where('is_active', true)
            ->whereHas('courses', fn (Builder $q) => $q->published())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($level) => [
                'value' => $level->code,
                'label' => $level->name,
            ])
            ->values();

        $formats = collect([
            ['value' => 'online', 'label' => $locale === 'fr' ? 'En ligne' : 'Online'],
            ['value' => 'in_person', 'label' => $locale === 'fr' ? 'En présentiel' : 'In-person'],
            ['value' => 'hybrid', 'label' => $locale === 'fr' ? 'Hybride' : 'Hybrid'],
        ])->filter(fn (array $format) => $center->courses()
            ->published()
            ->where('study_format', $format['value'])
            ->exists())
            ->values();

        return response()->json([
            'data' => $courses->getCollection()->map(fn (Course $course) => [
                'id' => (string) $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'short_description' => $course->short_description,
                'study_format' => $course->study_format,
                'duration_label' => $course->duration_hours
                    ? ($locale === 'fr'
                        ? $course->duration_hours.' heures'
                        : $course->duration_hours.' hours')
                    : null,
                'cover_image_url' => $course->cover_image_url,
                'cover_image_alt' => $course->title,
                'language' => [
                    'value' => $course->language->code,
                    'label' => $course->language->name,
                ],
                'level' => [
                    'value' => $course->level->code,
                    'label' => $course->level->name,
                ],
            ])->values(),
            'meta' => [
                'current_page' => $courses->currentPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
                'last_page' => $courses->lastPage(),
            ],
            'filters' => [
                'languages' => $languages,
                'levels' => $levels,
                'formats' => $formats,
            ],
        ]);
    }
}
