<?php

namespace App\Policies;

use App\Models\Center;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Learning\LearningAccessService;

class LessonPolicy
{
    public function __construct(
        protected LearningAccessService $learningAccess
    ) {}

    /**
     * Determine whether a student may access this lesson in the given course.
     */
    public function viewLearning(
        User $user,
        Lesson $lesson,
        Course $course,
        Center $center
    ): bool {
        return $this->learningAccess->canAccessLesson(
            $user,
            $lesson,
            $course,
            $center
        );
    }
}
