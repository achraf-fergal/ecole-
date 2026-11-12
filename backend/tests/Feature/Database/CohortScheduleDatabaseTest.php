<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\Cohort;
use App\Models\CohortSchedule;
use App\Models\Course;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CohortScheduleDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a cohort with a valid course and center.
     */
    private function createCohort(Center $center): Cohort
    {
        $course = Course::factory()
            ->forCenter($center)
            ->create();

        return Cohort::factory()
            ->forCourse($course)
            ->create();
    }

    public function test_cohort_schedules_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('cohort_schedules', [
                'id',
                'center_id',
                'cohort_id',
                'day_of_week',
                'start_time',
                'end_time',
                'location_label',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_schedule_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $cohort = $this->createCohort($center);

        $schedule = CohortSchedule::factory()
            ->forCohort($cohort)
            ->create([
                'day_of_week' => CohortSchedule::MONDAY,
                'location_label' => 'Room A',
            ]);

        $this->assertTrue(
            $schedule->center->is($center)
        );

        $this->assertTrue(
            $schedule->cohort->is($cohort)
        );

        $this->assertTrue(
            $cohort->schedules()
                ->whereKey($schedule->id)
                ->exists()
        );

        $this->assertTrue(
            $center->cohortSchedules()
                ->whereKey($schedule->id)
                ->exists()
        );

        $this->assertSame('Monday', $schedule->day_name);
    }

    public function test_cohort_can_have_multiple_ordered_schedules(): void
    {
        $center = Center::factory()->create();

        $cohort = $this->createCohort($center);

        $wednesday = CohortSchedule::factory()
            ->forCohort($cohort)
            ->create([
                'day_of_week' => CohortSchedule::WEDNESDAY,
                'start_time' => '18:00:00',
            ]);

        $mondayEvening = CohortSchedule::factory()
            ->forCohort($cohort)
            ->create([
                'day_of_week' => CohortSchedule::MONDAY,
                'start_time' => '18:00:00',
            ]);

        $mondayMorning = CohortSchedule::factory()
            ->forCohort($cohort)
            ->morning()
            ->create([
                'day_of_week' => CohortSchedule::MONDAY,
            ]);

        $scheduleIds = $cohort->fresh()
            ->schedules
            ->pluck('id')
            ->all();

        $this->assertSame([
            $mondayMorning->id,
            $mondayEvening->id,
            $wednesday->id,
        ], $scheduleIds);
    }

    public function test_schedule_cannot_reference_cohort_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $cohortB = $this->createCohort($centerB);

        $this->expectException(QueryException::class);

        CohortSchedule::factory()
            ->forCohort($cohortB)
            ->create([
                'center_id' => $centerA->id,
            ]);
    }

    public function test_same_cohort_cannot_have_duplicate_start_time_on_same_day(): void
    {
        $center = Center::factory()->create();

        $cohort = $this->createCohort($center);

        CohortSchedule::factory()
            ->forCohort($cohort)
            ->create([
                'day_of_week' => CohortSchedule::MONDAY,
                'start_time' => '18:00:00',
                'end_time' => '20:00:00',
            ]);

        $this->expectException(QueryException::class);

        CohortSchedule::factory()
            ->forCohort($cohort)
            ->create([
                'day_of_week' => CohortSchedule::MONDAY,
                'start_time' => '18:00:00',
                'end_time' => '21:00:00',
            ]);
    }

    public function test_cohort_can_have_multiple_sessions_on_same_day(): void
    {
        $center = Center::factory()->create();

        $cohort = $this->createCohort($center);

        $morning = CohortSchedule::factory()
            ->forCohort($cohort)
            ->morning()
            ->create([
                'day_of_week' => CohortSchedule::SATURDAY,
            ]);

        $afternoon = CohortSchedule::factory()
            ->forCohort($cohort)
            ->afternoon()
            ->create([
                'day_of_week' => CohortSchedule::SATURDAY,
            ]);

        $this->assertSame(
            2,
            $cohort->schedules()->count()
        );

        $this->assertDatabaseHas('cohort_schedules', [
            'id' => $morning->id,
            'day_of_week' => CohortSchedule::SATURDAY,
        ]);

        $this->assertDatabaseHas('cohort_schedules', [
            'id' => $afternoon->id,
            'day_of_week' => CohortSchedule::SATURDAY,
        ]);
    }

    
    public function test_different_cohorts_can_use_same_weekly_time_slot(): void
    {
        $center = Center::factory()->create();
    
        // Both cohorts share one course.
        // This avoids creating duplicate default center languages.
        $course = Course::factory()
            ->forCenter($center)
            ->create();
    
        $cohortA = Cohort::factory()
            ->forCourse($course)
            ->create();
    
        $cohortB = Cohort::factory()
            ->forCourse($course)
            ->create();
    
        // Different cohorts may use the same weekly time slot.
        $scheduleA = CohortSchedule::factory()
            ->forCohort($cohortA)
            ->create([
                'day_of_week' => CohortSchedule::MONDAY,
                'start_time' => '18:00:00',
                'end_time' => '20:00:00',
            ]);
    
        $scheduleB = CohortSchedule::factory()
            ->forCohort($cohortB)
            ->create([
                'day_of_week' => CohortSchedule::MONDAY,
                'start_time' => '18:00:00',
                'end_time' => '20:00:00',
            ]);
    
        $this->assertDatabaseCount('cohort_schedules', 2);
    
        $this->assertNotSame(
            $scheduleA->cohort_id,
            $scheduleB->cohort_id
        );
    
        $this->assertSame(
            $scheduleA->start_time,
            $scheduleB->start_time
        );
    }
    
    public function test_day_names_and_location_override_are_preserved(): void
    {
        $center = Center::factory()->create();

        $cohort = $this->createCohort($center);

        $schedule = CohortSchedule::factory()
            ->forCohort($cohort)
            ->create([
                'day_of_week' => CohortSchedule::SUNDAY,
                'location_label' => 'Online Classroom',
            ]);

        $this->assertSame('Sunday', $schedule->day_name);

        $this->assertSame(
            'Online Classroom',
            $schedule->location_label
        );

        $this->assertNull(
            CohortSchedule::DAYS_OF_WEEK[8] ?? null
        );
    }
}
