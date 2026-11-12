<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentProfile>
 */
class StudentProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),
            'user_id' => User::factory(),
            'student_code' => fake()->unique()->bothify('STU-####??'),
            'phone' => null,
            'status' => StudentProfile::STATUS_ACTIVE,
        ];
    }

    /**
     * Ensure the tenant membership exists before saving the profile.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (StudentProfile $profile): void {
            CenterUser::query()->firstOrCreate(
                [
                    'center_id' => $profile->center_id,
                    'user_id' => $profile->user_id,
                ],
                [
                    'status' => 'active',
                ]
            );
        });
    }

    /**
     * Associate the profile with an existing membership.
     */
    public function forMembership(CenterUser $membership): static
    {
        return $this->state([
            'center_id' => $membership->center_id,
            'user_id' => $membership->user_id,
        ]);
    }

    /**
     * Create a suspended profile.
     */
    public function suspended(): static
    {
        return $this->state([
            'status' => StudentProfile::STATUS_SUSPENDED,
        ]);
    }

    /**
     * Create an archived profile.
     */
    public function archived(): static
    {
        return $this->state([
            'status' => StudentProfile::STATUS_ARCHIVED,
        ]);
    }
}
