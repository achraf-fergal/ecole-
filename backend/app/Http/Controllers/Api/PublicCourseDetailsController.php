<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Cohort;
use App\Models\CohortSchedule;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCourseDetailsController extends Controller
{
    public function __invoke(
        Request $request,
        Center $center,
        Course $course
    ): JsonResponse {

        // 1. Only active centers can expose public content.
        abort_unless($center->isActive(), 404);

        // 2. Defense in depth for tenant isolation.
        abort_unless(
            (int) $course->center_id === (int) $center->id,
            404
        );

        // 3. Only published courses are publicly accessible.
        abort_unless($course->isPublished(), 404);

        // 4. Validate the website locale.
        $validated = $request->validate([
            'locale' => [
                'sometimes',
                'string',
                'max:35',
                'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/',
            ],
        ]);

        $requestedLocale = $validated['locale']
            ?? ($center->default_locale ?: $course->source_locale);

        $sourceLocale = $course->source_locale;

        $isSourceLocale = strcasecmp(
            $requestedLocale,
            $sourceLocale
        ) === 0;

        // 5. Select the source content or a published translation.
        $translation = null;

        if (! $isSourceLocale) {
            $translation = $course->translations()
                ->published()
                ->where('locale', $requestedLocale)
                ->first();

            // Never expose draft or unavailable translations.
            if (! $translation) {
                return response()->json([
                    'error' => [
                        'code' => 'translation_unavailable',
                        'message' => 'This course is not available in the requested language.',
                        'requested_locale' => $requestedLocale,
                        'source_locale' => $sourceLocale,
                        'available_locales' => $this->availableLocales($course),
                    ],
                ], 404);
            }
        }

        $content = $translation ?? $course;

        $contentLocale = $isSourceLocale
            ? $sourceLocale
            : $translation->locale;

        // 6. Load shared course relationships.
        $course->load([
            'language',
            'level',
        ]);

        // Public courses require active language and level.
        abort_unless(
            $course->language !== null
            && $course->language->is_active
            && $course->level !== null
            && $course->level->is_active,
            404
        );

        // 7. Load only published outcomes in the selected locale.
        $outcomes = $course->outcomes()
            ->published()
            ->where('center_id', $center->id)
            ->where('locale', $contentLocale)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'content',
                'is_highlight',
            ]);

        // 8. Validate pricing before public exposure.
        $allowedPricingModes = [
            Course::PRICING_FIXED,
            Course::PRICING_FROM,
            Course::PRICING_UPON_REQUEST,
            Course::PRICING_FREE,
        ];

        $pricingMode = in_array(
            $course->pricing_mode,
            $allowedPricingModes,
            true
        )
            ? $course->pricing_mode
            : Course::PRICING_UPON_REQUEST;

        $amount = null;

        if (in_array($pricingMode, [
            Course::PRICING_FIXED,
            Course::PRICING_FROM,
        ], true)) {
            if (
                $course->price !== null
                && (float) $course->price > 0
            ) {
                $amount = $course->price;
            } else {
                // Fail safely if the stored pricing configuration is invalid.
                $pricingMode = Course::PRICING_UPON_REQUEST;
            }
        }

        // 9. Validate the cover image URL.
        $coverImageUrl = $this->validPublicImageUrl(
            $course->cover_image_url
        );

        $availableLocales = $this->availableLocales($course);
        // Build a safe public curriculum preview.
        $curriculum = $this->publicCurriculum(
            $course,
            $contentLocale
        );

        // Return only groups that are ready for public presentation.
        $availableGroups = $this->availableGroups(
            $center,
            $course
        );

        // 10. Return only public course information.
        return response()->json([
            'data' => [
                'id' => (string) $course->id,

                'slug' => $course->slug,

                'localization' => [
                    'requested_locale' => $requestedLocale,
                    'content_locale' => $contentLocale,
                    'is_translated' => $translation !== null,
                    'available_locales' => $availableLocales,
                ],

                'title' => $content->title,

                'short_description' => $content->short_description,

                'description' => $content->description,

                'prerequisites' => $content->prerequisites,

                'target_audience' => $content->target_audience,

                'language' => [
                    'code' => $course->language->code,
                    'name' => $course->language->name,
                ],

                'level' => [
                    'code' => $course->level->code,
                    'name' => $course->level->name,
                ],

                'study_format' => $course->study_format,

                'duration_hours' => $course->duration_hours,

                'cover_image' => [
                    'url' => $coverImageUrl,
                    'alt' => $content->cover_image_alt
                        ?: $course->cover_image_alt
                        ?: $content->title,
                ],

                'pricing' => [
                    'mode' => $pricingMode,
                    'amount' => $amount,
                    'currency' => $course->currency,
                ],

                'outcomes' => $outcomes->map(
                    fn ($outcome) => [
                        'content' => $outcome->content,
                        'is_highlight' => $outcome->is_highlight,
                    ]
                )->values(),

                'curriculum' => $curriculum,

                'available_groups' => $availableGroups,

                'seo' => [
                    'title' => $content->meta_title
                        ?: $content->title,

                    'description' => $content->meta_description
                        ?: $content->short_description,
                ],
            ],
        ]);
    }

    /**
     * Build a public curriculum preview without private lesson content.
     *
     * @return array{is_public: bool, modules: array<int, array<string, mixed>>}
     */
    private function publicCurriculum(
        Course $course,
        string $contentLocale
    ): array {
        if (
            ! $course->is_curriculum_public
            || strcasecmp(
                $contentLocale,
                $course->source_locale
            ) !== 0
        ) {
            return [
                'is_public' => false,
                'modules' => [],
            ];
        }

        $publishedLessons = static function (
            $query
        ) use ($course, $contentLocale): void {
            $query
                ->published()
                ->where('center_id', $course->center_id)
                ->where('content_locale', $contentLocale);
        };

        $modules = $course->modules()
            ->where('center_id', $course->center_id)
            ->published()
            ->whereHas('lessons', $publishedLessons)
            ->with([
                'lessons' => static function ($query) use ($course,
                    $contentLocale
                ): void {
                    $query
                        ->published()
                        ->where('center_id', $course->center_id)
                        ->where('content_locale', $contentLocale)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->select([
                            'id',
                            'center_id',
                            'course_module_id',
                            'title',
                            'content_type',
                            'duration_minutes',
                            'sort_order',
                        ]);
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'center_id',
                'course_id',
                'title',
                'description',
                'sort_order',
            ]);

        return [
            'is_public' => true,
            'modules' => $modules->map(
                static function (CourseModule $module): array {
                    return [
                        'id' => (string) $module->id,
                        'title' => $module->title,
                        'description' => $module->description,
                        'lessons' => $module->lessons
                            ->map(
                                static function (Lesson $lesson): array {
                                    return [
                                        'id' => (string) $lesson->id,
                                        'title' => $lesson->title,
                                        'content_type' => $lesson->content_type,
                                        'duration_minutes' => $lesson->duration_minutes,
                                    ];
                                }
                            )
                            ->values()
                            ->all(),
                    ];
                }
            )
                ->values()
                ->all(),
        ];
    }

    /**
     * Return only open groups ready for public presentation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableGroups(
        Center $center,
        Course $course
    ): array {
        $validSchedules = static function (
            $query
        ): void {
            $query
                ->whereBetween('day_of_week', [1, 7])
                ->whereColumn('end_time', '>', 'start_time')
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->orderBy('id');
        };

        $groups = $course->cohorts()
            ->where('center_id', $center->id)
            ->where('status', Cohort::STATUS_OPEN)
            ->whereNotNull('teacher_user_id')
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->whereColumn('ends_at', '>=', 'starts_at')
            ->where('ends_at', '>=', now())
            ->where('capacity', '>', 0)
            ->whereHas('teacher', function (Builder $teacherQuery) use (
                $center
            ): void {
                $teacherQuery->whereHas(
                    'centerMemberships',
                    function (Builder $membershipQuery) use (
                        $center
                    ): void {
                        $membershipQuery
                            ->where('center_id', $center->id)
                            ->where('status', 'active')
                            ->whereHas(
                                'roles',
                                static fn (Builder $roleQuery): Builder => $roleQuery->where('key', 'teacher')
                            );
                    }
                );
            })
            ->whereHas('schedules', $validSchedules)
            ->with([
                'schedules' => $validSchedules,
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get([
                'id',
                'center_id',
                'course_id',
                'name',
                'starts_at',
                'ends_at',
                'capacity',
                'location_label',
                'status',
            ]);

        return $groups->map(
            static function (Cohort $cohort): array {
                return [
                    'id' => (string) $cohort->id,
                    'name' => $cohort->name,
                    'start_date' => $cohort->starts_at?->toIso8601String(),
                    'end_date' => $cohort->ends_at?->toIso8601String(),
                    'location_label' => $cohort->location_label,
                    'room_label' => null,
                    'enrollment_status' => 'open',
                    'schedules' => $cohort->schedules
                        ->map(
                            static function (
                                CohortSchedule $schedule
                            ): array {
                                return [
                                    'day_of_week' => CohortSchedule::DAYS_OF_WEEK[
                                            $schedule->day_of_week
                                        ] ?? null,
                                    'start_time' => substr(
                                        (string) $schedule->start_time,
                                        0,
                                        5
                                    ),
                                    'end_time' => substr(
                                        (string) $schedule->end_time,
                                        0,
                                        5
                                    ),
                                    'location_label' => $schedule->location_label,
                                ];
                            }
                        )
                        ->values()
                        ->all(),
                ];
            }
        )
            ->values()
            ->all();
    }

    /**
     * Source locale and published additional locales.
     *
     * @return array<int, string>
     */
    private function availableLocales(Course $course): array
    {
        $translations = $course->translations()
            ->published()
            ->orderBy('locale')
            ->pluck('locale')
            ->all();

        return array_values(array_unique(array_filter([
            $course->source_locale,
            ...$translations,
        ])));

    }

    /**
     * Only HTTP and HTTPS image URLs are exposed.
     */
    private function validPublicImageUrl(?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }
}
