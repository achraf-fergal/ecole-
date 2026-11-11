<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseModuleDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a valid course owned by the given center.
     */
    private function createCourse(Center $center): Course
    {
        return Course::factory()
            ->forCenter($center)
            ->create();
    }

    public function test_course_modules_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('course_modules', [
                'id',
                'center_id',
                'course_id',
                'title',
                'description',
                'sort_order',
                'status',
                'published_at',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_course_module_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create([
                'title' => 'Introduction',
            ]);

        $this->assertTrue(
            $module->course->is($course)
        );

        $this->assertTrue(
            $module->center->is($center)
        );

        $this->assertTrue(
            $course->modules()->whereKey($module->id)->exists()
        );

        $this->assertTrue(
            $center->courseModules()->whereKey($module->id)->exists()
        );

        $this->assertSame(
            $center->id,
            $module->center_id
        );

        $this->assertSame(
            $course->id,
            $module->course_id
        );
    }

    public function test_course_can_have_multiple_ordered_modules(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        CourseModule::factory()
            ->forCourse($course)
            ->create([
                'title' => 'Grammar',
                'sort_order' => 2,
            ]);

        CourseModule::factory()
            ->forCourse($course)
            ->create([
                'title' => 'Introduction',
                'sort_order' => 1,
            ]);

        CourseModule::factory()
            ->forCourse($course)
            ->create([
                'title' => 'Vocabulary',
                'sort_order' => 3,
            ]);

        $modules = $course->modules;

        $this->assertCount(3, $modules);

        $this->assertSame(
            [
                'Introduction',
                'Grammar',
                'Vocabulary',
            ],
            $modules->pluck('title')->all()
        );
    }

    public function test_module_cannot_reference_course_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $courseB = $this->createCourse($centerB);

        $this->expectException(QueryException::class);

        CourseModule::factory()
            ->forCourse($courseB)
            ->create([
                'center_id' => $centerA->id,
            ]);
    }

    public function test_published_scope_excludes_draft_and_future_modules(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $published = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create([
                'title' => 'Published Module',
            ]);

        CourseModule::factory()
            ->forCourse($course)
            ->create([
                'title' => 'Draft Module',
                'status' => CourseModule::STATUS_DRAFT,
                'published_at' => null,
            ]);

        CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create([
                'title' => 'Future Module',
                'published_at' => now()->addDay(),
            ]);

        $result = CourseModule::query()
            ->forCenter($center->id)
            ->published()
            ->get();

        $this->assertCount(1, $result);

        $this->assertSame(
            $published->id,
            $result->first()->id
        );

        $this->assertTrue($published->fresh()->isPublished());
    }

    public function test_archiving_module_preserves_its_record(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $module = CourseModule::factory()
            ->forCourse($course)
            ->published()
            ->create();

        $module->update([
            'status' => CourseModule::STATUS_ARCHIVED,
        ]);

        $this->assertDatabaseHas('course_modules', [
            'id' => $module->id,
            'center_id' => $center->id,
            'course_id' => $course->id,
            'status' => CourseModule::STATUS_ARCHIVED,
        ]);

        $this->assertTrue(
            $module->fresh()->isArchived()
        );

        $this->assertFalse(
            $module->fresh()->isPublished()
        );
    }
}
