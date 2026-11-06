<?php

namespace Database\Factories;

use App\Models\Center;
use App\Models\Course;
use App\Models\Language;
use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        $title = fake()->words(2, true);

        return [
            // Must be provided through forCenter().
            'center_id' => null,
            'language_id' => null,
            'level_id' => null,

            'title' => Str::title($title),
            'slug' => Str::slug($title).'-'.fake()->unique()->numerify('####'),

            'short_description' => fake()->sentence(),
            'description' => fake()->paragraphs(2, true),
            'prerequisites' => null,

            'study_format' => 'in_person',
            'duration_hours' => null,

            // Images are optional and can be external URLs.
            'cover_image_url' => null,

            'price' => null,
            'currency' => 'MAD',

            'status' => 'draft',
            'sort_order' => 0,
            'published_at' => null,
        ];
    }

    /**
     * Create language and level records that belong to the same center.
     */
    public function forCenter(Center $center): static
    {
        return $this->state([
            'center_id' => $center->id,

            'language_id' => Language::factory()
                ->for($center),

            'level_id' => Level::factory()
                ->for($center),
        ]);
    }

    /**
     * Override the automatically generated language.
     */
    public function withLanguage(Language $language): static
    {
        return $this->state([
            'language_id' => $language->id,
        ]);
    }

    /**
     * Override the automatically generated level.
     */
    public function withLevel(Level $level): static
    {
        return $this->state([
            'level_id' => $level->id,
        ]);
    }

    public function published(): static
    {
        return $this->state([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state([
            'status' => 'archived',
        ]);
    }
}
