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
     * Resolve an active student profile for the requested center.
     *
     * A platform administrator or center administrator does not gain
     * student access automatically. The user must have an active
     * student membership and an active student profile in this center.
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
     * Determine whether the student has a current enrollment in this course.
     */
    public function canAccessCourse(
        User $user,
        Course $course,
        Center $center
    ): bool {
        if (
            (int) $course->center_id !== (int) $center->id
            || ! $course->isPublished()
        ) {
            return false;
        }

        $studentProfile = $this->findActiveStudentProfile($user, $center);

        if (! $studentProfile) {
            return false;
        }

        return Enrollment::query()
            ->forCenter($center->id)
            ->forStudentProfile($studentProfile->id)
            ->activeAt()
            ->whereHas('cohort', static function (Builder $query) use ($center, $course): void {
                $query
                    ->where('center_id', $center->id)
                    ->where('course_id', $course->id)
                    ->whereIn('status', [
                        Cohort::STATUS_OPEN,
                        Cohort::STATUS_IN_PROGRESS,
                        Cohort::STATUS_COMPLETED,
                    ]);
            })
            ->exists();
    }

    /**
     * Determine whether the student may access a published lesson
     * contained in the requested course and center.
     */
    public function canAccessLesson(
        User $user,
        Lesson $lesson,
        Course $course,
        Center $center
    ): bool {
        if (
            ! $this->canAccessCourse($user, $course, $center)
            || (int) $lesson->center_id !== (int) $center->id
            || ! $lesson->isPublished()
        ) {
            return false;
        }

        return $lesson->courseModule()
            ->where('center_id', $center->id)
            ->where('course_id', $course->id)
            ->published()
            ->exists();
    }
}
