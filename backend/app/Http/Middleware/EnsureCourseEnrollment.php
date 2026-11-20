<?php

namespace App\Http\Middleware;

use App\Models\Center;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCourseEnrollment
{
    /**
     * Handle an incoming request.
     * Enforces active course enrollment for student course player access.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /** @var Center|null $center */
        $center = $request->attributes->get('current_center') ?? $request->route('center');

        if (! $center instanceof Center) {
            return response()->json([
                'message' => 'Center context missing.',
            ], 400);
        }

        $isAssessmentRoute = $request->routeIs('*attempts*') || str_contains($request->path(), '/attempts');

        // Administrative preview bypass is only allowed for general course content preview, never for assessment attempts.
        if (! $isAssessmentRoute) {
            // Super admins have global access for general course preview.
            if ($user->isPlatformAdmin()) {
                return $next($request);
            }

            // Center admins have administrative course preview access.
            if ($user->hasRoleInCenter($center, 'center_admin')) {
                return $next($request);
            }
        }

        // Resolve student profile.
        $studentProfile = StudentProfile::where('center_id', $center->id)
            ->where('user_id', $user->id)
            ->where('status', StudentProfile::STATUS_ACTIVE)
            ->first();

        if (! $studentProfile) {
            return response()->json([
                'message' => 'Active student profile required.',
            ], 403);
        }

        // Check active enrollment.
        $courseParam = $request->route('course');
        $course = $courseParam instanceof Course
            ? $courseParam
            : Course::where('center_id', $center->id)
                ->where(function ($query) use ($courseParam) {
                    $query->where('slug', (string) $courseParam)
                        ->orWhere('id', (int) $courseParam);
                })
                ->first();

        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $hasActiveEnrollment = Enrollment::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->whereHas('cohort', function ($query) use ($center, $course) {
                $query->where('center_id', $center->id)
                    ->where('course_id', $course->id)
                    ->whereIn('status', [
                        Cohort::STATUS_OPEN,
                        Cohort::STATUS_IN_PROGRESS,
                        Cohort::STATUS_COMPLETED,
                    ]);
            })
            ->activeAt()
            ->exists();

        if (! $hasActiveEnrollment) {
            return response()->json([
                'message' => 'Active course enrollment required.',
            ], 403);
        }

        return $next($request);
    }
}
