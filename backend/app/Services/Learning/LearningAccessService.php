<?php

namespace App\Services\Learning;

use App\Models\Center;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LearningAccessService
{
    /**
     * Determine whether the user can preview unpublished content.
     *
     * Platform admins have global access.
     * Center admins are restricted to their active center membership.
     */
    public function isAdministrativeViewer(
        User $user,
        Center $center
    ): bool {
        if (! $user->isActive() || ! $center->isActive()) {
            return false;
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $user->hasRoleInCenter(
            $center,
            'center_admin'
        );
    }

    /**
     * Resolve an active student profile for a center.
     */
    public function findActiveStudentProfile(
        User $user,
        Center $center
    ): ?StudentProfile {
        if (! $user->isActive() || ! $center->isActive()) {
            return null;
        }

        $hasActiveStudentMembership = $user->centerMemberships()
            ->where('center_id', $center->id)
            ->where('status', 'active')
            ->whereHas('roles', static function (Builder $query): void {
                $query->where('key', 'student');
            })
            ->exists();

        if (! $hasActiveStudentMembership) {
            return null;
        }

        return StudentProfile::query()
            ->where('center_id', $center->id)
            ->where('user_id', $user->id)
            ->where('status', StudentProfile::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Determine whether a user may access a course.
     *
     * Administrators can preview every course status.
     * Students require a published course and active enrollment.
     */
    public function canAccessCourse(
        User $user,
        Course $course,
        Center $center
    ): bool {
        if (
            ! $user->isActive()
            || ! $center->isActive()
            || (int) $course->center_id !== (int) $center->id
        ) {
            return false;
        }

        if ($this->isAdministrativeViewer($user, $center)) {
            return true;
        }

        if (! $course->isPublished()) {
            return false;
        }

        $studentProfile = $this->findActiveStudentProfile(
            $user,
            $center
        );

        if (! $studentProfile) {
            return false;
        }

        return Enrollment::query()
            ->forCenter($center->id)
            ->forStudentProfile($studentProfile->id)
            ->activeAt()
            ->whereHas(
                'cohort',
                static function (Builder $query) use ($center, $course): void {
                    $query
                        ->where('center_id', $center->id)
                        ->where('course_id', $course->id)
                        ->whereIn('status', [
                            Cohort::STATUS_OPEN,
                            Cohort::STATUS_IN_PROGRESS,
                            Cohort::STATUS_COMPLETED,
                        ]);
                }
            )
            ->exists();
    }

    /**
     * Determine whether a user may access a lesson.
     *
     * Administrators can preview unpublished lessons and modules,
     * provided that they belong to the requested course and center.
     */
    public function canAccessLesson(
        User $user,
        Lesson $lesson,
        Course $course,
        Center $center
    ): bool {
        if (
            ! $user->isActive()
            || ! $center->isActive()
            || (int) $course->center_id !== (int) $center->id
            || (int) $lesson->center_id !== (int) $center->id
        ) {
            return false;
        }

        $moduleQuery = $lesson->courseModule()
            ->where('center_id', $center->id)
            ->where('course_id', $course->id);

        // Administrators can inspect draft and archived course content.
        if ($this->isAdministrativeViewer($user, $center)) {
            return $moduleQuery->exists();
        }

        // Students can only access published learning content.
        if (
            ! $this->canAccessCourse($user, $course, $center)
            || ! $lesson->isPublished()
        ) {
            return false;
        }

        return $moduleQuery
            ->published()
            ->exists();
    }
}