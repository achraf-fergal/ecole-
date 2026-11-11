<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LessonDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a module belonging to a valid course and center.
     */
    private function createModule(Center $center): CourseModule
    {
        $course = Course::factory()
            ->forCenter($center)
            ->create();

        return CourseModule::factory()
            ->forCourse($course)
            ->create();
    }

    public function test_lessons_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('lessons', [
            'id',
            'center_id',
            'course_module_id',
            'title',
            'description',
            'content_type',
            'body',
            'content_locale',
            'duration_minutes',
            'sort_order',
            'status',
            'published_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_lesson_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $module = $this->createModule($center);

        $lesson = Lesson::factory()
            ->forModule($module)
            ->create();

        $this->assertSame(
            $module->id,
            $lesson->courseModule->id
        );

        $this->assertSame(
            $center->id,
            $lesson->center->id
        );

        $this->assertCount(
            1,
            $module->fresh()->lessons
        );

        $this->assertCount(
            1,
            $center->fresh()->lessons
        );
    }

    public function test_module_can_have_multiple_ordered_lessons(): void
    {
        $center = Center::factory()->create();

        $module = $this->createModule($center);

        Lesson::factory()
            ->forModule($module)
            ->create([
                'title' => 'Third lesson',
                'sort_order' => 3,
            ]);

        Lesson::factory()
            ->forModule($module)
            ->create([
                'title' => 'First lesson',
                'sort_order' => 1,
            ]);

        Lesson::factory()
            ->forModule($module)
            ->create([
                'title' => 'Second lesson',
                'sort_order' => 2,
            ]);

        $orderedTitles = $module->fresh()
            ->lessons()
            ->orderBy('sort_order')
            ->pluck('title')
            ->all();

        $this->assertSame([
            'First lesson',
            'Second lesson',
            'Third lesson',
        ], $orderedTitles);
    }

    public function test_lesson_cannot_reference_module_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $module = $this->createModule($centerA);

        $this->expectException(QueryException::class);

        Lesson::factory()
            ->forModule($module)
            ->create([
                'center_id' => $centerB->id,
            ]);
    }

    public function test_published_scope_excludes_draft_and_future_lessons(): void
    {
        $center = Center::factory()->create();

        $module = $this->createModule($center);

        $published = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create();

        $draft = Lesson::factory()
            ->forModule($module)
            ->create();

        $future = Lesson::factory()
            ->forModule($module)
            ->published()
            ->create([
                'published_at' => now()->addDay(),
            ]);

        $publishedIds = Lesson::query()
            ->published()
            ->pluck('id')
            ->all();

        $this->assertContains($published->id, $publishedIds);

        $this->assertNotContains($draft->id, $publishedIds);

        $this->assertNotContains($future->id, $publishedIds);
    }

    public function test_archiving_lesson_preserves_its_record(): void
    {
        $center = Center::factory()->create();

        $module = $this->createModule($center);

        $lesson = Lesson::factory()
            ->forModule($module)
            ->archived()
            ->create();

        $this->assertTrue(
            $lesson->isArchived()
        );

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'status' => Lesson::STATUS_ARCHIVED,
        ]);
    }
}