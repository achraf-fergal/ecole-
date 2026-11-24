<?php

namespace App\Services\Learning;

use App\Models\Center;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\ExerciseAttemptAnswer;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonRevision;
use App\Models\Question;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExerciseAttemptService
{
    public function __construct(
        protected LearningAccessService $learningAccess,
        protected ExerciseAnswerGrader $answerGrader
    ) {}

    /**
     * Resolve and strictly authorize student access to an exercise.
     * Enforces active student role, active student profile, and actual course enrollment.
     *
     * @return array{0: Exercise, 1: StudentProfile}
     */
    public function resolveAuthorizedExercise(
        User $user,
        Center $center,
        Course $course,
        Lesson $lesson,
        int $exerciseId
    ): array {
        abort_unless(
            $user->isActive() && $center->isActive(),
            403,
            'User or center is inactive.'
        );

        abort_unless(
            (int) $course->center_id === (int) $center->id
                && (int) $lesson->center_id === (int) $center->id,
            404,
            'Resource not found for this center.'
        );

        abort_unless(
            $course->isPublished() && $lesson->isPublished(),
            404,
            'Course or lesson is not published.'
        );

        $module = $lesson->courseModule()
            ->where('center_id', $center->id)
            ->where('course_id', $course->id)
            ->published()
            ->first();

        abort_unless(
            $module !== null,
            404,
            'Published lesson module not found.'
        );

        // Every assessment attempt requires a student role in this center.
        abort_unless(
            $user->hasRoleInCenter($center, 'student'),
            403,
            'Student assessment requires a student role in this center.'
        );

        // Active student profile is mandatory.
        $studentProfile = StudentProfile::query()
            ->where('center_id', $center->id)
            ->where('user_id', $user->id)
            ->where('status', StudentProfile::STATUS_ACTIVE)
            ->first();

        abort_unless(
            $studentProfile instanceof StudentProfile,
            403,
            'Active student profile required.'
        );

        // Active course enrollment is mandatory, even for dual-role users or super admins.
        $hasActiveEnrollment = Enrollment::query()
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

        abort_unless(
            $hasActiveEnrollment,
            403,
            'Active course enrollment required.'
        );

        // Lesson must have a valid current published revision.
        $publishedRevision = $lesson->publishedRevision;

        abort_unless(
            $publishedRevision !== null
                && $publishedRevision->isPublished()
                && (int) $publishedRevision->center_id === (int) $center->id
                && (int) $publishedRevision->lesson_id === (int) $lesson->id,
            404,
            'Published lesson content not found.'
        );

        // Exercise must belong to the exact center and lesson, and be published.
        $exercise = Exercise::query()
            ->forCenter($center->id)
            ->where('lesson_id', $lesson->id)
            ->whereKey($exerciseId)
            ->first();

        abort_unless(
            $exercise instanceof Exercise && $exercise->isPublished(),
            404,
            'Exercise not found or not published.'
        );

        // The published revision must contain an ExerciseBlock referencing this exact exercise.
        $blockExists = LessonBlock::query()
            ->where('center_id', $center->id)
            ->where('lesson_id', $lesson->id)
            ->where('lesson_revision_id', $publishedRevision->id)
            ->where('block_type', LessonBlock::TYPE_EXERCISE)
            ->where('exercise_id', $exercise->id)
            ->exists();

        abort_unless(
            $blockExists,
            404,
            'Exercise is not included in the published lesson.'
        );

        return [$exercise, $studentProfile];
    }

    /**
     * Start a new attempt or resume an active in-progress attempt.
     * Revalidates lesson publication and enrollment under locks before creating an attempt.
     */
    public function startOrResumeAttempt(
        User $user,
        Course $course,
        Lesson $lesson,
        Exercise $exercise,
        StudentProfile $studentProfile,
        Center $center
    ): ExerciseAttempt {
        return DB::transaction(function () use (
            $user,
            $course,
            $lesson,
            $exercise,
            $studentProfile,
            $center
        ): ExerciseAttempt {
            // Match publication and exercise-authoring lock order: Lesson -> Exercise.
            $lockedLesson = Lesson::query()
                ->forCenter($center->id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $user->isActive()
                    && $center->isActive()
                    && $lockedLesson->isPublished()
                    && (int) $lockedLesson->center_id === (int) $center->id,
                404,
                'Published lesson not found.'
            );

            $lockedCourse = Course::query()
                ->forCenter($center->id)
                ->whereKey($course->id)
                ->firstOrFail();

            abort_unless(
                $lockedCourse->isPublished()
                    && $lockedLesson->courseModule()
                        ->where('center_id', $center->id)
                        ->where('course_id', $lockedCourse->id)
                        ->published()
                        ->exists(),
                404,
                'Published course lesson not found.'
            );

            $lockedExercise = Exercise::query()
                ->forCenter($center->id)
                ->where('lesson_id', $lockedLesson->id)
                ->whereKey($exercise->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedExercise->isPublished(),
                404,
                'Exercise not found or not published.'
            );

            $publishedRevision = $lockedLesson->published_revision_id === null
                ? null
                : LessonRevision::query()
                    ->forCenter($center->id)
                    ->forLesson($lockedLesson->id)
                    ->whereKey($lockedLesson->published_revision_id)
                    ->where('status', LessonRevision::STATUS_PUBLISHED)
                    ->first();

            abort_unless(
                $publishedRevision !== null
                    && LessonBlock::query()
                        ->where('center_id', $center->id)
                        ->where('lesson_id', $lockedLesson->id)
                        ->where('lesson_revision_id', $publishedRevision->id)
                        ->where('block_type', LessonBlock::TYPE_EXERCISE)
                        ->where('exercise_id', $lockedExercise->id)
                        ->exists(),
                404,
                'Exercise is not included in the current published lesson.'
            );

            abort_unless(
                $user->hasRoleInCenter($center, 'student'),
                403,
                'Student assessment requires a student role in this center.'
            );

            // Lock the student parent row after lesson and exercise, matching the
            // serialization order used by lesson publication and exercise edits.
            $lockedStudentProfile = StudentProfile::query()
                ->where('center_id', $center->id)
                ->where('user_id', $user->id)
                ->where('status', StudentProfile::STATUS_ACTIVE)
                ->whereKey($studentProfile->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasActiveEnrollment = Enrollment::query()
                ->forCenter($center->id)
                ->forStudentProfile($lockedStudentProfile->id)
                ->activeAt()
                ->whereHas(
                    'cohort',
                    static function (Builder $query) use ($center, $lockedCourse): void {
                        $query
                            ->where('center_id', $center->id)
                            ->where('course_id', $lockedCourse->id)
                            ->whereIn('status', [
                                Cohort::STATUS_OPEN,
                                Cohort::STATUS_IN_PROGRESS,
                                Cohort::STATUS_COMPLETED,
                            ]);
                    }
                )
                ->exists();

            abort_unless(
                $hasActiveEnrollment,
                403,
                'Active course enrollment required.'
            );

            // Lock active in-progress attempt if present.
            $activeAttempt = ExerciseAttempt::query()
                ->where('center_id', $center->id)
                ->where('student_profile_id', $lockedStudentProfile->id)
                ->where('exercise_id', $lockedExercise->id)
                ->where('status', ExerciseAttempt::STATUS_IN_PROGRESS)
                ->lockForUpdate()
                ->first();

            if ($activeAttempt) {
                return $activeAttempt->load('answers');
            }

            // Check maximum attempts limit.
            if ($lockedExercise->max_attempts !== null) {
                $completedCount = ExerciseAttempt::query()
                    ->where('center_id', $center->id)
                    ->where('student_profile_id', $lockedStudentProfile->id)
                    ->where('exercise_id', $lockedExercise->id)
                    ->whereIn('status', [
                        ExerciseAttempt::STATUS_SUBMITTED,
                        ExerciseAttempt::STATUS_GRADED,
                    ])
                    ->count();

                if ($completedCount >= (int) $lockedExercise->max_attempts) {
                    throw ValidationException::withMessages([
                        'attempt' => ['Maximum number of attempts reached for this exercise.'],
                    ]);
                }
            }

            // Calculate next sequential attempt number with lock.
            $maxAttemptNumber = ExerciseAttempt::query()
                ->where('center_id', $center->id)
                ->where('student_profile_id', $lockedStudentProfile->id)
                ->where('exercise_id', $lockedExercise->id)
                ->lockForUpdate()
                ->max('attempt_number');

            $nextAttemptNumber = ($maxAttemptNumber !== null) ? ((int) $maxAttemptNumber + 1) : 1;

            $questions = $lockedExercise->questions()
                ->with('options')
                ->get();

            if ($questions->isEmpty()) {
                throw ValidationException::withMessages([
                    'exercise' => ['This exercise has no questions.'],
                ]);
            }

            $maxScore = $questions->sum(fn ($q) => (float) $q->points);

            foreach ($questions as $question) {
                $configurationErrors = $question->optionConfigurationErrors();
                if ($configurationErrors !== []) {
                    throw ValidationException::withMessages([
                        'exercise' => ["Question #{$question->id} is not configured correctly."],
                    ]);
                }

                if ($question->question_type === Question::TYPE_SHORT_ANSWER) {
                    $configurationErrors = $this->answerGrader->configurationErrors(
                        $question->answer_config,
                        true
                    );
                    if ($configurationErrors !== []) {
                        throw ValidationException::withMessages([
                            'exercise' => ["Question #{$question->id} is not configured correctly."],
                        ]);
                    }
                }
            }

            $attempt = ExerciseAttempt::query()->create([
                'center_id' => $center->id,
                'exercise_id' => $lockedExercise->id,
                'student_profile_id' => $lockedStudentProfile->id,
                'attempt_number' => $nextAttemptNumber,
                'status' => ExerciseAttempt::STATUS_IN_PROGRESS,
                'passing_score_snapshot' => $lockedExercise->passing_score,
                'show_correct_answers_after_submission_snapshot' => (bool) $lockedExercise->show_correct_answers_after_submission,
                'score' => null,
                'max_score' => $maxScore,
                'percentage' => null,
                'passed' => null,
                'started_at' => now(),
            ]);

            foreach ($questions as $question) {
                $correctOptionIds = $question->options
                    ->where('is_correct', true)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                $correctOptionId = count($correctOptionIds) === 1
                    ? $correctOptionIds[0]
                    : null;

                $optionsSnapshot = $question->options->map(
                    fn ($opt): array => [
                        'id' => $opt->id,
                        'option_text' => $opt->option_text,
                        'sort_order' => $opt->sort_order,
                    ]
                )->values()->all();

                ExerciseAttemptAnswer::query()->create([
                    'center_id' => $center->id,
                    'exercise_id' => $lockedExercise->id,
                    'exercise_attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'selected_option_id' => null,
                    'selected_option_ids' => null,
                    'text_answer' => null,
                    'correct_option_id_snapshot' => $correctOptionId,
                    'correct_option_ids_snapshot' => $correctOptionIds,
                    'answer_config_snapshot' => $question->question_type === Question::TYPE_SHORT_ANSWER
                        ? $question->answer_config
                        : null,
                    'question_type_snapshot' => $question->question_type,
                    'prompt_snapshot' => $question->prompt,
                    'explanation_snapshot' => $question->explanation,
                    'options_snapshot' => $optionsSnapshot,
                    'points_possible' => $question->points,
                    'is_correct' => null,
                    'points_awarded' => null,
                ]);
            }

            return $attempt->load('answers');
        });
    }

    /**
     * Save answers for an in-progress attempt.
     */
    public function saveAnswers(
        ExerciseAttempt $attempt,
        array $answers
    ): ExerciseAttempt {
        return DB::transaction(function () use ($attempt, $answers): ExerciseAttempt {
            $lockedAttempt = ExerciseAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedAttempt->isInProgress()) {
                throw ValidationException::withMessages([
                    'attempt' => ['Cannot save answers for an attempt that is already completed.'],
                ]);
            }

            $attemptAnswers = $lockedAttempt->answers()->get();
            $seenQuestionIds = [];

            foreach ($answers as $entry) {
                if (! isset($entry['question_id']) || ! is_numeric($entry['question_id'])) {
                    throw ValidationException::withMessages([
                        'answers' => ['Each answer must provide a valid question ID.'],
                    ]);
                }

                $questionId = (int) $entry['question_id'];

                if (in_array($questionId, $seenQuestionIds, true)) {
                    throw ValidationException::withMessages([
                        'answers' => ["Duplicate answer for Question ID {$questionId} in payload."],
                    ]);
                }
                $seenQuestionIds[] = $questionId;

                $answerRecord = $attemptAnswers->firstWhere('question_id', $questionId);

                if (! $answerRecord) {
                    throw ValidationException::withMessages([
                        'answers' => ["Question ID {$questionId} does not belong to this attempt."],
                    ]);
                }

                $answerRecord->update(
                    $this->answerValuesFromEntry($answerRecord, $entry)
                );
            }

            return $lockedAttempt->fresh('answers');
        });
    }

    /**
     * Submit and grade an attempt atomically.
     */
    public function submitAttempt(
        ExerciseAttempt $attempt,
        array $answers = [],
        bool $answersWereProvided = true
    ): ExerciseAttempt {
        return DB::transaction(function () use ($attempt, $answers, $answersWereProvided): ExerciseAttempt {
            $lockedAttempt = ExerciseAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fingerprint = $this->submissionFingerprint($answers, $answersWereProvided);

            if (! $lockedAttempt->isInProgress()) {
                if (
                    $lockedAttempt->submission_fingerprint !== null
                    && hash_equals((string) $lockedAttempt->submission_fingerprint, $fingerprint)
                ) {
                    return $lockedAttempt->fresh('answers');
                }

                if (
                    $lockedAttempt->submission_fingerprint === null
                    && ! $answersWereProvided
                ) {
                    return $lockedAttempt->fresh('answers');
                }

                abort(409, 'This attempt was already submitted with different answers.');
            }

            if ($answersWereProvided && $answers !== []) {
                $this->saveAnswers($lockedAttempt, $answers);
            }

            $answersList = $lockedAttempt->answers()->get();

            $totalPointsPossible = 0.0;
            $totalPointsAwarded = 0.0;

            foreach ($answersList as $answer) {
                $pointsPossible = (float) $answer->points_possible;
                $totalPointsPossible += $pointsPossible;

                $isCorrect = $this->answerGrader->isCorrect($answer);

                $pointsAwarded = $isCorrect ? $pointsPossible : 0.0;
                $totalPointsAwarded += $pointsAwarded;

                $answer->update([
                    'is_correct' => $isCorrect,
                    'points_awarded' => $pointsAwarded,
                ]);
            }

            $percentage = $totalPointsPossible > 0
                ? round(($totalPointsAwarded / $totalPointsPossible) * 100, 2)
                : 0.0;

            $passed = $percentage >= (float) $lockedAttempt->passing_score_snapshot;

            $now = now();

            $lockedAttempt->update([
                'status' => ExerciseAttempt::STATUS_GRADED,
                'score' => $totalPointsAwarded,
                'max_score' => $totalPointsPossible,
                'percentage' => $percentage,
                'passed' => $passed,
                'submitted_at' => $now,
                'graded_at' => $now,
                'submission_fingerprint' => $fingerprint,
            ]);

            return $lockedAttempt->fresh('answers');
        });
    }

    /**
     * Format an attempt for output according to the release policy.
     */
    public function formatAttemptPayload(
        ExerciseAttempt $attempt,
        bool $isAdmin = false
    ): array {
        $isFinished = $attempt->isFinished();
        $canRevealAnswers = $isAdmin || (
            $isFinished && (bool) $attempt->show_correct_answers_after_submission_snapshot
        );

        return [
            'id' => $attempt->id,
            'exercise_id' => $attempt->exercise_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status,
            'passing_score' => (float) $attempt->passing_score_snapshot,
            'max_score' => (float) $attempt->max_score,
            'score' => $isFinished ? (float) $attempt->score : null,
            'percentage' => $isFinished ? (float) $attempt->percentage : null,
            'passed' => $isFinished ? (bool) $attempt->passed : null,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'graded_at' => $attempt->graded_at?->toIso8601String(),
            'show_correct_answers' => $canRevealAnswers,
            'questions' => $attempt->answers->map(function (ExerciseAttemptAnswer $answer) use ($canRevealAnswers): array {
                $data = [
                    'id' => $answer->question_id,
                    'question_type' => $answer->question_type_snapshot,
                    'prompt' => $answer->prompt_snapshot,
                    'points_possible' => (float) $answer->points_possible,
                    'selected_option_id' => $answer->selected_option_id,
                    'selected_option_ids' => $answer->selected_option_ids,
                    'text_answer' => $answer->text_answer,
                    'options' => $answer->options_snapshot,
                ];

                if ($canRevealAnswers) {
                    $data['is_correct'] = $answer->is_correct;
                    $data['points_awarded'] = (float) $answer->points_awarded;
                    $data['correct_option_id'] = $answer->correct_option_id_snapshot;
                    $data['correct_option_ids'] = $answer->correct_option_ids_snapshot ?? [];
                    if ($answer->question_type_snapshot === Question::TYPE_SHORT_ANSWER) {
                        $data['accepted_answers'] = $answer->answer_config_snapshot['accepted_answers'] ?? [];
                    }
                    $data['explanation'] = $answer->explanation_snapshot;
                }

                return $data;
            })->values()->all(),
        ];
    }

    /** Validate a type-specific entry against the immutable attempt snapshot. */
    private function answerValuesFromEntry(
        ExerciseAttemptAnswer $answer,
        array $entry
    ): array {
        $allowedFields = match ($answer->question_type_snapshot) {
            Question::TYPE_SINGLE_CHOICE,
            Question::TYPE_TRUE_FALSE => ['question_id', 'selected_option_id'],
            Question::TYPE_MULTIPLE_SELECT => ['question_id', 'selected_option_ids'],
            Question::TYPE_SHORT_ANSWER => ['question_id', 'text_answer'],
            default => ['question_id'],
        };

        if (array_diff(array_keys($entry), $allowedFields) !== []) {
            throw ValidationException::withMessages([
                'answers' => ["Answer payload contains fields not allowed for Question ID {$answer->question_id}."],
            ]);
        }

        $validOptionIds = array_map(
            'intval',
            array_column($answer->options_snapshot ?? [], 'id')
        );

        if (in_array($answer->question_type_snapshot, [Question::TYPE_SINGLE_CHOICE, Question::TYPE_TRUE_FALSE], true)) {
            $selectedOptionId = array_key_exists('selected_option_id', $entry)
                && $entry['selected_option_id'] !== null
                ? (int) $entry['selected_option_id']
                : null;

            if ($selectedOptionId !== null && ! in_array($selectedOptionId, $validOptionIds, true)) {
                throw ValidationException::withMessages([
                    'answers' => ["Option ID {$selectedOptionId} is not a valid option for Question ID {$answer->question_id}."],
                ]);
            }

            return [
                'selected_option_id' => $selectedOptionId,
                'selected_option_ids' => null,
                'text_answer' => null,
            ];
        }

        if ($answer->question_type_snapshot === Question::TYPE_MULTIPLE_SELECT) {
            $selectedOptionIds = $entry['selected_option_ids'] ?? [];
            if (! is_array($selectedOptionIds) || ! array_is_list($selectedOptionIds)) {
                throw ValidationException::withMessages([
                    'answers' => ['Multiple-select answers must be a list of option IDs.'],
                ]);
            }

            $normalizedIds = array_map('intval', $selectedOptionIds);
            if (count(array_unique($normalizedIds)) !== count($normalizedIds)) {
                throw ValidationException::withMessages([
                    'answers' => ["Duplicate option IDs are not allowed for Question ID {$answer->question_id}."],
                ]);
            }

            foreach ($normalizedIds as $optionId) {
                if (! in_array($optionId, $validOptionIds, true)) {
                    throw ValidationException::withMessages([
                        'answers' => ["Option ID {$optionId} is not a valid option for Question ID {$answer->question_id}."],
                    ]);
                }
            }

            sort($normalizedIds, SORT_NUMERIC);

            return [
                'selected_option_id' => null,
                'selected_option_ids' => $normalizedIds,
                'text_answer' => null,
            ];
        }

        $textAnswer = $entry['text_answer'] ?? null;
        if ($textAnswer !== null && (! is_string($textAnswer) || mb_strlen($textAnswer, 'UTF-8') > ExerciseAnswerGrader::MAX_STUDENT_ANSWER_LENGTH)) {
            throw ValidationException::withMessages([
                'answers' => ['Short answers may not exceed 5000 characters.'],
            ]);
        }

        return [
            'selected_option_id' => null,
            'selected_option_ids' => null,
            'text_answer' => $textAnswer,
        ];
    }

    /** Build a stable request fingerprint so retries do not apply grading twice. */
    private function submissionFingerprint(array $answers, bool $answersWereProvided): string
    {
        $normalizedAnswers = [];

        foreach ($answers as $entry) {
            $normalized = ['question_id' => (int) ($entry['question_id'] ?? 0)];

            if (array_key_exists('selected_option_id', $entry)) {
                $normalized['selected_option_id'] = $entry['selected_option_id'] === null
                    ? null
                    : (int) $entry['selected_option_id'];
            }

            if (array_key_exists('selected_option_ids', $entry)) {
                $ids = array_map('intval', $entry['selected_option_ids']);
                sort($ids, SORT_NUMERIC);
                $normalized['selected_option_ids'] = $ids;
            }

            if (array_key_exists('text_answer', $entry)) {
                $normalized['text_answer'] = $entry['text_answer'];
            }

            $normalizedAnswers[] = $normalized;
        }

        usort(
            $normalizedAnswers,
            static fn (array $left, array $right): int => $left['question_id'] <=> $right['question_id']
        );

        return hash('sha256', json_encode([
            'answers_provided' => $answersWereProvided,
            'answers' => $normalizedAnswers,
        ], JSON_THROW_ON_ERROR));
    }
}
