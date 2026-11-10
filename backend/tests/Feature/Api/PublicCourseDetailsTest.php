<?php

namespace Tests\Feature\Api;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseOutcome;
use App\Models\CourseTranslation;
use App\Models\Language;
use App\Models\Level;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCourseDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function createPublishedCourse(
        Center $center,
        array $attributes = []
    ): Course {
        $language = Language::factory()
            ->for($center)
            ->create();

        $level = Level::factory()
            ->for($center)
            ->create();

        return Course::factory()->create(array_merge([
            'center_id' => $center->id,
            'language_id' => $language->id,
            'level_id' => $level->id,

            'source_locale' => 'en',

            'title' => 'General English A1',
            'slug' => 'general-english-a1',

            'status' => 'published',
            'published_at' => now(),

            'pricing_mode' => 'fixed',
            'price' => '1200.00',
            'currency' => 'MAD',
        ], $attributes));
    }

    public function test_source_course_details_are_returned(): void
    {
        $center = Center::factory()->create([
            'default_locale' => 'en',
        ]);

        $course = $this->createPublishedCourse($center);

        CourseOutcome::factory()
            ->forCourse($course)
            ->inLocale('en')
            ->published()
            ->create([
                'content' => 'Understand everyday conversations.',
                'sort_order' => 1,
                'is_highlight' => true,
            ]);

        // Draft outcomes must not be exposed.
        CourseOutcome::factory()
            ->forCourse($course)
            ->inLocale('en')
            ->create([
                'content' => 'Unpublished learning outcome.',
                'status' => 'draft',
            ]);

        $response = $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}?locale=en"
        );

        $response->assertOk()
            ->assertJsonPath('data.title', 'General English A1')
            ->assertJsonPath('data.localization.content_locale', 'en')
            ->assertJsonPath('data.localization.is_translated', false)
            ->assertJsonPath('data.pricing.mode', 'fixed')
            ->assertJsonPath('data.pricing.amount', '1200.00')
            ->assertJsonPath('data.pricing.currency', 'MAD')
            ->assertJsonPath(
                'data.outcomes.0.content',
                'Understand everyday conversations.'
            )
            ->assertJsonCount(1, 'data.outcomes')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'slug',
                    'localization',
                    'title',
                    'short_description',
                    'description',
                    'prerequisites',
                    'target_audience',
                    'language',
                    'level',
                    'study_format',
                    'duration_hours',
                    'cover_image',
                    'pricing',
                    'outcomes',
                    'curriculum',
                    'available_groups',
                    'seo',
                ],
            ]);
    }

    public function test_published_translation_is_returned(): void
    {
        $center = Center::factory()->create([
            'default_locale' => 'en',
        ]);

        $course = $this->createPublishedCourse($center);

        CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->published()
            ->create([
                'title' => 'Anglais général A1',
                'short_description' => 'Apprenez les bases de l’anglais.',
                'description' => 'Description complète en français.',
                'meta_title' => 'Anglais général A1 - Centre',
            ]);

        CourseOutcome::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->published()
            ->create([
                'content' => 'Comprendre les conversations quotidiennes.',
                'sort_order' => 1,
            ]);

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}?locale=fr"
        )
            ->assertOk()
            ->assertJsonPath('data.title', 'Anglais général A1')
            ->assertJsonPath('data.localization.is_translated', true)
            ->assertJsonPath('data.localization.content_locale', 'fr')
            ->assertJsonPath('data.seo.title', 'Anglais général A1 - Centre')
            ->assertJsonPath(
                'data.outcomes.0.content',
                'Comprendre les conversations quotidiennes.'
            );
    }

    public function test_draft_translation_is_not_public(): void
    {
        $center = Center::factory()->create();

        $course = $this->createPublishedCourse($center);

        CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->create([
                'status' => 'draft',
                'published_at' => null,
            ]);

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}?locale=fr"
        )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'translation_unavailable'
            )
            ->assertJsonPath(
                'error.source_locale',
                'en'
            );
    }

    public function test_future_translation_is_not_public(): void
    {
        $center = Center::factory()->create();

        $course = $this->createPublishedCourse($center);

        CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->published()
            ->create([
                'published_at' => now()->addDay(),
            ]);

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}?locale=fr"
        )->assertNotFound();
    }

    public function test_unpublished_course_is_not_public(): void
    {
        $center = Center::factory()->create();

        $course = $this->createPublishedCourse($center, [
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}"
        )->assertNotFound();
    }

    public function test_inactive_center_cannot_expose_courses(): void
    {
        $center = Center::factory()
            ->inactive()
            ->create();

        $course = $this->createPublishedCourse($center);

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}"
        )->assertNotFound();
    }

    public function test_course_from_another_center_cannot_be_resolved(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();

        $courseB = $this->createPublishedCourse(
            $centerB,
            ['slug' => 'shared-course-slug']
        );

        $this->getJson(
            "/api/public/centers/{$centerA->slug}/courses/{$courseB->slug}"
        )->assertNotFound();
    }

    public function test_center_default_locale_is_used(): void
    {
        $center = Center::factory()->create([
            'default_locale' => 'fr',
        ]);

        $course = $this->createPublishedCourse($center, [
            'source_locale' => 'en',
        ]);

        CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->published()
            ->create([
                'title' => 'Anglais général A1',
            ]);

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}"
        )
            ->assertOk()
            ->assertJsonPath('data.title', 'Anglais général A1')
            ->assertJsonPath('data.localization.requested_locale', 'fr');
    }

    public function test_invalid_locale_format_is_rejected(): void
    {
        $center = Center::factory()->create();

        $course = $this->createPublishedCourse($center);

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}?locale=fr_MA"
        )->assertUnprocessable();
    }

    public function test_course_without_public_price_returns_upon_request(): void
    {
        $center = Center::factory()->create();

        $course = $this->createPublishedCourse($center, [
            'price' => null,
            'pricing_mode' => 'upon_request',
        ]);

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}"
        )
            ->assertOk()
            ->assertJsonPath('data.pricing.mode', 'upon_request')
            ->assertJsonPath('data.pricing.amount', null);
    }
}
