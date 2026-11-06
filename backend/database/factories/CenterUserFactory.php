<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CenterUser>
 */
class CenterUserFactory extends Factory
{
    protected $model = CenterUser::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),
            'user_id' => User::factory(),

            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => 'suspended',
        ]);
    }
}
