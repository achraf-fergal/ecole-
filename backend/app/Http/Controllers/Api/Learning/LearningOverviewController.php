<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Learning\LearningOverviewResource;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\LessonProgress;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LearningOverviewController extends Controller
{
    public function __invoke(Request $request): LearningOverviewResource
    {
        $center = $this->currentCenter($request);

        $course = $this->resolveCourse(
            $center,
            $request->route('course')
        );

        // Authorize the course within the current tenant.
        Gate::authorize('viewLearning', [$course, $center]);

        $studentProfile = $this->resolveStudentProfile(
            $request,
            $center
        );

        $modules = CourseModule::query()
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

        $lessonIds = $modules
            ->flatMap(
                fn ($module) => $module->lessons->pluck('id')
            )
            ->values();

        $progressByLesson = $lessonIds->isEmpty()
            ? collect()
            : LessonProgress::query()
                ->where('center_id', $center->id)
                ->where('student_profile_id', $studentProfile->id)
                ->whereIn('lesson_id', $lessonIds)
                ->get()
                ->keyBy('lesson_id');

        $totalLessons = (int) $modules->sum(
            fn ($module) => $module->lessons->count()
        );

        $completedLessons = $progressByLesson
            ->where('status', LessonProgress::STATUS_COMPLETED)
            ->count();

        $inProgressLessons = $progressByLesson
            ->where('status', LessonProgress::STATUS_IN_PROGRESS)
            ->count();

        $completionPercentage = $totalLessons > 0
            ? round(($completedLessons / $totalLessons) * 100, 1)
            : 0;

        return new LearningOverviewResource([
            'course' => $course,
            'modules' => $modules,
            'progress_by_lesson' => $progressByLesson,
            'progress_summary' => [
                'total_lessons' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'in_progress_lessons' => $inProgressLessons,
                'not_started_lessons' => max(
                    0,
                    $totalLessons - $completedLessons - $inProgressLessons
                ),
                'completion_percentage' => $completionPercentage,
            ],
        ]);
    }

    private function currentCenter(Request $request): Center
    {
        $center = $request->attributes->get('current_center');

        abort_unless(
            $center instanceof Center,
            404,
            'Center not found.'
        );

        return $center;
    }

    private function resolveCourse(
        Center $center,
        mixed $courseParam
    ): Course {
        $courseParam = (string) $courseParam;

        $query = Course::query()
            ->where('center_id', $center->id);

        if (ctype_digit($courseParam)) {
            $query->whereKey((int) $courseParam);
        } else {
            $query->where('slug', $courseParam);
        }

        return $query->firstOrFail();
    }

    private function resolveStudentProfile(
        Request $request,
        Center $center
    ): StudentProfile {
        $profile = StudentProfile::query()
            ->forCenter($center->id)
            ->where('user_id', $request->user()->id)
            ->active()
            ->first();

        abort_unless(
            $profile instanceof StudentProfile,
            403,
            'Active student profile required.'
        );

        return $profile;
    }
}
