<?php

namespace App\Services\Learning;

use App\Models\Center;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\ExerciseAttemptAnswer;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExerciseAttemptService
{
    public function __construct(
        protected LearningAccessService $learningAccess
    ) {}

    /**
     * Resolve and strictly authorize student access to an exercise.
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

        $studentProfile = $this->learningAccess->findActiveStudentProfile(
            $user,
            $center
        );

        abort_unless(
            $studentProfile instanceof StudentProfile,
            403,
            'Active student profile required.'
        );

        abort_unless(
            $this->learningAccess->canAccessLesson($user, $lesson, $course, $center),
            403,
            'Unauthorized lesson access.'
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
     */
    public function startOrResumeAttempt(
        Exercise $exercise,
        StudentProfile $studentProfile,
        Center $center
    ): ExerciseAttempt {
        return DB::transaction(function () use (
            $exercise,
            $studentProfile,
            $center
        ): ExerciseAttempt {
            // Lock active in-progress attempt if present.
            $activeAttempt = ExerciseAttempt::query()
                ->where('center_id', $center->id)
                ->where('student_profile_id', $studentProfile->id)
                ->where('exercise_id', $exercise->id)
                ->where('status', ExerciseAttempt::STATUS_IN_PROGRESS)
                ->lockForUpdate()
                ->first();

            if ($activeAttempt) {
                return $activeAttempt->load('answers');
            }

            // Check maximum attempts limit.
            if ($exercise->max_attempts !== null) {
                $completedCount = ExerciseAttempt::query()
                    ->where('center_id', $center->id)
                    ->where('student_profile_id', $studentProfile->id)
                    ->where('exercise_id', $exercise->id)
                    ->whereIn('status', [
                        ExerciseAttempt::STATUS_SUBMITTED,
                        ExerciseAttempt::STATUS_GRADED,
                    ])
                    ->count();

                if ($completedCount >= (int) $exercise->max_attempts) {
                    throw ValidationException::withMessages([
                        'attempt' => ['Maximum number of attempts reached for this exercise.'],
                    ]);
                }
            }

            // Calculate next sequential attempt number.
            $maxAttemptNumber = ExerciseAttempt::query()
                ->where('center_id', $center->id)
                ->where('student_profile_id', $studentProfile->id)
                ->where('exercise_id', $exercise->id)
                ->lockForUpdate()
                ->max('attempt_number');

            $nextAttemptNumber = ($maxAttemptNumber !== null) ? ((int) $maxAttemptNumber + 1) : 1;

            $questions = $exercise->questions()
                ->with('options')
                ->get();

            if ($questions->isEmpty()) {
                throw ValidationException::withMessages([
                    'exercise' => ['This exercise has no questions.'],
                ]);
            }

            $maxScore = $questions->sum(fn ($q) => (float) $q->points);

            $attempt = ExerciseAttempt::query()->create([
                'center_id' => $center->id,
                'exercise_id' => $exercise->id,
                'student_profile_id' => $studentProfile->id,
                'attempt_number' => $nextAttemptNumber,
                'status' => ExerciseAttempt::STATUS_IN_PROGRESS,
                'passing_score_snapshot' => $exercise->passing_score,
                'show_correct_answers_after_submission_snapshot' => (bool) $exercise->show_correct_answers_after_submission,
                'score' => null,
                'max_score' => $maxScore,
                'percentage' => null,
                'passed' => null,
                'started_at' => now(),
            ]);

            foreach ($questions as $question) {
                $correctOption = $question->options->firstWhere('is_correct', true);

                if (! $correctOption) {
                    throw ValidationException::withMessages([
                        'exercise' => ["Question #{$question->id} has no correct answer configured."],
                    ]);
                }

                $optionsSnapshot = $question->options->map(
                    fn ($opt): array => [
                        'id' => $opt->id,
                        'option_text' => $opt->option_text,
                        'sort_order' => $opt->sort_order,
                    ]
                )->values()->all();

                ExerciseAttemptAnswer::query()->create([
                    'center_id' => $center->id,
                    'exercise_id' => $exercise->id,
                    'exercise_attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'selected_option_id' => null,
                    'correct_option_id_snapshot' => $correctOption->id,
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

            foreach ($answers as $entry) {
                $questionId = (int) ($entry['question_id'] ?? 0);
                $selectedOptionId = isset($entry['selected_option_id']) && $entry['selected_option_id'] !== null
                    ? (int) $entry['selected_option_id']
                    : null;

                $answerRecord = $attemptAnswers->firstWhere('question_id', $questionId);

                if (! $answerRecord) {
                    throw ValidationException::withMessages([
                        'answers' => ["Question ID {$questionId} does not belong to this attempt."],
                    ]);
                }

                if ($selectedOptionId !== null) {
                    $validOptionIds = array_map(
                        'intval',
                        array_column($answerRecord->options_snapshot, 'id')
                    );

                    if (! in_array($selectedOptionId, $validOptionIds, true)) {
                        throw ValidationException::withMessages([
                            'answers' => ["Option ID {$selectedOptionId} is not a valid option for Question ID {$questionId}."],
                        ]);
                    }
                }

                $answerRecord->update([
                    'selected_option_id' => $selectedOptionId,
                ]);
            }

            return $lockedAttempt->fresh('answers');
        });
    }

    /**
     * Submit and grade an attempt atomically.
     */
    public function submitAttempt(
        ExerciseAttempt $attempt,
        array $answers = []
    ): ExerciseAttempt {
        return DB::transaction(function () use ($attempt, $answers): ExerciseAttempt {
            $lockedAttempt = ExerciseAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedAttempt->isInProgress()) {
                throw ValidationException::withMessages([
                    'attempt' => ['Attempt is already submitted.'],
                ]);
            }

            if ($answers !== []) {
                $this->saveAnswers($lockedAttempt, $answers);
            }

            $answersList = $lockedAttempt->answers()->get();

            $totalPointsPossible = 0.0;
            $totalPointsAwarded = 0.0;

            foreach ($answersList as $answer) {
                $pointsPossible = (float) $answer->points_possible;
                $totalPointsPossible += $pointsPossible;

                $isCorrect = (
                    $answer->selected_option_id !== null
                    && (int) $answer->selected_option_id === (int) $answer->correct_option_id_snapshot
                );

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
                    'options' => $answer->options_snapshot,
                ];

                if ($canRevealAnswers) {
                    $data['is_correct'] = $answer->is_correct;
                    $data['points_awarded'] = (float) $answer->points_awarded;
                    $data['correct_option_id'] = $answer->correct_option_id_snapshot;
                    $data['explanation'] = $answer->explanation_snapshot;
                }

                return $data;
            })->values()->all(),
        ];
    }
}
