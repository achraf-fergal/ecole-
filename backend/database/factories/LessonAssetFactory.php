<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\LessonAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LessonAsset>
 */
class LessonAssetFactory extends Factory
{
    protected $model = LessonAsset::class;

    public function definition(): array
    {
        return [
            'center_id' => null,
            'lesson_id' => null,

            'title' => fake()->sentence(3),

            'asset_type' => LessonAsset::TYPE_FILE,

            'storage_disk' => 'private',

            'storage_key' => 'pending/' . Str::uuid() . '.pdf',

            'original_filename' => 'learning-resource.pdf',

            'mime_type' => 'application/pdf',

            'size_bytes' => fake()->numberBetween(
                1024,
                5 * 1024 * 1024
            ),

            'video_provider' => null,
            'provider_asset_id' => null,

            'external_url' => null,

            'duration_seconds' => null,

            'alt_text' => null,
            'caption' => null,

            'is_downloadable' => true,

            'sort_order' => 0,

            'status' => LessonAsset::STATUS_ACTIVE,
        ];
    }

    /**
     * Associate the asset with a lesson and its tenant.
     */
    public function forLesson(Lesson $lesson): static
    {
        return $this->state([
            'center_id' => $lesson->center_id,
            'lesson_id' => $lesson->id,

            'storage_key' => sprintf(
                'centers/%d/lessons/%d/assets/%s',
                $lesson->center_id,
                $lesson->id,
                Str::uuid()
            ),
        ]);
    }

    /**
     * Create a private PDF resource.
     */
    public function privateFile(): static
    {
        return $this->state([
            'asset_type' => LessonAsset::TYPE_FILE,

            'storage_disk' => 'private',

            'original_filename' => 'worksheet.pdf',

            'mime_type' => 'application/pdf',

            'external_url' => null,

            'video_provider' => null,
            'provider_asset_id' => null,

            'is_downloadable' => true,
        ]);
    }

    /**
     * Create a private image resource.
     */
    public function image(): static
    {
        return $this->state([
            'asset_type' => LessonAsset::TYPE_IMAGE,

            'storage_disk' => 'private',


            'original_filename' => 'lesson-image.jpg',

            'mime_type' => 'image/jpeg',

            'alt_text' => 'Educational illustration',

            'is_downloadable' => false,

            'external_url' => null,

            'video_provider' => null,
            'provider_asset_id' => null,
        ]);
    }

    /**
     * Create a video provider reference.
     */
    public function video(): static
    {
        return $this->state([
            'asset_type' => LessonAsset::TYPE_VIDEO,

            'storage_disk' => null,
            'storage_key' => null,

            'original_filename' => null,
            'mime_type' => null,
            'size_bytes' => null,

            'video_provider' => 'cloudflare_stream',

            'provider_asset_id' => (string) Str::uuid(),

            'external_url' => null,

            'duration_seconds' => 600,

            'is_downloadable' => false,
        ]);
    }

    /**
     * Create a normal external HTTPS resource.
     */
    public function externalLink(): static
    {
        return $this->state([
            'asset_type' => LessonAsset::TYPE_EXTERNAL_LINK,

            'storage_disk' => null,
            'storage_key' => null,

            'original_filename' => null,
            'mime_type' => null,
            'size_bytes' => null,

            'video_provider' => null,
            'provider_asset_id' => null,

            'external_url' => 'https://example.com/learning-resource',

            'is_downloadable' => false,
        ]);
    }

    /**
     * Archive the asset without deleting its record.
     */
    public function archived(): static
    {
        return $this->state([
            'status' => LessonAsset::STATUS_ARCHIVED,
        ]);
    }
}
