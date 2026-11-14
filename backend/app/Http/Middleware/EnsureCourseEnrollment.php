<?php

namespace App\Http\Middleware;

use App\Models\Center;
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

        // Super admins have global access.
        if ($user->isPlatformAdmin()) {
            return $next($request);
        }

        /** @var Center|null $center */
        $center = $request->attributes->get('current_center') ?? $request->route('center');

        if (! $center instanceof Center) {
            return response()->json([
                'message' => 'Center context missing.',
            ], 400);
        }

        // Center admins have administrative course access.
        if ($user->hasRoleInCenter($center, 'center_admin')) {
            return $next($request);
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
            ->whereHas('cohort', function ($query) use ($course) {
                $query->where('course_id', $course->id);
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
