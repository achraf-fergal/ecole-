<?php

namespace Database\Factories;

use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cohort>
 */
class CohortFactory extends Factory
{
    protected $model = Cohort::class;

    public function definition(): array
    {
        return [
            // Assigned through forCourse().
            'center_id' => null,
            'course_id' => null,

            // A draft cohort may have no teacher yet.
            'teacher_user_id' => null,

            'name' => fake()->words(3, true),

            'code' => fake()->unique()->bothify('COH-#####'),

            // Default test dates.
            'starts_at' => now()->addDays(14),
            'ends_at' => now()->addMonths(3),

            'capacity' => 15,

            'location_label' => null,

            'status' => Cohort::STATUS_DRAFT,
        ];
    }

    /**
     * Associate the cohort with a course and its center.
     */
    public function forCourse(Course $course): static
    {
        return $this->state([
            'center_id' => $course->center_id,
            'course_id' => $course->id,
        ]);
    }

    /**
     * Assign a teacher through an existing center membership.
     *
     * The database verifies that the membership belongs
     * to the same center as the cohort.
     */
    public function taughtBy(CenterUser $membership): static
    {
        return $this->state([
            'teacher_user_id' => $membership->user_id,
        ]);
    }

    /**
     * Create a cohort accepting enrollments.
     */
    public function openForEnrollment(): static
    {
        return $this->state([
            'status' => Cohort::STATUS_OPEN,
        ]);
    }

    /**
     * Create a cohort currently in progress.
     */
    public function inProgress(): static
    {
        return $this->state([
            'status' => Cohort::STATUS_IN_PROGRESS,
        ]);
    }

    /**
     * Create a cancelled cohort.
     */
    public function cancelled(): static
    {
        return $this->state([
            'status' => Cohort::STATUS_CANCELLED,
        ]);
    }
}
