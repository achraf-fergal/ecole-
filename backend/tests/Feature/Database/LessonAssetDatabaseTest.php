<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonAsset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LessonAssetDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a lesson with valid course, module and center ownership.
     */
    private function createLesson(Center $center): Lesson
    {
        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $module = CourseModule::factory()
            ->forCourse($course)
            ->create();

        return Lesson::factory()
            ->forModule($module)
            ->create();
    }

    public function test_lesson_assets_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('lesson_assets', [
                'id',
                'center_id',
                'lesson_id',
                'title',
                'asset_type',
                'storage_disk',
                'storage_key',
                'original_filename',
                'mime_type',
                'size_bytes',
                'video_provider',
                'provider_asset_id',
                'external_url',
                'duration_seconds',
                'alt_text',
                'caption',
                'is_downloadable',
                'sort_order',
                'status',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_asset_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create([
                'title' => 'Grammar Worksheet',
            ]);

        $this->assertTrue(
            $asset->lesson->is($lesson)
        );

        $this->assertTrue(
            $asset->center->is($center)
        );

        $this->assertTrue(
            $lesson->assets()
                ->whereKey($asset->id)
                ->exists()
        );

        $this->assertTrue(
            $center->lessonAssets()
                ->whereKey($asset->id)
                ->exists()
        );
    }

    public function test_lesson_can_have_multiple_ordered_assets(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create([
                'title' => 'Third Resource',
                'sort_order' => 3,
            ]);

        LessonAsset::factory()
            ->forLesson($lesson)
            ->image()
            ->create([
                'title' => 'First Image',
                'sort_order' => 1,
            ]);

        LessonAsset::factory()
            ->forLesson($lesson)
            ->video()
            ->create([
                'title' => 'Second Video',
                'sort_order' => 2,
            ]);

        $assets = $lesson->fresh()
            ->assets()
            ->pluck('title')
            ->all();

        $this->assertSame([
            'First Image',
            'Second Video',
            'Third Resource',
        ], $assets);
    }

    public function test_asset_cannot_reference_lesson_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $lessonA = $this->createLesson($centerA);

        $this->expectException(QueryException::class);

        LessonAsset::factory()
            ->forLesson($lessonA)
            ->create([
                'center_id' => $centerB->id,
            ]);
    }

    public function test_private_file_stores_storage_reference(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->create();

        $this->assertSame('private', $asset->storage_disk);

        $this->assertStringStartsWith(
            sprintf(
                'centers/%d/lessons/%d/assets/',
                $center->id,
                $lesson->id
            ),
            $asset->storage_key
        );

        $this->assertTrue(
            $asset->isPrivateStorageAsset()
        );

        $this->assertNull($asset->external_url);

        $this->assertTrue($asset->is_downloadable);
    }

    public function test_video_stores_provider_reference_without_signed_url(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->video()
            ->create();

        $this->assertTrue(
            $asset->isProviderVideo()
        );

        $this->assertSame(
            'cloudflare_stream',
            $asset->video_provider
        );

        $this->assertNotEmpty(
            $asset->provider_asset_id
        );

        $this->assertNull($asset->storage_key);

        $this->assertNull($asset->external_url);

        $this->assertFalse(
            $asset->is_downloadable
        );
    }

    public function test_archiving_asset_preserves_its_record(): void
    {
        $center = Center::factory()->create();

        $lesson = $this->createLesson($center);

        $asset = LessonAsset::factory()
            ->forLesson($lesson)
            ->privateFile()
            ->archived()
            ->create();

        $this->assertTrue($asset->isArchived());

        $this->assertDatabaseHas('lesson_assets', [
            'id' => $asset->id,
            'center_id' => $center->id,
            'lesson_id' => $lesson->id,
            'status' => LessonAsset::STATUS_ARCHIVED,
        ]);

        $this->assertCount(
            0,
            LessonAsset::query()
                ->active()
                ->whereKey($asset->id)
                ->get()
        );
    }
    public function test_external_link_stores_normal_https_url(): void
{
    $center = Center::factory()->create();

    $lesson = $this->createLesson($center);

    $asset = LessonAsset::factory()
        ->forLesson($lesson)
        ->externalLink()
        ->create();

    $this->assertSame(
        LessonAsset::TYPE_EXTERNAL_LINK,
        $asset->asset_type
    );

    $this->assertSame(
        'https://example.com/learning-resource',
        $asset->external_url
    );

    $this->assertNull($asset->storage_key);

    $this->assertNull($asset->video_provider);
}
}
