<?php

namespace App\Http\Resources\Api\Learning;

use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningOverviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $course = $this->resource['course'];
        $modules = $this->resource['modules'];
        $progressByLesson = $this->resource['progress_by_lesson'];

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'short_description' => $course->short_description,
                'duration_hours' => $course->duration_hours,
                'cover_image_url' => $course->cover_image_url,
            ],

            'progress_summary' => $this->resource['progress_summary'],

            'modules' => $modules->map(
                function (CourseModule $module) use ($progressByLesson) {
                    return [
                        'id' => $module->id,
                        'title' => $module->title,
                        'description' => $module->description,
                        'sort_order' => $module->sort_order,

                        'lessons' => $module->lessons->map(
                            function (Lesson $lesson) use ($progressByLesson) {
                                $progress = $progressByLesson->get($lesson->id);

                                return [
                                    'id' => $lesson->id,
                                    'title' => $lesson->title,
                                    'description' => $lesson->description,
                                    'content_type' => $lesson->content_type,
                                    'duration_minutes' => $lesson->duration_minutes,
                                    'sort_order' => $lesson->sort_order,

                                    'progress' => [
                                        'status' => $progress?->status ?? 'not_started',
                                        'last_position_seconds' => $progress?->last_position_seconds ?? 0,
                                        'started_at' => $progress?->started_at?->toIso8601String(),
                                        'completed_at' => $progress?->completed_at?->toIso8601String(),
                                        'last_accessed_at' => $progress?->last_accessed_at?->toIso8601String(),
                                    ],
                                ];
                            }
                        )->values(),
                    ];
                }
            )->values(),
        ];
    }
}
