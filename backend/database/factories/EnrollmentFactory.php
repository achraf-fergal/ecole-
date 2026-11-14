<?php

namespace Database\Factories;

use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'center_id' => null,

            'student_profile_id' => null,

            'cohort_id' => null,

            'granted_by_user_id' => null,

            'status' => Enrollment::STATUS_ACTIVE,

            'start_at' => now(),

            'end_at' => null,
        ];
    }

    /**
     * Associate an enrollment with a student profile.
     */
    public function forStudentProfile(
        StudentProfile $profile
    ): static {
        return $this->state([
            'center_id' => $profile->center_id,
            'student_profile_id' => $profile->id,
        ]);
    }

    /**
     * Associate an enrollment with a cohort.
     */
    public function forCohort(
        Cohort $cohort
    ): static {
        return $this->state([
            'center_id' => $cohort->center_id,
            'cohort_id' => $cohort->id,
        ]);
    }

    /**
     * Record the center member granting access.
     */
    public function grantedBy(
        CenterUser $membership
    ): static {
        return $this->state([
            'center_id' => $membership->center_id,
            'granted_by_user_id' => $membership->user_id,
        ]);
    }

    /**
     * Create a suspended enrollment.
     */
    public function suspended(): static
    {
        return $this->state([
            'status' => Enrollment::STATUS_SUSPENDED,
        ]);
    }

    /**
     * Create a completed enrollment.
     */
    public function completed(): static
    {
        return $this->state([
            'status' => Enrollment::STATUS_COMPLETED,
        ]);
    }

    /**
     * Create a cancelled enrollment.
     */
    public function cancelled(): static
    {
        return $this->state([
            'status' => Enrollment::STATUS_CANCELLED,
        ]);
    }
}
