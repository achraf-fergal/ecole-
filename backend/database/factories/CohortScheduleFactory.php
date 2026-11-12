<?php

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\CohortSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CohortSchedule>
 */
class CohortScheduleFactory extends Factory
{
    protected $model = CohortSchedule::class;

    public function definition(): array
    {
        return [
            // Assigned through forCohort().
            'center_id' => null,
            'cohort_id' => null,

            'day_of_week' => fake()->numberBetween(1, 5),

            'start_time' => '18:00:00',

            'end_time' => '20:00:00',

            'location_label' => null,
        ];
    }

    /**
     * Associate the schedule with a cohort and its center.
     */
    public function forCohort(Cohort $cohort): static
    {
        return $this->state([
            'center_id' => $cohort->center_id,
            'cohort_id' => $cohort->id,
        ]);
    }

    /**
     * Set a morning schedule.
     */
    public function morning(): static
    {
        return $this->state([
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
    }

    /**
     * Set an afternoon schedule.
     */
    public function afternoon(): static
    {
        return $this->state([
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
        ]);
    }

    /**
     * Assign a physical location.
     */
    public function atLocation(string $location): static
    {
        return $this->state([
            'location_label' => $location,
        ]);
    }
}
