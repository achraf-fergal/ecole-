<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            'code' => 'en',
            'name' => 'English',
            'native_name' => 'English',

            'is_active' => true,
            'sort_order' => 1,
        ];
    }

    public function french(): static
    {
        return $this->state([
            'code' => 'fr',
            'name' => 'French',
            'native_name' => 'Français',
        ]);
    }

    public function german(): static
    {
        return $this->state([
            'code' => 'de',
            'name' => 'German',
            'native_name' => 'Deutsch',
        ]);
    }

    public function inactive(): static
    {
        return $this->state([
            'is_active' => false,
        ]);
    }
}
