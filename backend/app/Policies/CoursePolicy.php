<?php

namespace App\Policies;

use App\Models\Center;
use App\Models\Course;
use App\Models\User;
use App\Services\Learning\LearningAccessService;

class CoursePolicy
{
    public function __construct(
        protected LearningAccessService $learningAccess
    ) {}

    /**
     * Determine whether a student may access the learning area of a course.
     */
    public function viewLearning(
        User $user,
        Course $course,
        Center $center
    ): bool {
        return $this->learningAccess->canAccessCourse(
            $user,
            $course,
            $center
        );
    }
}
