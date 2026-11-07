<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\Level;
use App\Models\LevelTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Level>
 */
class LevelFactory extends Factory
{
    protected $model = Level::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),

            // NULL means the level was created by the center.
            'level_template_id' => null,

            'code' => 'CUSTOM-'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),

            'description' => fake()->sentence(),

            'sort_order' => 1,
            'is_active' => true,
        ];
    }

    public function fromTemplate(LevelTemplate $template): static
    {
        return $this->state([
            'level_template_id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'sort_order' => $template->sort_order,
        ]);
    }

    public function inactive(): static
    {
        return $this->state([
            'is_active' => false,
        ]);
    }
}
