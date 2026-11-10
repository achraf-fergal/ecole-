<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseOutcome;
use App\Models\CourseTranslation;
use App\Models\Language;
use App\Models\Level;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseDetailsDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a course with valid tenant-owned relationships.
     */
    private function createCourse(
        Center $center,
        string $locale = 'en'
    ): Course {
        $language = $center->languages()->first()
            ?? Language::factory()
                ->for($center)
                ->create();

        $level = $center->levels()->first()
            ?? Level::factory()
                ->for($center)
                ->create();

        return Course::factory()->create([
            'center_id' => $center->id,
            'language_id' => $language->id,
            'level_id' => $level->id,
            'source_locale' => $locale,
        ]);
    }

    public function test_course_details_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('courses', [
            'source_locale',
            'target_audience',
            'pricing_mode',
            'is_curriculum_public',
            'meta_title',
            'meta_description',
            'cover_image_alt',
        ]));

        $this->assertTrue(
            Schema::hasColumn('centers', 'default_locale')
        );

        $this->assertTrue(
            Schema::hasTable('course_translations')
        );

        $this->assertTrue(
            Schema::hasTable('course_outcomes')
        );
    }

    public function test_course_uses_center_default_locale_when_created(): void
    {
        $center = Center::factory()->create([
            'default_locale' => 'fr-MA',
        ]);

        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $this->assertSame(
            'fr-MA',
            $course->source_locale
        );
    }

    public function test_changing_center_locale_does_not_change_existing_course_source_locale(): void
    {
        $center = Center::factory()->create([
            'default_locale' => 'en',
        ]);

        $course = $this->createCourse($center, 'en');

        $center->update([
            'default_locale' => 'fr',
        ]);

        $this->assertSame(
            'en',
            $course->fresh()->source_locale
        );
    }

    public function test_course_has_an_additional_translation(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $translation = CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->published()
            ->create([
                'title' => 'Anglais général A1',
            ]);

        $this->assertSame(
            $course->id,
            $translation->course->id
        );

        $this->assertSame(
            'fr',
            $translation->locale
        );

        $this->assertTrue(
            $translation->isPublished()
        );

        $this->assertCount(
            1,
            $course->fresh()->translations
        );
    }

    public function test_same_course_cannot_have_duplicate_translation_for_same_locale(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->create();

        $this->expectException(QueryException::class);

        CourseTranslation::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->create();
    }

    public function test_same_locale_can_be_used_by_different_courses(): void
    {
        $center = Center::factory()->create();

        $courseA = $this->createCourse($center);

        $courseB = $this->createCourse($center);

        CourseTranslation::factory()
            ->forCourse($courseA)
            ->inLocale('fr')
            ->create();

        CourseTranslation::factory()
            ->forCourse($courseB)
            ->inLocale('fr')
            ->create();

        $this->assertDatabaseCount(
            'course_translations',
            2
        );
    }

    public function test_translation_cannot_reference_course_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $courseA = $this->createCourse($centerA);

        $this->expectException(QueryException::class);

        CourseTranslation::factory()
            ->forCourse($courseA)
            ->create([
                'center_id' => $centerB->id,
            ]);
    }

    public function test_course_can_have_outcomes_in_multiple_locales(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        CourseOutcome::factory()
            ->forCourse($course)
            ->inLocale('en')
            ->published()
            ->create([
                'content' => 'Understand everyday conversations.',
                'sort_order' => 1,
                'is_highlight' => true,
            ]);

        CourseOutcome::factory()
            ->forCourse($course)
            ->inLocale('fr')
            ->published()
            ->create([
                'content' => 'Comprendre les conversations quotidiennes.',
                'sort_order' => 1,
            ]);

        $this->assertCount(
            2,
            $course->fresh()->outcomes
        );

        $this->assertDatabaseHas('course_outcomes', [
            'course_id' => $course->id,
            'locale' => 'en',
        ]);

        $this->assertDatabaseHas('course_outcomes', [
            'course_id' => $course->id,
            'locale' => 'fr',
        ]);
    }

    public function test_course_outcome_cannot_reference_course_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $courseA = $this->createCourse($centerA);

        $this->expectException(QueryException::class);

        CourseOutcome::factory()
            ->forCourse($courseA)
            ->create([
                'center_id' => $centerB->id,
            ]);
    }

    public function test_new_course_defaults_to_upon_request_pricing(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $this->assertSame(
            'upon_request',
            $course->pricing_mode
        );

        $this->assertNull($course->price);
    }
}
