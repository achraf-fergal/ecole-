<?php

namespace Tests\Feature\Api;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\CohortSchedule;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseOutcome;
use App\Models\CourseTranslation;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
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

    private function createOpenCohort(
        Course $course,
        CenterUser $teacherMembership,
        array $attributes = []
    ): Cohort {
        return Cohort::factory()
            ->forCourse($course)
            ->taughtBy($teacherMembership)
            ->openForEnrollment()
            ->create(array_merge([
                'starts_at' => now()->addDays(14),
                'ends_at' => now()->addMonths(3),
                'capacity' => 15,
            ], $attributes));
    }

    private function createCohortSchedule(
        Cohort $cohort,
        array $attributes = []
    ): CohortSchedule {
        return CohortSchedule::factory()
            ->forCohort($cohort)
            ->create($attributes);
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

    public function test_public_curriculum_returns_only_published_content(): void
    {
        $center = Center::factory()->create();

        $course = $this->createPublishedCourse($center, [
            'is_curriculum_public' => true,
        ]);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create([
                'title' => 'English Foundations',
                'sort_order' => 1,
            ]);

        Lesson::factory()
            ->forModule($module)
            ->published()
            ->create([
                'title' => 'Daily Conversations',
                'content_type' => 'video',
                'content_locale' => 'en',
                'duration_minutes' => 20,
                'body' => 'PRIVATE LESSON BODY',
            ]);

        Lesson::factory()
            ->forModule($module)
            ->published()
            ->create([
                'title' => 'Conversation quotidienne',
                'content_locale' => 'fr',
            ]);

        Lesson::factory()
            ->forModule($module)
            ->create([
                'title' => 'Draft Lesson',
                'status' => 'draft',
                'published_at' => null,
            ]);

        Lesson::factory()
            ->forModule($module)
            ->create([
                'title' => 'Future Lesson',
                'status' => 'published',
                'published_at' => now()->addDay(),
            ]);

        $draftModule = CourseModule::factory()
            ->forCourse($course)
            ->create([
                'title' => 'Draft Module',
                'status' => 'draft',
                'sort_order' => 2,
            ]);

        Lesson::factory()
            ->forModule($draftModule)
            ->published()
            ->create();

        $futureModule = CourseModule::factory()
            ->forCourse($course)
            ->create([
                'title' => 'Future Module',
                'status' => 'published',
                'published_at' => now()->addDay(),
                'sort_order' => 3,
            ]);

        Lesson::factory()
            ->forModule($futureModule)
            ->published()
            ->create();

        $response = $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}?locale=en"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.curriculum.is_public', true)
            ->assertJsonCount(1, 'data.curriculum.modules')
            ->assertJsonPath(
                'data.curriculum.modules.0.title',
                'English Foundations'
            )
            ->assertJsonCount(
                1,
                'data.curriculum.modules.0.lessons'
            )
            ->assertJsonPath(
                'data.curriculum.modules.0.lessons.0.title',
                'Daily Conversations'
            );

        $preview = $response->json(
            'data.curriculum.modules.0.lessons.0'
        );

        $this->assertArrayNotHasKey('body', $preview);
        $this->assertArrayNotHasKey('assets', $preview);
        $this->assertArrayNotHasKey('exercises', $preview);
    }

    public function test_private_curriculum_is_not_exposed(): void
    {
        $center = Center::factory()->create();

        $course = $this->createPublishedCourse($center, [
            'is_curriculum_public' => false,
        ]);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create();

        Lesson::factory()
            ->forModule($module)
            ->published()
            ->create();

        $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}"
        )
            ->assertOk()
            ->assertJsonPath('data.curriculum.is_public', false)
            ->assertJsonCount(0, 'data.curriculum.modules');
    }

    public function test_only_ready_open_groups_are_exposed(): void
    {
        $this->seed(RoleSeeder::class);

        $center = Center::factory()->create();

        $course = $this->createPublishedCourse($center);

        $teacherRole = Role::query()
            ->where('key', 'teacher')
            ->firstOrFail();

        $teacherMembership = CenterUser::factory()
            ->for($center)
            ->create([
                'status' => 'active',
            ]);

        $teacherMembership->roles()->attach($teacherRole->id);

        $availableGroup = $this->createOpenCohort(
            $course,
            $teacherMembership,
            [
                'name' => 'Available group',
                'location_label' => 'Main Campus',
            ]
        );

        $this->createCohortSchedule($availableGroup, [
            'day_of_week' => CohortSchedule::MONDAY,
            'start_time' => '18:00:00',
            'end_time' => '20:00:00',
            'location_label' => 'Room 2',
        ]);

        $missingSchedule = $this->createOpenCohort(
            $course,
            $teacherMembership,
            ['name' => 'Missing schedule']
        );

        $invalidSchedule = $this->createOpenCohort(
            $course,
            $teacherMembership,
            ['name' => 'Invalid schedule']
        );

        $this->createCohortSchedule($invalidSchedule, [
            'start_time' => '18:00:00',
            'end_time' => '17:00:00',
        ]);

        $zeroCapacity = $this->createOpenCohort(
            $course,
            $teacherMembership,
            [
                'name' => 'Zero capacity',
                'capacity' => 0,
            ]
        );

        $this->createCohortSchedule($zeroCapacity);

        $expiredGroup = $this->createOpenCohort(
            $course,
            $teacherMembership,
            [
                'name' => 'Expired group',
                'starts_at' => now()->subMonths(4),
                'ends_at' => now()->subMonth(),
            ]
        );

        $this->createCohortSchedule($expiredGroup);

        $invalidDates = $this->createOpenCohort(
            $course,
            $teacherMembership,
            [
                'name' => 'Invalid dates',
                'starts_at' => now()->addDays(30),
                'ends_at' => now()->addDays(20),
            ]
        );

        $this->createCohortSchedule($invalidDates);

        $missingDates = $this->createOpenCohort(
            $course,
            $teacherMembership,
            [
                'name' => 'Missing dates',
                'starts_at' => null,
                'ends_at' => null,
            ]
        );

        $this->createCohortSchedule($missingDates);

        $draftGroup = Cohort::factory()
            ->forCourse($course)
            ->taughtBy($teacherMembership)
            ->create([
                'name' => 'Draft group',
                'starts_at' => now()->addDays(14),
                'ends_at' => now()->addMonths(3),
            ]);

        $this->createCohortSchedule($draftGroup);

        $noTeacherGroup = Cohort::factory()
            ->forCourse($course)
            ->openForEnrollment()
            ->create([
                'name' => 'Unassigned teacher',
                'starts_at' => now()->addDays(14),
                'ends_at' => now()->addMonths(3),
            ]);

        $this->createCohortSchedule($noTeacherGroup);

        $nonTeacherMembership = CenterUser::factory()
            ->for($center)
            ->create([
                'status' => 'active',
            ]);

        $nonTeacherGroup = $this->createOpenCohort(
            $course,
            $nonTeacherMembership,
            ['name' => 'Membership without teacher role']
        );

        $this->createCohortSchedule($nonTeacherGroup);

        $inactiveTeacherMembership = CenterUser::factory()
            ->for($center)
            ->create([
                'status' => 'suspended',
            ]);

        $inactiveTeacherMembership->roles()->attach($teacherRole->id);

        $inactiveTeacherGroup = $this->createOpenCohort(
            $course,
            $inactiveTeacherMembership,
            ['name' => 'Inactive teacher']
        );

        $this->createCohortSchedule($inactiveTeacherGroup);

        $response = $this->getJson(
            "/api/public/centers/{$center->slug}/courses/{$course->slug}"
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.available_groups')
            ->assertJsonPath(
                'data.available_groups.0.id',
                (string) $availableGroup->id
            )
            ->assertJsonPath(
                'data.available_groups.0.name',
                'Available group'
            )
            ->assertJsonPath(
                'data.available_groups.0.start_date',
                $availableGroup->starts_at->toIso8601String()
            )
            ->assertJsonPath(
                'data.available_groups.0.end_date',
                $availableGroup->ends_at->toIso8601String()
            )
            ->assertJsonPath(
                'data.available_groups.0.enrollment_status',
                'open'
            )
            ->assertJsonPath(
                'data.available_groups.0.room_label',
                null
            )
            ->assertJsonPath(
                'data.available_groups.0.location_label',
                'Main Campus'
            )
            ->assertJsonPath(
                'data.available_groups.0.schedules.0.day_of_week',
                'Monday'
            )
            ->assertJsonPath(
                'data.available_groups.0.schedules.0.start_time',
                '18:00'
            )
            ->assertJsonPath(
                'data.available_groups.0.schedules.0.end_time',
                '20:00'
            )
            ->assertJsonPath(
                'data.available_groups.0.schedules.0.location_label',
                'Room 2'
            );
    }
}
