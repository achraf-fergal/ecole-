<?php

namespace Database\Factories;

use App\Models\Center;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Center>
 */
class CenterFactory extends Factory
{
    protected $model = Center::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' Language Center';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),

            'email' => fake()->safeEmail(),
            'phone' => null,
            'logo_url' => null,

            'status' => 'active',

            'timezone' => 'Africa/Casablanca',
            'default_locale' => 'en',
        ];
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => 'suspended',
        ]);
    }
}
