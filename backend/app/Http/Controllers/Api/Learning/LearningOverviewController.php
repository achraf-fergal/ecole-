<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Learning\LearningOverviewResource;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\LessonProgress;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\LearningAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LearningOverviewController extends Controller
{
    public function __construct(
        protected LearningAccessService $learningAccess
    ) {}

    public function __invoke(Request $request): LearningOverviewResource
    {
        $center = $this->currentCenter($request);

        $course = $this->resolveCourse(
            $center,
            $request->route('course')
        );

        Gate::authorize('viewLearning', [$course, $center]);

        $isAdminPreview = $this->learningAccess
            ->isAdministrativeViewer(
                $request->user(),
                $center
            );

        $studentProfile = $isAdminPreview
            ? null
            : $this->resolveStudentProfile($request, $center);

        // Students receive published content only.
        // Administrators receive all module and lesson statuses.
        $modulesQuery = CourseModule::query()
            ->forCenter($center->id)
            ->where('course_id', $course->id);

        if (! $isAdminPreview) {
            $modulesQuery->published();
        }

        $modules = $modulesQuery
            ->with([
                'lessons' => function ($query) use (
                    $center,
                    $isAdminPreview
                ): void {
                    $query->forCenter($center->id);

                    if (! $isAdminPreview) {
                        $query->published();
                    }
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

        // Administrator previews never load or expose student progress.
        $progressByLesson = collect();

        if (! $isAdminPreview && $studentProfile !== null && $lessonIds->isNotEmpty()) {
            $progressByLesson = LessonProgress::query()
                ->where('center_id', $center->id)
                ->where('student_profile_id', $studentProfile->id)
                ->whereIn('lesson_id', $lessonIds)
                ->get()
                ->keyBy('lesson_id');
        }

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
            'access_mode' => $isAdminPreview
                ? 'admin_preview'
                : 'student',
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
        $profile = $this->learningAccess->findActiveStudentProfile(
            $request->user(),
            $center
        );

        abort_unless(
            $profile instanceof StudentProfile,
            403,
            'Active student profile required.'
        );

        return $profile;
    }
}