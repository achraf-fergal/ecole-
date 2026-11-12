<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CohortDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a valid course belonging to a center.
     */
    private function createCourse(Center $center): Course
    {
        return Course::factory()
            ->forCenter($center)
            ->create();
    }

    public function test_cohorts_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('cohorts', [
                'id',
                'center_id',
                'course_id',
                'teacher_user_id',
                'name',
                'code',
                'starts_at',
                'ends_at',
                'capacity',
                'location_label',
                'status',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_cohort_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $membership = CenterUser::factory()
            ->for($center)
            ->create();

        $cohort = Cohort::factory()
            ->forCourse($course)
            ->taughtBy($membership)
            ->create();

        $this->assertTrue(
            $cohort->center->is($center)
        );

        $this->assertTrue(
            $cohort->course->is($course)
        );

        $this->assertTrue(
            $cohort->teacher->is($membership->user)
        );

        $this->assertTrue(
            $center->cohorts()
                ->whereKey($cohort->id)
                ->exists()
        );

        $this->assertTrue(
            $course->cohorts()
                ->whereKey($cohort->id)
                ->exists()
        );

        $this->assertTrue(
            $membership->user->teachingCohorts()
                ->whereKey($cohort->id)
                ->exists()
        );

        $this->assertSame(15, $cohort->capacity);

        $this->assertNotNull($cohort->starts_at);
    }

    public function test_course_can_have_multiple_cohorts_ordered_by_start_date(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        Cohort::factory()
            ->forCourse($course)
            ->create([
                'name' => 'Third Group',
                'starts_at' => now()->addDays(30),
            ]);

        Cohort::factory()
            ->forCourse($course)
            ->create([
                'name' => 'First Group',
                'starts_at' => now()->addDays(5),
            ]);

        Cohort::factory()
            ->forCourse($course)
            ->create([
                'name' => 'Second Group',
                'starts_at' => now()->addDays(15),
            ]);

        $names = $course->fresh()
            ->cohorts
            ->pluck('name')
            ->all();

        $this->assertSame([
            'First Group',
            'Second Group',
            'Third Group',
        ], $names);
    }

    public function test_cohort_cannot_reference_course_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $courseB = $this->createCourse($centerB);

        $this->expectException(QueryException::class);

        Cohort::factory()
            ->forCourse($courseB)
            ->create([
                'center_id' => $centerA->id,
            ]);
    }

    public function test_assigned_teacher_must_be_a_member_of_the_same_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $courseA = $this->createCourse($centerA);

        $teacherMembershipB = CenterUser::factory()
            ->for($centerB)
            ->create();

        // The composite foreign key must reject this assignment.
        $this->expectException(QueryException::class);

        Cohort::factory()
            ->forCourse($courseA)
            ->create([
                'teacher_user_id' => $teacherMembershipB->user_id,
            ]);
    }

    public function test_draft_cohort_can_exist_without_assigned_teacher(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $cohort = Cohort::factory()
            ->forCourse($course)
            ->create([
                'teacher_user_id' => null,
                'status' => Cohort::STATUS_DRAFT,
            ]);

        $this->assertFalse($cohort->hasTeacher());

        $this->assertSame(
            Cohort::STATUS_DRAFT,
            $cohort->status
        );

        $this->assertDatabaseHas('cohorts', [
            'id' => $cohort->id,
            'teacher_user_id' => null,
            'status' => Cohort::STATUS_DRAFT,
        ]);
    }

    public function test_cohort_code_must_be_unique_within_same_center(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        Cohort::factory()
            ->forCourse($course)
            ->create([
                'code' => 'EN-A2-EVENING',
            ]);

        $this->expectException(QueryException::class);

        Cohort::factory()
            ->forCourse($course)
            ->create([
                'code' => 'EN-A2-EVENING',
            ]);
    }

    public function test_same_cohort_code_can_be_used_by_different_centers(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $courseA = $this->createCourse($centerA);

        $courseB = $this->createCourse($centerB);

        $cohortA = Cohort::factory()
            ->forCourse($courseA)
            ->create([
                'code' => 'EN-A2-01',
            ]);

        $cohortB = Cohort::factory()
            ->forCourse($courseB)
            ->create([
                'code' => 'EN-A2-01',
            ]);

        $this->assertNotSame(
            $cohortA->center_id,
            $cohortB->center_id
        );

        $this->assertDatabaseCount('cohorts', 2);
    }

    public function test_open_for_enrollment_scope_returns_only_open_cohorts(): void
    {
        $center = Center::factory()->create();

        $course = $this->createCourse($center);

        $open = Cohort::factory()
            ->forCourse($course)
            ->openForEnrollment()
            ->create();

        $draft = Cohort::factory()
            ->forCourse($course)
            ->create([
                'status' => Cohort::STATUS_DRAFT,
            ]);

        $result = Cohort::query()
            ->forCenter($center->id)
            ->openForEnrollment()
            ->get();

        $this->assertCount(1, $result);

        $this->assertTrue(
            $result->first()->isOpenForEnrollment()
        );

        $this->assertSame($open->id, $result->first()->id);

        $this->assertFalse(
            $draft->fresh()->isOpenForEnrollment()
        );
    }
}
