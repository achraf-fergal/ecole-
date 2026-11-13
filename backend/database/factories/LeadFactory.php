<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\Lead;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            'course_id' => null,

            'assigned_to_user_id' => null,

            'converted_student_profile_id' => null,

            'converted_by_user_id' => null,

            'duplicate_of_lead_id' => null,

            'name' => fake()->name(),

            'phone' => fake()->phoneNumber(),

            'email' => null,

            'preferred_contact_method' => 'phone',

            'message' => fake()->optional()->sentence(),

            'source' => Lead::SOURCE_WEBSITE,

            'source_detail' => null,

            'status' => Lead::STATUS_NEW,

            'lost_reason' => null,

            'next_follow_up_at' => null,

            'privacy_notice_accepted_at' => null,

            'converted_at' => null,
        ];
    }

    /**
     * Associate the lead with a course and its center.
     */
    public function forCourse(Course $course): static
    {
        return $this->state([
            'center_id' => $course->center_id,
            'course_id' => $course->id,
        ]);
    }

    /**
     * Assign the lead to a center membership.
     */
    public function assignedTo(CenterUser $membership): static
    {
        return $this->state([
            'center_id' => $membership->center_id,
            'assigned_to_user_id' => $membership->user_id,
        ]);
    }

    /**
     * Create a converted lead.
     */
    public function convertedTo(
        StudentProfile $profile,
        CenterUser $actor
    ): static {
        return $this->state([
            'center_id' => $profile->center_id,
            'converted_student_profile_id' => $profile->id,
            'converted_by_user_id' => $actor->user_id,
            'converted_at' => now(),
            'status' => Lead::STATUS_CONVERTED,
        ]);
    }

    /**
     * Mark this lead as a duplicate of another lead.
     */
    public function duplicateOf(Lead $lead): static
    {
        return $this->state([
            'center_id' => $lead->center_id,
            'duplicate_of_lead_id' => $lead->id,
            'status' => Lead::STATUS_DUPLICATE,
        ]);
    }

    /**
     * Create a manually entered lead.
     */
    public function fromManual(): static
    {
        return $this->state([
            'source' => Lead::SOURCE_MANUAL,
        ]);
    }

    /**
     * Create a lost lead with a reason.
     */
    public function lost(string $reason): static
    {
        return $this->state([
            'status' => Lead::STATUS_LOST,
            'lost_reason' => $reason,
        ]);
    }
}
