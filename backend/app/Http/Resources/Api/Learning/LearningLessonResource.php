<?php

namespace App\Http\Resources\Api\Learning;

use App\Models\Course;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningLessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Course $course */
        $course = $this->resource['course'];

        /** @var Lesson $lesson */
        $lesson = $this->resource['lesson'];

        $progress = $this->resource['progress'];

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
            ],

            'module' => [
                'id' => $lesson->courseModule->id,
                'title' => $lesson->courseModule->title,
                'description' => $lesson->courseModule->description,
            ],

            'lesson' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'content_type' => $lesson->content_type,
                'body' => $lesson->body,
                'content_locale' => $lesson->content_locale,
                'duration_minutes' => $lesson->duration_minutes,
                'sort_order' => $lesson->sort_order,
            ],

            'progress' => [
                'status' => $progress?->status ?? 'not_started',
                'last_position_seconds' => $progress?->last_position_seconds ?? 0,
                'started_at' => $progress?->started_at?->toIso8601String(),
                'completed_at' => $progress?->completed_at?->toIso8601String(),
                'last_accessed_at' => $progress?->last_accessed_at?->toIso8601String(),
            ],

            // Only expose safe asset metadata.
            'assets' => $this->resource['assets']->map(
                function (LessonAsset $asset): array {
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
                    ];
                }
            )->values(),

            // Question answers and explanations are intentionally excluded.
            'exercises' => $this->resource['exercises']->map(
                function (Exercise $exercise): array {
                    return [
                        'id' => $exercise->id,
                        'title' => $exercise->title,
                        'instructions' => $exercise->instructions,
                        'max_attempts' => $exercise->max_attempts,
                        'passing_score' => $exercise->passing_score,
                        'sort_order' => $exercise->sort_order,

                        'questions' => $exercise->questions->map(
                            function ($question): array {
                                return [
                                    'id' => $question->id,
                                    'question_type' => $question->question_type,
                                    'prompt' => $question->prompt,
                                    'points' => $question->points,
                                    'sort_order' => $question->sort_order,

                                    'options' => $question->options->map(
                                        function ($option): array {
                                            return [
                                                'id' => $option->id,
                                                'option_text' => $option->option_text,
                                                'sort_order' => $option->sort_order,
                                            ];
                                        }
                                    )->values(),
                                ];
                            }
                        )->values(),
                    ];
                }
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
