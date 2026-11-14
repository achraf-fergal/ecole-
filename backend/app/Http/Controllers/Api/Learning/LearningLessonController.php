<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Learning\LearningLessonResource;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LearningLessonController extends Controller
{
    public function show(Request $request): LearningLessonResource
    {
        [$center, $course, $lesson, $studentProfile] =
            $this->resolveLearningContext($request);

        $modules = $this->publishedCourseModules($center, $course);

        $lessonSequence = $modules
            ->flatMap(fn (CourseModule $module) => $module->lessons)
            ->values();

        $currentIndex = $lessonSequence->search(
            fn (Lesson $item) => $item->is($lesson)
        );

        $previousLesson = $currentIndex !== false && $currentIndex > 0
            ? $lessonSequence->get($currentIndex - 1)
            : null;

        $nextLesson = $currentIndex !== false
            ? $lessonSequence->get($currentIndex + 1)
            : null;

        $progress = LessonProgress::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        $assets = $lesson->assets()
            ->forCenter($center->id)
            ->active()
            ->get([
                'id',
                'lesson_id',
                'title',
                'asset_type',
                'mime_type',
                'size_bytes',
                'duration_seconds',
                'alt_text',
                'caption',
                'is_downloadable',
                'sort_order',
            ]);

        return new LearningLessonResource([
            'course' => $course,
            'lesson' => $lesson,
            'progress' => $progress,
            'assets' => $assets,
            'previous_lesson' => $previousLesson,
            'next_lesson' => $nextLesson,
        ]);
    }

    public function updateProgress(Request $request): JsonResponse
    {
        [$center, $course, $lesson, $studentProfile] =
            $this->resolveLearningContext($request);

        $validated = $request->validate([
            'last_position_seconds' => [
                'sometimes',
                'integer',
                'min:0',
                'max:4294967295',
            ],
            'completed' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $progress = DB::transaction(function () use (
            $center,
            $lesson,
            $studentProfile,
            $validated
        ) {
            $progress = LessonProgress::query()
                ->where('center_id', $center->id)
                ->where('student_profile_id', $studentProfile->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();

            if (! $progress) {
                $progress = new LessonProgress();

                $progress->center_id = $center->id;
                $progress->student_profile_id = $studentProfile->id;
                $progress->lesson_id = $lesson->id;
                $progress->status = 'in_progress';
                $progress->last_position_seconds = 0;
            }

            $now = now();

            $progress->started_at ??= $now;
            $progress->last_accessed_at = $now;

            if (array_key_exists('last_position_seconds', $validated)) {
                $progress->last_position_seconds =
                    $validated['last_position_seconds'];
            }

            $completedRequested = (bool) ($validated['completed'] ?? false);

            // Once completed, the lesson stays completed.
            if ($completedRequested || $progress->status === 'completed') {
                $progress->status = 'completed';
                $progress->completed_at ??= $now;
            } else {
                $progress->status = 'in_progress';
            }

            $progress->save();

            return $progress->fresh();
        });

        return response()->json([
            'data' => [
                'lesson_id' => $lesson->id,
                'status' => $progress->status,
                'last_position_seconds' => $progress->last_position_seconds,
                'started_at' => $progress->started_at?->toIso8601String(),
                'completed_at' => $progress->completed_at?->toIso8601String(),
                'last_accessed_at' => $progress->last_accessed_at?->toIso8601String(),
            ],
        ]);
    }

    private function resolveLearningContext(Request $request): array
    {
        $center = $request->attributes->get('current_center');

        abort_unless(
            $center instanceof Center,
            404,
            'Center not found.'
        );

        $courseParam = (string) $request->route('course');

        $courseQuery = Course::query()
            ->where('center_id', $center->id);

        if (ctype_digit($courseParam)) {
            $courseQuery->whereKey((int) $courseParam);
        } else {
            $courseQuery->where('slug', $courseParam);
        }

        $course = $courseQuery->firstOrFail();

        Gate::authorize('viewLearning', $course);

        $lessonParam = (string) $request->route('lesson');

        abort_unless(
            ctype_digit($lessonParam),
            404,
            'Lesson not found.'
        );

        $lesson = Lesson::query()
            ->forCenter($center->id)
            ->whereKey((int) $lessonParam)
            ->published()
            ->whereHas('courseModule', function (Builder $query) use (
                $center,
                $course
            ) {
                $query
                    ->forCenter($center->id)
                    ->where('course_id', $course->id)
                    ->published();
            })
            ->with([
                'courseModule:id,center_id,course_id,title,description,sort_order',
            ])
            ->firstOrFail();

        Gate::authorize('viewLearning', $lesson);

        $studentProfile = StudentProfile::query()
            ->forCenter($center->id)
            ->where('user_id', $request->user()->id)
            ->active()
            ->first();

        abort_unless(
            $studentProfile,
            403,
            'Active student profile required.'
        );

        return [
            $center,
            $course,
            $lesson,
            $studentProfile,
        ];
    }

    private function publishedCourseModules(
        Center $center,
        Course $course
    ) {
        return CourseModule::query()
            ->forCenter($center->id)
            ->where('course_id', $course->id)
            ->published()
            ->with([
                'lessons' => function (Builder $query) use ($center) {
                    $query
                        ->forCenter($center->id)
                        ->published();
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
