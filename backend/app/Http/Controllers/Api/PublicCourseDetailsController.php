<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
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

        if (!$isSourceLocale) {
            $translation = $course->translations()
                ->published()
                ->where('locale', $requestedLocale)
                ->first();

            // Never expose draft or unavailable translations.
            if (!$translation) {
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

                'short_description' =>
                    $content->short_description,

                'description' => $content->description,

                'prerequisites' => $content->prerequisites,

                'target_audience' =>
                    $content->target_audience,

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

                // Module and Lesson tables are not implemented yet.
                'curriculum' => [
                    'is_public' => $course->is_curriculum_public,
                    'modules' => [],
                ],

                // Cohort tables are not implemented yet.
                'available_groups' => [],

                'seo' => [
                    'title' => $content->meta_title
                        ?: $content->title,

                    'description' =>
                        $content->meta_description
                        ?: $content->short_description,
                ],
            ],
        ]);
    }

    /**
     * Source locale and published additional locales.
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
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }
}
