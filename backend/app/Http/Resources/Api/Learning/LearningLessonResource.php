<?php

namespace App\Http\Resources\Api\Learning;

use App\Models\Course;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class LearningLessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Course $course */
        $course = $this->resource['course'];

        /** @var Lesson $lesson */
        $lesson = $this->resource['lesson'];

        /** @var LessonRevision|null $revision */
        $revision = $this->resource['revision'];

        /** @var Collection<int, LessonBlock> $blocks */
        $blocks = $this->resource['blocks'];

        $progress = $this->resource['progress'];

        $isAdminPreview = $this->resource['access_mode'] === 'admin_preview';

        return [
            'access_mode' => $this->resource['access_mode'],

            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'status' => $course->status,
                'published_at' => $course->published_at?->toIso8601String(),
            ],

            'module' => [
                'id' => $lesson->courseModule->id,
                'title' => $lesson->courseModule->title,
                'description' => $lesson->courseModule->description,
                'status' => $lesson->courseModule->status,
                'published_at' => $lesson->courseModule->published_at?->toIso8601String(),
            ],

            'lesson' => [
                'id' => $lesson->id,
                'title' => $revision?->title ?? $lesson->title,
                'description' => $revision?->description ?? $lesson->description,
                'content_type' => $lesson->content_type,

                // Temporary backwards compatibility. The player should use blocks.
                'body' => $lesson->body,

                'content_locale' => $revision?->content_locale
                    ?? $lesson->content_locale,
                'duration_minutes' => $revision?->duration_minutes
                    ?? $lesson->duration_minutes,
                'sort_order' => $lesson->sort_order,
                'status' => $lesson->status,
                'published_at' => $lesson->published_at?->toIso8601String(),
            ],

            'revision' => $revision ? [
                'id' => $revision->id,
                'version_number' => $revision->version_number,
                'status' => $revision->status,
                'title' => $revision->title,
                'description' => $revision->description,
                'content_locale' => $revision->content_locale,
                'duration_minutes' => $revision->duration_minutes,
                'created_at' => $revision->created_at?->toIso8601String(),
                'published_at' => $revision->published_at?->toIso8601String(),
            ] : null,

            'blocks' => $blocks->map(
                fn (LessonBlock $block): array => $this->blockPayload($block, $isAdminPreview)
            )->values(),

            'progress' => [
                'status' => $progress?->status ?? 'not_started',
                'last_position_seconds' => $progress?->last_position_seconds ?? 0,
                'started_at' => $progress?->started_at?->toIso8601String(),
                'completed_at' => $progress?->completed_at?->toIso8601String(),
                'last_accessed_at' => $progress?->last_accessed_at?->toIso8601String(),
            ],

            // Deprecated compatibility fields for the current frontend.
            'assets' => $this->resource['assets']->map(
                fn (LessonAsset $asset): array => $this->assetPayload($asset)
            )->values(),

            'exercises' => $this->resource['exercises']->map(
                fn (Exercise $exercise): array => $this->exercisePayload($exercise, $isAdminPreview)
            )->values(),

            'navigation' => [
                'previous_lesson' => $this->lessonReference(
                    $this->resource['previous_lesson']
                ),
                'next_lesson' => $this->lessonReference(
                    $this->resource['next_lesson']
                ),
            ],
        ];
    }

    private function blockPayload(
        LessonBlock $block,
        bool $isAdminPreview
    ): array {
        return [
            'id' => $block->id,
            'block_type' => $block->block_type,
            'sort_order' => $block->sort_order,
            'content_json' => $block->isText()
                ? $block->content_json
                : null,
            'settings' => $isAdminPreview
                ? $block->settings
                : null,
            'asset' => $block->asset instanceof LessonAsset
                ? $this->assetPayload($block->asset)
                : null,
            'exercise' => $block->exercise instanceof Exercise
                ? $this->exercisePayload(
                    $block->exercise,
                    $isAdminPreview
                )
                : null,
        ];
    }

    private function assetPayload(LessonAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'title' => $asset->title,
            'asset_type' => $asset->asset_type,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
            'duration_seconds' => $asset->duration_seconds,
            'alt_text' => $asset->alt_text,
            'caption' => $asset->caption,
            'is_downloadable' => $asset->is_downloadable,
            'sort_order' => $asset->sort_order,
            'status' => $asset->status,
        ];
    }

    private function exercisePayload(
        Exercise $exercise,
        bool $isAdminPreview
    ): array {
        return [
            'id' => $exercise->id,
            'title' => $exercise->title,
            'instructions' => $exercise->instructions,
            'max_attempts' => $exercise->max_attempts,
            'passing_score' => $exercise->passing_score,
            'sort_order' => $exercise->sort_order,
            'status' => $exercise->status,
            'published_at' => $exercise->published_at?->toIso8601String(),
            'questions' => $exercise->questions->map(
                function ($question) use ($isAdminPreview): array {
                    $questionData = [
                        'id' => $question->id,
                        'question_type' => $question->question_type,
                        'prompt' => $question->prompt,
                        'points' => $question->points,
                        'sort_order' => $question->sort_order,
                        'options' => $question->options->map(
                            function ($option) use ($isAdminPreview): array {
                                $optionData = [
                                    'id' => $option->id,
                                    'option_text' => $option->option_text,
                                    'sort_order' => $option->sort_order,
                                ];

                                if ($isAdminPreview) {
                                    $optionData['is_correct'] = $option->is_correct;
                                }

                                return $optionData;
                            }
                        )->values(),
                    ];

                    if ($isAdminPreview) {
                        $questionData['explanation'] = $question->explanation;
                    }

                    return $questionData;
                }
            )->values(),
        ];
    }

    private function lessonReference(?Lesson $lesson): ?array
    {
        if (! $lesson) {
            return null;
        }

        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
        ];
    }
}
