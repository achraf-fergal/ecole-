<?php

namespace Tests\Feature\Api;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseTranslation;
use App\Models\Language;
use App\Models\Level;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCourseListingLocalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_returns_source_and_published_translation_locales(): void
    {
        $center = Center::factory()->create([
            'default_locale' => 'en',
        ]);

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = Level::factory()
            ->for($center)
            ->create();

        $course = Course::factory()->create([
            'center_id' => $center->id,
            'language_id' => $language->id,
            'level_id' => $level->id,
            'source_locale' => 'en',
            'title' => 'General English A1',
            'slug' => 'general-english-a1',
            'status' => 'published',
            'published_at' => now(),
        ]);

        CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->published()
            ->create([
                'title' => 'Anglais général A1',
            ]);

        CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('de')
            ->create([
                'status' => 'draft',
                'published_at' => null,
            ]);

        $url = route(
            'api.public.centers.courses.index',
            ['center' => $center->slug]
        );

        $response = $this->getJson($url.'?locale=en');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.available_locales',
                ['en', 'fr']
            );
    }
}
