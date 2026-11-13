<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicCourseController extends Controller
{
    public function index(
        Request $request,
        Center $center
    ): JsonResponse {

        // Only active centers can expose public courses.
        abort_unless($center->isActive(), 404);

        $validated = $request->validate([
            'locale' => ['sometimes', 'string', Rule::in(['en', 'fr'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:80'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'level' => ['sometimes', 'nullable', 'string', 'max:40'],
            'format' => [
                'sometimes',
                'nullable',
                Rule::in(['online', 'in_person', 'hybrid']),
            ],
            'sort' => [
                'sometimes',
                Rule::in(['recommended', 'name_asc']),
            ],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $locale = $validated['locale'] ?? 'en';

        $coursesQuery = Course::query()
            ->with([
                'language:id,center_id,code,name,native_name',
                'level:id,center_id,code,name',
                'translations' => fn (HasMany $query) => $query
                    ->published()
                    ->select([
                        'id',
                        'center_id',
                        'course_id',
                        'locale',
                    ])
                    ->orderBy('locale'),
            ])
            ->where('center_id', $center->id)
            ->published()
            ->whereHas('language', function (Builder $query) {
                $query->where('is_active', true);
            })
            ->whereHas('level', function (Builder $query) {
                $query->where('is_active', true);
            });

        // Search
        if (! empty($validated['search'])) {
            $search = trim($validated['search']);

            $coursesQuery->where(function (Builder $query) use ($search) {
                $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        // Language filter: use language code, not database ID.
        if (! empty($validated['language'])) {
            $languageCode = $validated['language'];

            $coursesQuery->whereHas(
                'language',
                fn (Builder $query) => $query->where('code', $languageCode)
            );
        }

        // Level filter: use level code, not database ID.
        if (! empty($validated['level'])) {
            $levelCode = $validated['level'];

            $coursesQuery->whereHas(
                'level',
                fn (Builder $query) => $query->where('code', $levelCode)
            );
        }

        // Study format
        if (! empty($validated['format'])) {
            $coursesQuery->where(
                'study_format',
                $validated['format']
            );
        }

        // Sorting
        if (($validated['sort'] ?? 'recommended') === 'name_asc') {
            $coursesQuery->orderBy('title');
        } else {
            $coursesQuery
                ->orderBy('sort_order')
                ->orderByDesc('published_at');
        }

        $perPage = $validated['per_page'] ?? 12;

        $paginator = $coursesQuery
            ->paginate($perPage)
            ->withQueryString();

        // Transform courses into the exact frontend contract.
        $data = $paginator->getCollection()->map(
            function (Course $course) use ($locale) {
                $language = $course->language;
                $level = $course->level;

                return [
                    'id' => $course->id,
                    'slug' => $course->slug,
                    'title' => $course->title,
                    'available_locales' => collect([
                        $course->source_locale,
                        ...$course->translations->pluck('locale')->all(),
                    ])
                        ->filter(fn ($locale) => is_string($locale) && $locale !== '')
                        ->unique()
                        ->values()
                        ->all(),

                    'short_description' => $course->short_description,

                    'language' => [
                        'code' => $language->code,
                        'name' => $locale === 'fr'
                            ? ($language->native_name ?: $language->name)
                            : $language->name,
                    ],

                    'level' => [
                        'code' => $level->code,
                        'name' => $level->name,
                    ],

                    'study_format' => $course->study_format,

                    'duration_label' => $course->duration_hours
                        ? $course->duration_hours.' hours'
                        : null,

                    'cover_image_url' => $course->cover_image_url,

                    'cover_image_alt' => $course->title,
                ];
            }
        );

        $paginator->setCollection($data);

        // Available languages belonging to this center.
        $languages = $center->languages()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'name', 'native_name'])
            ->map(fn ($language) => [
                'value' => $language->code,
                'label' => $locale === 'fr'
                    ? ($language->native_name ?: $language->name)
                    : $language->name,
            ])
            ->values();

        // Available levels belonging to this center.
        $levels = $center->levels()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->map(fn ($level) => [
                'value' => $level->code,
                'label' => $level->name,
            ])
            ->values();

        // Only show study formats actually used by published courses.
        $formats = Course::query()
            ->where('center_id', $center->id)
            ->published()
            ->whereHas('language', fn (Builder $q) => $q->where('is_active', true)
            )
            ->whereHas('level', fn (Builder $q) => $q->where('is_active', true)
            )
            ->distinct()
            ->orderBy('study_format')
            ->pluck('study_format')
            ->map(fn ($format) => [
                'value' => $format,
                'label' => match ($format) {
                    'online' => $locale === 'fr' ? 'En ligne' : 'Online',
                    'in_person' => $locale === 'fr'
                        ? 'En présentiel'
                        : 'In-person',
                    'hybrid' => $locale === 'fr' ? 'Hybride' : 'Hybrid',
                    default => $format,
                },
            ])
            ->values();

        return response()->json([
            'data' => $paginator->items(),

            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],

            'filters' => [
                'languages' => $languages,
                'levels' => $levels,
                'formats' => $formats,
            ],
        ]);
    }
}
