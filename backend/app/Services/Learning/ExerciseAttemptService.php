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

            $maxScoreMinorUnits = 0;
            foreach ($questions as $question) {
                $maxScoreMinorUnits += $this->decimalToMinorUnits($question->points);
            }

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
                } elseif ($question->question_type === Question::TYPE_FILL_IN_THE_BLANKS) {
                    $configurationErrors = $this->answerGrader->blankConfigurationErrors(
                        $question->answer_config,
                        true
                    );
                    $placeholderErrors = $this->answerGrader->promptPlaceholderErrors(
                        (string) $question->prompt,
                        $question->answer_config
                    );
                    $blankTotalMinorUnits = $this->answerGrader->sumBlankPointsMinorUnits($question->answer_config);
                    $questionPointsMinorUnits = $this->decimalToMinorUnits($question->points);

                    if (
                        $configurationErrors !== []
                        || $placeholderErrors !== []
                        || $blankTotalMinorUnits <= 0
                        || $blankTotalMinorUnits !== $questionPointsMinorUnits
                    ) {
                        throw ValidationException::withMessages([
                            'exercise' => ["Question #{$question->id} is not configured correctly."],
                        ]);
                    }
                }
            }

            $resolvedResultSettings = $lockedExercise->resolvedResultSettings();

            $attempt = ExerciseAttempt::query()->create([
                'center_id' => $center->id,
                'exercise_id' => $lockedExercise->id,
                'student_profile_id' => $lockedStudentProfile->id,
                'attempt_number' => $nextAttemptNumber,
                'status' => ExerciseAttempt::STATUS_IN_PROGRESS,
                'passing_score_snapshot' => $lockedExercise->passing_score,
                'max_attempts_snapshot' => $lockedExercise->max_attempts,
                'show_correct_answers_after_submission_snapshot' => (bool) $resolvedResultSettings['show_correct_answers'],
                'result_settings_snapshot' => $resolvedResultSettings,
                'score' => null,
                'max_score' => $this->minorUnitsToDecimalString($maxScoreMinorUnits),
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
                    'answer_config_snapshot' => in_array($question->question_type, [Question::TYPE_SHORT_ANSWER, Question::TYPE_FILL_IN_THE_BLANKS], true)
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

            $totalPointsPossibleMinorUnits = 0;
            $totalPointsAwardedMinorUnits = 0;

            foreach ($answersList as $answer) {
                $pointsPossibleMinorUnits = $this->decimalToMinorUnits($answer->points_possible);
                $totalPointsPossibleMinorUnits += $pointsPossibleMinorUnits;

                if ($answer->question_type_snapshot === Question::TYPE_FILL_IN_THE_BLANKS) {
                    $gradeResult = $this->answerGrader->gradeFillInTheBlanks($answer);
                    $isCorrect = $gradeResult['is_correct'];
                    $pointsAwardedMinorUnits = min(
                        max(0, (int) $gradeResult['points_awarded_minor_units']),
                        $pointsPossibleMinorUnits
                    );
                } else {
                    $isCorrect = $this->answerGrader->isCorrect($answer);
                    $pointsAwardedMinorUnits = $isCorrect ? $pointsPossibleMinorUnits : 0;
                }

                $totalPointsAwardedMinorUnits += $pointsAwardedMinorUnits;

                $answer->update([
                    'is_correct' => $isCorrect,
                    'points_awarded' => $this->minorUnitsToDecimalString($pointsAwardedMinorUnits),
                ]);
            }

            $percentageMinorUnits = $totalPointsPossibleMinorUnits > 0
                ? intdiv(
                    ($totalPointsAwardedMinorUnits * 10000)
                        + intdiv($totalPointsPossibleMinorUnits, 2),
                    $totalPointsPossibleMinorUnits
                )
                : 0;

            $passed = $percentageMinorUnits >= $this->decimalToMinorUnits(
                $lockedAttempt->passing_score_snapshot
            );

            $now = now();

            $lockedAttempt->update([
                'status' => ExerciseAttempt::STATUS_GRADED,
                'score' => $this->minorUnitsToDecimalString($totalPointsAwardedMinorUnits),
                'max_score' => $this->minorUnitsToDecimalString($totalPointsPossibleMinorUnits),
                'percentage' => $this->minorUnitsToDecimalString($percentageMinorUnits),
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
        $settings = $attempt->resolvedResultSettings();

        $resultsReleased = false;
        if ($isAdmin) {
            $resultsReleased = true;
        } elseif ($isFinished) {
            if (($settings['release_timing'] ?? Exercise::RELEASE_TIMING_IMMEDIATELY) === Exercise::RELEASE_TIMING_AFTER_FINAL_ATTEMPT) {
                $maxAttempts = $attempt->max_attempts_snapshot;
                $resultsReleased = $maxAttempts !== null && (
                    $attempt->attempt_number >= $maxAttempts
                    || ExerciseAttempt::query()
                        ->forCenter((int) $attempt->center_id)
                        ->forExercise((int) $attempt->exercise_id)
                        ->forStudent((int) $attempt->student_profile_id)
                        ->completed()
                        ->where('attempt_number', '>=', $maxAttempts)
                        ->exists()
                );
            } else {
                $resultsReleased = true;
            }
        }

        $showScore = $isAdmin || ($resultsReleased && (bool) $settings['show_score']);
        $showPercentage = $isAdmin || ($resultsReleased && (bool) $settings['show_percentage']);
        $showPassFail = $isAdmin || ($resultsReleased && (bool) $settings['show_pass_fail']);
        $showQuestionResults = $isAdmin || ($resultsReleased && (bool) $settings['show_question_results']);
        $showStudentAnswers = $isAdmin || ! $isFinished || ($resultsReleased && (bool) $settings['show_student_answers']);
        $showCorrectAnswers = $isAdmin || ($resultsReleased && (bool) $settings['show_question_results'] && (bool) $settings['show_correct_answers']);
        $showExplanations = $isAdmin || ($resultsReleased && (bool) $settings['show_question_results'] && (bool) $settings['show_explanations']);
        $showPointsPerQuestion = $isAdmin || ($resultsReleased && (bool) $settings['show_question_results'] && (bool) $settings['show_points_per_question']);

        $correctCount = 0;
        $incorrectCount = 0;
        $unansweredCount = 0;

        if ($isFinished) {
            foreach ($attempt->answers as $answer) {
                if ($this->isAnswerUnanswered($answer)) {
                    $unansweredCount += 1;
                } elseif ((bool) $answer->is_correct) {
                    $correctCount += 1;
                } else {
                    $incorrectCount += 1;
                }
            }
        }

        return [
            'id' => $attempt->id,
            'exercise_id' => $attempt->exercise_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status,
            'passing_score' => (! $isFinished || $showPassFail) ? (float) $attempt->passing_score_snapshot : null,
            'max_score' => (! $isFinished || $showScore) ? (float) $attempt->max_score : null,
            'score' => ($isFinished && $showScore) ? (float) $attempt->score : null,
            'percentage' => ($isFinished && $showPercentage) ? (float) $attempt->percentage : null,
            'passed' => ($isFinished && $showPassFail) ? (bool) $attempt->passed : null,
            'correct_count' => ($isFinished && $showQuestionResults) ? $correctCount : null,
            'incorrect_count' => ($isFinished && $showQuestionResults) ? $incorrectCount : null,
            'unanswered_count' => ($isFinished && $showQuestionResults) ? $unansweredCount : null,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'graded_at' => $attempt->graded_at?->toIso8601String(),
            'show_correct_answers' => $showCorrectAnswers,
            'result_visibility' => [
                'results_released' => $resultsReleased,
                'release_timing' => $settings['release_timing'],
                'show_score' => (bool) $settings['show_score'],
                'show_percentage' => (bool) $settings['show_percentage'],
                'show_pass_fail' => (bool) $settings['show_pass_fail'],
                'show_question_results' => (bool) $settings['show_question_results'],
                'show_student_answers' => (bool) $settings['show_student_answers'],
                'show_correct_answers' => (bool) $settings['show_correct_answers'],
                'show_explanations' => (bool) $settings['show_explanations'],
                'show_points_per_question' => (bool) $settings['show_points_per_question'],
            ],
            'questions' => $attempt->answers->map(function (ExerciseAttemptAnswer $answer) use (
                $isFinished,
                $showQuestionResults,
                $showStudentAnswers,
                $showCorrectAnswers,
                $showExplanations,
                $showPointsPerQuestion
            ): array {
                $data = [
                    'id' => $answer->question_id,
                    'question_type' => $answer->question_type_snapshot,
                    'prompt' => $answer->prompt_snapshot,
                    'points_possible' => (! $isFinished || $showPointsPerQuestion) ? (float) $answer->points_possible : null,
                    'options' => $answer->options_snapshot,
                ];

                if ($showStudentAnswers) {
                    $data['selected_option_id'] = $answer->selected_option_id;
                    $data['selected_option_ids'] = $answer->selected_option_ids;
                    $data['text_answer'] = $answer->text_answer;

                    if ($answer->question_type_snapshot === Question::TYPE_FILL_IN_THE_BLANKS) {
                        $decoded = $answer->text_answer !== null ? json_decode($answer->text_answer, true) : null;
                        $data['blank_answers'] = is_array($decoded) ? $decoded : new \stdClass();
                    }
                } else {
                    $data['selected_option_id'] = null;
                    $data['selected_option_ids'] = null;
                    $data['text_answer'] = null;
                    if ($answer->question_type_snapshot === Question::TYPE_FILL_IN_THE_BLANKS) {
                        $data['blank_answers'] = new \stdClass();
                    }
                }

                if ($showQuestionResults) {
                    $unanswered = $this->isAnswerUnanswered($answer);
                    $data['is_correct'] = $answer->is_correct;
                    $data['result_status'] = $unanswered
                        ? 'unanswered'
                        : ((bool) $answer->is_correct ? 'correct' : 'incorrect');

                    if ($showPointsPerQuestion) {
                        $data['points_awarded'] = (float) $answer->points_awarded;
                    }

                    if ($showCorrectAnswers) {
                        $data['correct_option_id'] = $answer->correct_option_id_snapshot;
                        $data['correct_option_ids'] = $answer->correct_option_ids_snapshot ?? [];
                        if ($answer->question_type_snapshot === Question::TYPE_SHORT_ANSWER) {
                            $data['accepted_answers'] = $answer->answer_config_snapshot['accepted_answers'] ?? [];
                        }
                    }

                    if ($answer->question_type_snapshot === Question::TYPE_FILL_IN_THE_BLANKS) {
                        $gradeResult = $this->answerGrader->gradeFillInTheBlanks($answer);
                        $data['blanks'] = array_map(
                            static function (array $blank) use ($showStudentAnswers, $showCorrectAnswers, $showPointsPerQuestion): array {
                                $item = [
                                    'id' => $blank['id'],
                                    'is_correct' => $blank['is_correct'],
                                ];
                                if ($showStudentAnswers) {
                                    $item['student_answer'] = $blank['student_answer'];
                                }
                                if ($showCorrectAnswers) {
                                    $item['accepted_answers'] = $blank['accepted_answers'];
                                }
                                if ($showPointsPerQuestion) {
                                    $item['points_awarded'] = $blank['points_awarded'];
                                    $item['points_possible'] = $blank['points_possible'];
                                }

                                return $item;
                            },
                            $gradeResult['blanks_feedback']
                        );
                    }
                }

                if ($showExplanations) {
                    $data['explanation'] = $answer->explanation_snapshot;
                }

                return $data;
            })->values()->all(),
        ];
    }

    private function isAnswerUnanswered(ExerciseAttemptAnswer $answer): bool
    {
        return match ($answer->question_type_snapshot) {
            Question::TYPE_SINGLE_CHOICE,
            Question::TYPE_TRUE_FALSE => $answer->selected_option_id === null,
            Question::TYPE_MULTIPLE_SELECT => ! is_array($answer->selected_option_ids) || $answer->selected_option_ids === [],
            Question::TYPE_SHORT_ANSWER => $answer->text_answer === null || trim($answer->text_answer) === '',
            Question::TYPE_FILL_IN_THE_BLANKS => $this->areAllBlanksEmpty($answer->text_answer),
            default => true,
        };
    }

    private function areAllBlanksEmpty(?string $textAnswer): bool
    {
        if ($textAnswer === null || trim($textAnswer) === '') {
            return true;
        }
        $decoded = json_decode($textAnswer, true);
        if (! is_array($decoded) || $decoded === []) {
            return true;
        }
        foreach ($decoded as $val) {
            if (is_string($val) && trim($val) !== '') {
                return false;
            }
        }

        return true;
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
            Question::TYPE_FILL_IN_THE_BLANKS => ['question_id', 'blank_answers', 'text_answer'],
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

        if ($answer->question_type_snapshot === Question::TYPE_FILL_IN_THE_BLANKS) {
            $snapshotBlanks = is_array($answer->answer_config_snapshot['blanks'] ?? null)
                ? $answer->answer_config_snapshot['blanks']
                : [];
            $validBlankIds = array_values(array_filter(
                array_map(
                    static fn (mixed $blank): string => is_array($blank) && is_string($blank['id'] ?? null)
                        ? trim($blank['id'])
                        : '',
                    $snapshotBlanks
                ),
                static fn (string $id): bool => $id !== ''
            ));

            $rawBlankAnswers = null;
            if (array_key_exists('blank_answers', $entry)) {
                $rawBlankAnswers = $entry['blank_answers'];
            } elseif (array_key_exists('text_answer', $entry) && $entry['text_answer'] !== null) {
                if (! is_string($entry['text_answer'])) {
                    throw ValidationException::withMessages([
                        'answers' => ["Blank answers for Question ID {$answer->question_id} must be valid JSON text."],
                    ]);
                }
                $decoded = json_decode($entry['text_answer'], true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    throw ValidationException::withMessages([
                        'answers' => ["Blank answers for Question ID {$answer->question_id} contain invalid JSON."],
                    ]);
                }
                $rawBlankAnswers = $decoded;
            }

            if (
                $rawBlankAnswers !== null
                && (! is_array($rawBlankAnswers) || ($rawBlankAnswers !== [] && array_is_list($rawBlankAnswers)))
            ) {
                throw ValidationException::withMessages([
                    'answers' => ["Blank answers for Question ID {$answer->question_id} must be an object of key-value pairs."],
                ]);
            }

            $sanitized = [];
            if (is_array($rawBlankAnswers)) {
                foreach ($rawBlankAnswers as $bId => $val) {
                    if (! is_string($bId)) {
                        throw ValidationException::withMessages([
                            'answers' => ["Blank IDs for Question ID {$answer->question_id} must be strings."],
                        ]);
                    }
                    if (! in_array($bId, $validBlankIds, true)) {
                        throw ValidationException::withMessages([
                            'answers' => ["Blank ID '{$bId}' is not a valid blank for Question ID {$answer->question_id}."],
                        ]);
                    }
                    if ($val !== null && ! is_string($val)) {
                        throw ValidationException::withMessages([
                            'answers' => ["Blank answer for blank '{$bId}' must be text."],
                        ]);
                    }
                    if (is_string($val) && mb_strlen($val, 'UTF-8') > ExerciseAnswerGrader::MAX_STUDENT_ANSWER_LENGTH) {
                        throw ValidationException::withMessages([
                            'answers' => ["Blank answer for blank '{$bId}' may not exceed 5000 characters."],
                        ]);
                    }
                    $sanitized[$bId] = is_string($val) ? $val : '';
                }
            }

            ksort($sanitized);

            return [
                'selected_option_id' => null,
                'selected_option_ids' => null,
                'text_answer' => $sanitized !== [] ? json_encode($sanitized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
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

            if (array_key_exists('blank_answers', $entry)) {
                $blanks = is_array($entry['blank_answers']) ? $entry['blank_answers'] : [];
                ksort($blanks);
                $normalized['blank_answers'] = $blanks;
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

    /** Convert a DECIMAL(..., 2) value to integer hundredths without float arithmetic. */
    private function decimalToMinorUnits(string|int|float $value): int
    {
        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }

        $decimal = trim((string) $value);
        if (! preg_match('/\A([+-]?)(\d+)(?:\.(\d{1,2}))?\z/', $decimal, $matches)) {
            throw new \UnexpectedValueException('Assessment scores must use a decimal value with at most two fraction digits.');
        }

        $whole = (int) $matches[2];
        $fraction = (int) str_pad($matches[3] ?? '', 2, '0');
        $minorUnits = ($whole * 100) + $fraction;

        return ($matches[1] ?? '') === '-' ? -$minorUnits : $minorUnits;
    }

    private function minorUnitsToDecimalString(int $minorUnits): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return $sign
            .intdiv($absolute, 100)
            .'.'
            .str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
