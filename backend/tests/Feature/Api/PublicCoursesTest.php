<?php

namespace Tests\\Feature\\Api;

use App\\Models\\Center;
use App\\Models\\Course;
use App\\Models\\Language;
use App\\Models\\Level;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class PublicCoursesTest extends TestCase
{
    use RefreshDatabase;

    private function createPublishedCourse(Center $center, array $attributes = []): Course
    {
        $language = Language::factory()->for($center)->create();
        $level = Level::factory()->for($center)->create();

        return Course::factory()
            ->forCenter($center)
            ->withLanguage($language)
            ->withLevel($level)
            ->published()
            ->create($attributes);
    }

    public function test_public_endpoint_returns_only_published_courses_of_requested_center(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();

        $visible = $this->createPublishedCourse($centerA, [
            'title' => 'English A1',
            'slug' => 'english-a1',
        ]);

        $this->createPublishedCourse($centerA, [
            'status' => 'draft',
            'published_at' => null,
            'slug' => 'draft-course',
        ]);

        $this->createPublishedCourse($centerB, [
            'slug' => 'other-center-course',
        ]);

        $response = $this->getJson("/api/public/centers/{$centerA->slug}/courses");

        $response->assertOk()
            ->assertJsonPath('data.0.id', (string) $visible->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'slug', 'title', 'short_description',
                    'study_format', 'duration_label', 'cover_image_url',
                    'cover_image_alt', 'language' => ['value', 'label'],
                    'level' => ['value', 'label'],
                ]],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
                'filters' => ['languages', 'levels', 'formats'],
            ]);
    }

    public function test_public_courses_can_be_filtered_by_language_level_and_format(): void
    {
        $center = Center::factory()->create();

        $english = Language::factory()->for($center)->create([
            'code' => 'en',
            'name' => 'English',
        ]);
        $french = Language::factory()->for($center)->french()->create();

        $beginner = Level::factory()->for($center)->create([
            'code' => 'A1',
            'name' => 'Beginner',
        ]);
        $advanced = Level::factory()->for($center)->create([
            'code' => 'C1',
            'name' => 'Advanced',
        ]);

        Course::factory()->forCenter($center)
            ->withLanguage($english)->withLevel($beginner)->published()
            ->create(['slug' => 'english-online', 'study_format' => 'online']);

        Course::factory()->forCenter($center)
            ->withLanguage($french)->withLevel($advanced)->published()
            ->create(['slug' => 'french-onsite', 'study_format' => 'in_person']);

        $this->getJson("/api/public/centers/{$center->slug}/courses?language=en&level=A1&format=online")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'english-online');
    }

    public function test_public_courses_support_search_and_pagination(): void
    {
        $center = Center::factory()->create();

        for ($i = 1; $i <= 14; $i++) {
            $language = Language::factory()->for($center)->create([
                'code' => 'lang'.$i,
                'name' => 'Language '.$i,
            ]);
            $level = Level::factory()->for($center)->create([
                'code' => 'L'.$i,
                'name' => 'Level '.$i,
            ]);

            Course::factory()->forCenter($center)
                ->withLanguage($language)
                ->withLevel($level)
                ->published()
                ->create([
                    'title' => 'Course '.$i,
                    'slug' => 'course-'.$i,
                    'short_description' => $i === 14 ? 'Special conversation class' : 'General course',
                ]);
        }

        $firstPage = $this->getJson("/api/public/centers/{$center->slug}/courses?per_page=12&page=1");
        $firstPage->assertOk()
            ->assertJsonPath('meta.total', 14)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(12, 'data');

        $secondPage = $this->getJson("/api/public/centers/{$center->slug}/courses?per_page=12&page=2");
        $secondPage->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/public/centers/{$center->slug}/courses?search=Special")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_inactive_center_is_not_publicly_accessible(): void
    {
        $center = Center::factory()->inactive()->create();

        $this->getJson("/api/public/centers/{$center->slug}/courses")
            ->assertNotFound();
    }

    public function test_invalid_query_parameters_are_rejected(): void
    {
        $center = Center::factory()->create();

        $this->getJson("/api/public/centers/{$center->slug}/courses?format=invalid")
            ->assertUnprocessable();
    }
}
