<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Learning\LearningLessonResource;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

        $modules = $this->publishedCourseModules(
            $center,
            $course
        );

        /** @var EloquentCollection<int, Lesson> $lessonSequence */
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

        // Retrieve active assets without exposing storage information.
        $assets = $lesson->assets()
            ->forCenter($center->id)
            ->active()
            ->get([
                'id',
                'lesson_id',
                'title',
                'asset_type',
                'original_filename',
                'mime_type',
                'size_bytes',
                'duration_seconds',
                'alt_text',
                'caption',
                'is_downloadable',
                'sort_order',
            ]);

        // Retrieve published exercises and safe student-facing question data.
        $exercises = Exercise::query()
            ->forCenter($center->id)
            ->where('lesson_id', $lesson->id)
            ->published()
            ->with([
                'questions' => function ($query) use ($center) {
                    $query
                        ->forCenter($center->id)
                        ->with([
                            'options' => function ($optionQuery) use ($center) {
                                $optionQuery->forCenter($center->id);
                            },
                        ]);
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return new LearningLessonResource([
            'course' => $course,
            'lesson' => $lesson,
            'progress' => $progress,
            'assets' => $assets,
            'exercises' => $exercises,
            'previous_lesson' => $previousLesson,
            'next_lesson' => $nextLesson,
        ]);
    }

    /**
     * Update a student's lesson progress safely.
     *
     * The completed flag represents an explicit learner completion
     * confirmation. Exercise grading must be handled by the assessment API.
     */
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

        $now = now();

        $completedRequested = (bool) ($validated['completed'] ?? false);

        $progressValues = [
            'center_id' => $center->id,
            'student_profile_id' => $studentProfile->id,
            'lesson_id' => $lesson->id,

            'status' => LessonProgress::STATUS_IN_PROGRESS,

            'last_position_seconds' => $validated['last_position_seconds'] ?? 0,

            'started_at' => $now,
            'completed_at' => null,
            'last_accessed_at' => $now,

            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Only update fields explicitly supplied by the client.
        $updateColumns = [
            'last_accessed_at',
            'updated_at',
        ];

        if (array_key_exists('last_position_seconds', $validated)) {
            $updateColumns[] = 'last_position_seconds';
        }

        DB::transaction(function () use (
            $progressValues,
            $updateColumns,
            $center,
            $studentProfile,
            $lesson,
            $completedRequested,
            $now
        ) {
            // The unique database constraint protects simultaneous first writes.
            DB::table('lesson_progresses')->upsert(
                [$progressValues],
                [
                    'center_id',
                    'student_profile_id',
                    'lesson_id',
                ],
                $updateColumns
            );

            // Preserve the original completion timestamp.
            if ($completedRequested) {
                DB::table('lesson_progresses')
                    ->where('center_id', $center->id)
                    ->where('student_profile_id', $studentProfile->id)
                    ->where('lesson_id', $lesson->id)
                    ->whereNull('completed_at')
                    ->update([
                        'status' => LessonProgress::STATUS_COMPLETED,
                        'completed_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        });

        $progress = LessonProgress::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

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

    /**
     * Resolve and authorize the complete student learning context.
     *
     * @return array{
     *     0: Center,
     *     1: Course,
     *     2: Lesson,
     *     3: StudentProfile
     * }
     */
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

        // Authorize the course within its tenant.
        Gate::authorize('viewLearning', [$course, $center]);

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

        // Authorize the lesson inside the requested course and tenant.
        Gate::authorize(
            'viewLearning',
            [$lesson, $course, $center]
        );

        $studentProfile = StudentProfile::query()
            ->forCenter($center->id)
            ->where('user_id', $request->user()->id)
            ->active()
            ->first();

        abort_unless(
            $studentProfile !== null,
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

    /**
     * Retrieve the published course structure in display order.
     *
     * @return EloquentCollection<int, CourseModule>
     */
    private function publishedCourseModules(
        Center $center,
        Course $course
    ): EloquentCollection {
        return CourseModule::query()
            ->forCenter($center->id)
            ->where('course_id', $course->id)
            ->published()
            ->with([
                'lessons' => function ($query) use ($center) {
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
