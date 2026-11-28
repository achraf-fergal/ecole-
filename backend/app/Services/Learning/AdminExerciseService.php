<?php

namespace App\Services\Learning;

use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\ExerciseAttemptAnswer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdminExerciseService
{
    public function __construct(
        protected LessonRevisionService $revisionService,
        protected ExerciseAnswerGrader $answerGrader
    ) {}

    /**
     * Create an exercise in draft status for a lesson.
     * Enforces locking on the owning Lesson row.
     */
    public function createExercise(
        Lesson $lesson,
        array $data,
        User $actor
    ): Exercise {
        return DB::transaction(function () use ($lesson, $data): Exercise {
            $lockedLesson = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sortOrder = $data['sort_order'] ?? null;
            if ($sortOrder === null) {
                $maxOrder = Exercise::query()
                    ->where('center_id', $lockedLesson->center_id)
                    ->where('lesson_id', $lockedLesson->id)
                    ->max('sort_order');
                $sortOrder = $maxOrder !== null ? $maxOrder + 1 : 0;
            }

            return Exercise::query()->create([
                'center_id' => $lockedLesson->center_id,
                'lesson_id' => $lockedLesson->id,
                'title' => $data['title'],
                'instructions' => $data['instructions'] ?? null,
                'max_attempts' => $data['max_attempts'] ?? null,
                'passing_score' => $data['passing_score'] ?? 70.00,
                'show_correct_answers_after_submission' => (bool) ($data['show_correct_answers_after_submission'] ?? false),
                'sort_order' => $sortOrder,
                'status' => Exercise::STATUS_DRAFT,
                'published_at' => null,
            ]);
        });
    }

    /**
     * Update exercise metadata.
     * Follows strict lock order: 1. Owning Lesson -> 2. Exercise -> 3. assertExerciseCanBeModified.
     */
    public function updateExercise(
        Exercise $exercise,
        array $data
    ): Exercise {
        return DB::transaction(function () use ($exercise, $data): Exercise {
            $lockedLesson = Lesson::query()
                ->forCenter((int) $exercise->center_id)
                ->whereKey($exercise->lesson_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedExercise = Exercise::query()
                ->where('center_id', $lockedLesson->center_id)
                ->where('lesson_id', $lockedLesson->id)
                ->whereKey($exercise->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->revisionService->assertExerciseCanBeModified($lockedExercise);

            $fillable = array_intersect_key($data, array_flip([
                'title',
                'instructions',
                'max_attempts',
                'passing_score',
                'show_correct_answers_after_submission',
                'sort_order',
            ]));

            $lockedExercise->update($fillable);

            return $lockedExercise->fresh();
        });
    }

    /**
     * Save an entire exercise with questions and options atomically.
     * Follows strict lock order: 1. Owning Lesson -> 2. Exercise (if existing) -> 3. assertCanBeModified.
     */
    public function saveAggregateExercise(
        Lesson $lesson,
        array $data,
        User $actor,
        ?Exercise $existing = null
    ): Exercise {
        return DB::transaction(function () use ($lesson, $data, $actor, $existing): Exercise {
            $lockedLesson = Lesson::query()
                ->forCenter((int) $lesson->center_id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($existing !== null) {
                $lockedExercise = Exercise::query()
                    ->where('center_id', $lockedLesson->center_id)
                    ->where('lesson_id', $lockedLesson->id)
                    ->whereKey($existing->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->revisionService->assertExerciseCanBeModified($lockedExercise);

                $wasIncompatible = $this->isIncompatibleReleaseConfiguration(
                    $lockedExercise->max_attempts,
                    $lockedExercise->resolvedResultSettings()
                );

                $fillable = array_intersect_key($data, array_flip([
                    'title',
                    'instructions',
                    'max_attempts',
                    'passing_score',
                    'show_correct_answers_after_submission',
                    'sort_order',
                ]));

                if (array_key_exists('result_settings', $data) && is_array($data['result_settings'])) {
                    $normalizedResultSettings = Exercise::normalizeResultSettings($data['result_settings']);
                    $fillable['result_settings'] = $normalizedResultSettings;
                    $fillable['show_correct_answers_after_submission'] = (bool) $normalizedResultSettings['show_correct_answers'];
                } elseif (array_key_exists('show_correct_answers_after_submission', $data)) {
                    $legacyFlag = (bool) $data['show_correct_answers_after_submission'];
                    $existingSettings = is_array($lockedExercise->result_settings)
                        ? $lockedExercise->result_settings
                        : Exercise::defaultResultSettings($legacyFlag);
                    $existingSettings['show_correct_answers'] = $legacyFlag;
                    $existingSettings['show_explanations'] = $legacyFlag;
                    $fillable['result_settings'] = Exercise::normalizeResultSettings($existingSettings);
                }

                $effectiveMaxAttempts = array_key_exists('max_attempts', $fillable)
                    ? $fillable['max_attempts']
                    : $lockedExercise->max_attempts;
                $effectiveResultSettings = $fillable['result_settings'] ?? $lockedExercise->resolvedResultSettings();

                if (
                    array_key_exists('max_attempts', $fillable)
                    || array_key_exists('result_settings', $fillable)
                    || ! $wasIncompatible
                ) {
                    $this->assertValidResultReleaseConfiguration($effectiveMaxAttempts, $effectiveResultSettings);
                }

                $lockedExercise->update($fillable);

                if ($wasIncompatible && ! $this->isIncompatibleReleaseConfiguration($lockedExercise->max_attempts, $lockedExercise->resolvedResultSettings())) {
                    Log::info('Resolved incompatible exercise release configuration.', [
                        'center_id' => $lockedExercise->center_id,
                        'lesson_id' => $lockedExercise->lesson_id,
                        'exercise_id' => $lockedExercise->id,
                        'actor_user_id' => $actor->id,
                        'new_max_attempts' => $lockedExercise->max_attempts,
                        'new_release_timing' => $lockedExercise->resolvedResultSettings()['release_timing'] ?? null,
                    ]);
                }

                $exercise = $lockedExercise;
            } else {
                $sortOrder = $data['sort_order'] ?? null;
                if ($sortOrder === null) {
                    $maxOrder = Exercise::query()
                        ->where('center_id', $lockedLesson->center_id)
                        ->where('lesson_id', $lockedLesson->id)
                        ->max('sort_order');
                    $sortOrder = $maxOrder !== null ? $maxOrder + 1 : 0;
                }

                $legacyShowCorrect = (bool) ($data['show_correct_answers_after_submission'] ?? false);
                $normalizedResultSettings = Exercise::normalizeResultSettings(
                    is_array($data['result_settings'] ?? null) ? $data['result_settings'] : null,
                    $legacyShowCorrect
                );

                $this->assertValidResultReleaseConfiguration(
                    $data['max_attempts'] ?? null,
                    $normalizedResultSettings
                );

                $exercise = Exercise::query()->create([
                    'center_id' => $lockedLesson->center_id,
                    'lesson_id' => $lockedLesson->id,
                    'title' => $data['title'],
                    'instructions' => $data['instructions'] ?? null,
                    'max_attempts' => $data['max_attempts'] ?? null,
                    'passing_score' => $data['passing_score'] ?? 70.00,
                    'show_correct_answers_after_submission' => (bool) $normalizedResultSettings['show_correct_answers'],
                    'result_settings' => $normalizedResultSettings,
                    'sort_order' => $sortOrder,
                    'status' => Exercise::STATUS_DRAFT,
                    'published_at' => null,
                ]);
            }

            if (isset($data['questions']) && is_array($data['questions'])) {
                $this->syncQuestionsAndOptions($exercise, $data['questions']);
            }

            return $exercise->fresh([
                'questions.options',
            ]);
        });
    }

    /**
     * Synchronize questions and options for an exercise.
     * Enforces locking on Lesson -> Exercise -> Questions, followed by strict ID validation.
     */
    public function syncQuestionsAndOptions(
        Exercise $exercise,
        array $questionsData
    ): void {
        DB::transaction(function () use ($exercise, $questionsData): void {
            $lockedLesson = Lesson::query()
                ->forCenter((int) $exercise->center_id)
                ->whereKey($exercise->lesson_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedExercise = Exercise::query()
                ->where('center_id', $lockedLesson->center_id)
                ->where('lesson_id', $lockedLesson->id)
                ->whereKey($exercise->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->revisionService->assertExerciseCanBeModified($lockedExercise);

            // Pre-validation: Strictly validate question and option IDs while locks are held.
            $submittedQuestionIds = [];
            $existingQuestionIdsInDb = Question::query()
                ->where('center_id', $lockedExercise->center_id)
                ->where('exercise_id', $lockedExercise->id)
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($questionsData as $qIndex => $qData) {
                $questionType = $qData['question_type'] ?? Question::TYPE_SINGLE_CHOICE;

                if (! in_array($questionType, Question::TYPES, true)) {
                    throw ValidationException::withMessages([
                        "questions.{$qIndex}.question_type" => ['Unsupported question type.'],
                    ]);
                }

                if (
                    ! in_array($questionType, [Question::TYPE_SHORT_ANSWER, Question::TYPE_FILL_IN_THE_BLANKS], true)
                    && isset($qData['answer_config'])
                ) {
                    throw ValidationException::withMessages([
                        "questions.{$qIndex}.answer_config" => ['Answer configuration is only valid for short-answer and fill-in-the-blanks questions.'],
                    ]);
                }

                if (
                    $questionType === Question::TYPE_SHORT_ANSWER
                    && array_key_exists('answer_config', $qData)
                    && $qData['answer_config'] !== null
                ) {
                    $configErrors = $this->answerGrader->configurationErrors(
                        $qData['answer_config'],
                        false
                    );

                    if ($configErrors !== []) {
                        throw ValidationException::withMessages(
                            $this->prefixQuestionErrors($configErrors, $qIndex)
                        );
                    }
                }

                if (
                    $questionType === Question::TYPE_FILL_IN_THE_BLANKS
                    && array_key_exists('answer_config', $qData)
                    && $qData['answer_config'] !== null
                ) {
                    $configErrors = $this->answerGrader->blankConfigurationErrors(
                        $qData['answer_config'],
                        false
                    );

                    if ($configErrors !== []) {
                        throw ValidationException::withMessages(
                            $this->prefixQuestionErrors($configErrors, $qIndex)
                        );
                    }
                }

                if (
                    in_array($questionType, [Question::TYPE_SHORT_ANSWER, Question::TYPE_FILL_IN_THE_BLANKS], true)
                    && ! empty($qData['options'])
                ) {
                    throw ValidationException::withMessages([
                        "questions.{$qIndex}.options" => ['This question type does not accept choice options.'],
                    ]);
                }

                $qId = $qData['id'] ?? null;
                if ($qId !== null) {
                    if (! is_numeric($qId) || (int) $qId <= 0) {
                        throw ValidationException::withMessages([
                            "questions.{$qIndex}.id" => ['Invalid question ID provided.'],
                        ]);
                    }
                    $qId = (int) $qId;
                    if (in_array($qId, $submittedQuestionIds, true)) {
                        throw ValidationException::withMessages([
                            "questions.{$qIndex}.id" => ["Duplicate question ID {$qId} in payload."],
                        ]);
                    }
                    if (! in_array($qId, $existingQuestionIdsInDb, true)) {
                        throw ValidationException::withMessages([
                            "questions.{$qIndex}.id" => ["Question ID {$qId} does not belong to this exercise."],
                        ]);
                    }
                    $submittedQuestionIds[] = $qId;
                }

                // Validate options for this question
                if (isset($qData['options']) && is_array($qData['options'])) {
                    $this->assertUniqueOptionLabels($qData['options'], $qIndex);

                    $submittedOptionIds = [];
                    $existingOptionIdsForQuestion = [];
                    if ($qId !== null) {
                        $existingOptionIdsForQuestion = QuestionOption::query()
                            ->where('center_id', $lockedExercise->center_id)
                            ->where('question_id', $qId)
                            ->lockForUpdate()
                            ->pluck('id')
                            ->map(fn ($id) => (int) $id)
                            ->all();
                    }

                    foreach ($qData['options'] as $oIndex => $oData) {
                        $oId = $oData['id'] ?? null;
                        if ($oId !== null) {
                            if (! is_numeric($oId) || (int) $oId <= 0) {
                                throw ValidationException::withMessages([
                                    "questions.{$qIndex}.options.{$oIndex}.id" => ['Invalid option ID provided.'],
                                ]);
                            }
                            $oId = (int) $oId;
                            if (in_array($oId, $submittedOptionIds, true)) {
                                throw ValidationException::withMessages([
                                    "questions.{$qIndex}.options.{$oIndex}.id" => ["Duplicate option ID {$oId} in question."],
                                ]);
                            }
                            if ($qId === null) {
                                throw ValidationException::withMessages([
                                    "questions.{$qIndex}.options.{$oIndex}.id" => ["New question cannot reference existing option ID {$oId}."],
                                ]);
                            }
                            if (! in_array($oId, $existingOptionIdsForQuestion, true)) {
                                throw ValidationException::withMessages([
                                    "questions.{$qIndex}.options.{$oIndex}.id" => ["Option ID {$oId} does not belong to Question ID {$qId}."],
                                ]);
                            }
                            $submittedOptionIds[] = $oId;
                        }
                    }
                }
            }

            // Delete questions not present in the payload
            Question::query()
                ->where('center_id', $lockedExercise->center_id)
                ->where('exercise_id', $lockedExercise->id)
                ->whereNotIn('id', $submittedQuestionIds)
                ->delete();

            foreach ($questionsData as $qIndex => $qData) {
                $qId = isset($qData['id']) && $qData['id'] !== null ? (int) $qData['id'] : null;
                $questionType = $qData['question_type'] ?? Question::TYPE_SINGLE_CHOICE;
                $sortOrder = $qData['sort_order'] ?? ($qIndex + 1);

                $normalizedAnswerConfig = match ($questionType) {
                    Question::TYPE_SHORT_ANSWER => isset($qData['answer_config'])
                        ? $this->answerGrader->normalizeConfiguration($qData['answer_config'])
                        : null,
                    Question::TYPE_FILL_IN_THE_BLANKS => isset($qData['answer_config'])
                        ? $this->answerGrader->normalizeBlankConfiguration($qData['answer_config'])
                        : null,
                    default => null,
                };

                $points = $questionType === Question::TYPE_FILL_IN_THE_BLANKS
                    ? $this->answerGrader->minorUnitsToDecimalString(
                        $this->answerGrader->sumBlankPointsMinorUnits($normalizedAnswerConfig)
                    )
                    : ($qData['points'] ?? 1.00);

                if ($qId !== null) {
                    $q = Question::query()
                        ->where('center_id', $lockedExercise->center_id)
                        ->where('exercise_id', $lockedExercise->id)
                        ->lockForUpdate()
                        ->findOrFail($qId);

                    $q->update([
                        'question_type' => $questionType,
                        'prompt' => $qData['prompt'] ?? '',
                        'explanation' => $qData['explanation'] ?? null,
                        'answer_config' => $normalizedAnswerConfig,
                        'points' => $points,
                        'sort_order' => $sortOrder,
                    ]);
                } else {
                    $q = Question::query()->create([
                        'center_id' => $lockedExercise->center_id,
                        'exercise_id' => $lockedExercise->id,
                        'question_type' => $questionType,
                        'prompt' => $qData['prompt'] ?? '',
                        'explanation' => $qData['explanation'] ?? null,
                        'answer_config' => $normalizedAnswerConfig,
                        'points' => $points,
                        'sort_order' => $sortOrder,
                    ]);
                }

                if (in_array($questionType, [Question::TYPE_SHORT_ANSWER, Question::TYPE_FILL_IN_THE_BLANKS], true)) {
                    QuestionOption::query()
                        ->where('center_id', $lockedExercise->center_id)
                        ->where('question_id', $q->id)
                        ->delete();
                } elseif (isset($qData['options']) && is_array($qData['options'])) {
                    $submittedOptIds = array_values(array_filter(
                        array_map(fn ($o) => isset($o['id']) && $o['id'] !== null ? (int) $o['id'] : null, $qData['options']),
                        fn ($id) => $id !== null
                    ));

                    QuestionOption::query()
                        ->where('center_id', $lockedExercise->center_id)
                        ->where('question_id', $q->id)
                        ->whereNotIn('id', $submittedOptIds)
                        ->delete();

                    foreach ($qData['options'] as $oIndex => $oData) {
                        $oId = isset($oData['id']) && $oData['id'] !== null ? (int) $oData['id'] : null;
                        $isCorrect = (bool) ($oData['is_correct'] ?? false);
                        $oSortOrder = $oData['sort_order'] ?? ($oIndex + 1);

                        if ($oId !== null) {
                            $opt = QuestionOption::query()
                                ->where('center_id', $lockedExercise->center_id)
                                ->where('question_id', $q->id)
                                ->lockForUpdate()
                                ->findOrFail($oId);

                            $opt->update([
                                'option_text' => $oData['option_text'] ?? '',
                                'is_correct' => $isCorrect,
                                'sort_order' => $oSortOrder,
                            ]);
                        } else {
                            QuestionOption::query()->create([
                                'center_id' => $lockedExercise->center_id,
                                'question_id' => $q->id,
                                'option_text' => $oData['option_text'] ?? '',
                                'is_correct' => $isCorrect,
                                'sort_order' => $oSortOrder,
                            ]);
                        }
                    }
                }
            }
        });
    }

    /**
     * Publish an exercise after comprehensive validation.
     * Follows strict lock order: 1. Owning Lesson -> 2. Exercise -> 3. assertExerciseCanBeModified.
     */
    public function publishExercise(
        Exercise $exercise,
        User $actor
    ): Exercise {
        return DB::transaction(function () use ($exercise): Exercise {
            $lockedLesson = Lesson::query()
                ->forCenter((int) $exercise->center_id)
                ->whereKey($exercise->lesson_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedExercise = Exercise::query()
                ->where('center_id', $lockedLesson->center_id)
                ->where('lesson_id', $lockedLesson->id)
                ->whereKey($exercise->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->revisionService->assertExerciseCanBeModified($lockedExercise);

            $this->assertValidResultReleaseConfiguration(
                $lockedExercise->max_attempts,
                $lockedExercise->resolvedResultSettings()
            );

            if (trim((string) $lockedExercise->title) === '') {
                throw ValidationException::withMessages([
                    'title' => ['An exercise title is required before publication.'],
                ]);
            }

            $questions = $lockedExercise->questions()->with('options')->lockForUpdate()->get();

            if ($questions->isEmpty()) {
                throw ValidationException::withMessages([
                    'questions' => ['An exercise must contain at least one question before publication.'],
                ]);
            }

            foreach ($questions as $question) {
                if (trim((string) $question->prompt) === '') {
                    throw ValidationException::withMessages([
                        'questions' => ["Question #{$question->id} must have a prompt."],
                    ]);
                }

                if ((float) $question->points <= 0) {
                    throw ValidationException::withMessages([
                        'questions' => ["Question #{$question->id} points must be greater than zero."],
                    ]);
                }

                if (! in_array($question->question_type, Question::TYPES, true)) {
                    throw ValidationException::withMessages([
                        'questions' => ["Question #{$question->id} has an invalid question type."],
                    ]);
                }

                $errors = $question->optionConfigurationErrors();
                if ($errors !== []) {
                    throw ValidationException::withMessages([
                        'questions' => array_map(
                            fn (string $err): string => "Question #{$question->id}: {$err}",
                            $errors
                        ),
                    ]);
                }

                if ($question->question_type === Question::TYPE_SHORT_ANSWER) {
                    $configErrors = $this->answerGrader->configurationErrors(
                        $question->answer_config,
                        true
                    );

                    if ($configErrors !== []) {
                        $messages = [];
                        foreach ($configErrors as $fieldMessages) {
                            foreach ($fieldMessages as $message) {
                                $messages[] = "Question #{$question->id}: {$message}";
                            }
                        }

                        throw ValidationException::withMessages([
                            'questions' => $messages,
                        ]);
                    }
                } elseif ($question->question_type === Question::TYPE_FILL_IN_THE_BLANKS) {
                    $configErrors = $this->answerGrader->blankConfigurationErrors(
                        $question->answer_config,
                        true
                    );

                    $placeholderErrors = $this->answerGrader->promptPlaceholderErrors(
                        (string) $question->prompt,
                        $question->answer_config
                    );

                    $allErrors = array_merge_recursive($configErrors, $placeholderErrors);

                    $blankTotalMinorUnits = $this->answerGrader->sumBlankPointsMinorUnits($question->answer_config);
                    $questionPointsMinorUnits = $this->answerGrader->decimalToMinorUnits($question->points);

                    if ($blankTotalMinorUnits <= 0 || $blankTotalMinorUnits !== $questionPointsMinorUnits) {
                        $allErrors['points'][] = 'Question points must equal the positive sum of all blank points.';
                    }

                    if ($allErrors !== []) {
                        $messages = [];
                        foreach ($allErrors as $fieldMessages) {
                            foreach ($fieldMessages as $message) {
                                $messages[] = "Question #{$question->id}: {$message}";
                            }
                        }

                        throw ValidationException::withMessages([
                            'questions' => $messages,
                        ]);
                    }
                } elseif ($question->answer_config !== null) {
                    throw ValidationException::withMessages([
                        'questions' => ["Question #{$question->id} has answer settings for an unsupported question type."],
                    ]);
                }

                $this->assertStoredOptionsAreUnique($question);
            }

            $lockedExercise->update([
                'status' => Exercise::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            return $lockedExercise->fresh(['questions.options']);
        });
    }

    /**
     * Delete an exercise.
     * Follows strict lock order: 1. Owning Lesson -> 2. Exercise -> 3. assertExerciseCanBeModified.
     */
    public function deleteExercise(Exercise $exercise): void
    {
        DB::transaction(function () use ($exercise): void {
            $lockedLesson = Lesson::query()
                ->forCenter((int) $exercise->center_id)
                ->whereKey($exercise->lesson_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedExercise = Exercise::query()
                ->where('center_id', $lockedLesson->center_id)
                ->where('lesson_id', $lockedLesson->id)
                ->whereKey($exercise->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->revisionService->assertExerciseCanBeModified($lockedExercise);

            // Delete options first, then questions, then exercise.
            QuestionOption::query()
                ->where('center_id', $lockedExercise->center_id)
                ->whereIn(
                    'question_id',
                    $lockedExercise->questions()->select('id')
                )
                ->delete();

            $lockedExercise->questions()->delete();
            $lockedExercise->delete();
        });
    }

    /**
     * Reject duplicate option labels using NFC and whitespace normalization.
     * Case and accents remain meaningful for option labels.
     */
    private function assertUniqueOptionLabels(array $options, int|string $questionIndex): void
    {
        $seen = [];

        foreach ($options as $optionIndex => $option) {
            $text = $option['option_text'] ?? '';
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $normalized = $this->answerGrader->normalizeOptionLabel($text);
            if (in_array($normalized, $seen, true)) {
                throw ValidationException::withMessages([
                    "questions.{$questionIndex}.options.{$optionIndex}.option_text" => [
                        'This option duplicates another option in the same question after whitespace and Unicode normalization.',
                    ],
                ]);
            }

            $seen[] = $normalized;
        }
    }

    private function assertStoredOptionsAreUnique(Question $question): void
    {
        $seen = [];

        foreach ($question->options as $option) {
            $normalized = $this->answerGrader->normalizeOptionLabel((string) $option->option_text);
            if (in_array($normalized, $seen, true)) {
                throw ValidationException::withMessages([
                    'questions' => ["Question #{$question->id} contains duplicate option text."],
                ]);
            }

            $seen[] = $normalized;
        }
    }

    /** @param array<string, list<string>> $errors @return array<string, list<string>> */
    private function prefixQuestionErrors(array $errors, int|string $questionIndex): array
    {
        $prefixed = [];

        foreach ($errors as $field => $messages) {
            $prefixed["questions.{$questionIndex}.{$field}"] = $messages;
        }

        return $prefixed;
    }

    public function isIncompatibleReleaseConfiguration(?int $maxAttempts, array $resultSettings): bool
    {
        return $maxAttempts === null
            && ($resultSettings['release_timing'] ?? Exercise::RELEASE_TIMING_IMMEDIATELY) === Exercise::RELEASE_TIMING_AFTER_FINAL_ATTEMPT;
    }

    private function assertValidResultReleaseConfiguration(?int $maxAttempts, array $resultSettings): void
    {
        if ($this->isIncompatibleReleaseConfiguration($maxAttempts, $resultSettings)) {
            throw ValidationException::withMessages([
                'result_settings.release_timing' => [
                    'Results cannot be held until the final attempt when maximum attempts are unlimited. Set a finite attempt limit or release results immediately.',
                ],
            ]);
        }
    }

    /**
     * Grade a simulated student attempt in memory without persisting any attempt,
     * answer, or lesson progress records.
     *
     * @param  list<array<string, mixed>>  $answersInput
     */
    public function simulateGrade(Exercise $exercise, array $answersInput, string $attemptScenario = 'first'): array
    {
        $exercise->loadMissing(['questions.options']);

        $indexedAnswers = [];
        foreach ($answersInput as $item) {
            if (is_array($item) && isset($item['question_id']) && is_numeric($item['question_id'])) {
                $indexedAnswers[(int) $item['question_id']] = $item;
            }
        }

        $isFinalScenario = $attemptScenario === 'final' && $exercise->max_attempts !== null;
        $simulatedAttemptNumber = $isFinalScenario ? (int) $exercise->max_attempts : 1;

        $settings = $exercise->resolvedResultSettings();
        $releaseTiming = $settings['release_timing'] ?? Exercise::RELEASE_TIMING_IMMEDIATELY;
        $resultsReleased = $releaseTiming === Exercise::RELEASE_TIMING_AFTER_FINAL_ATTEMPT
            ? ($exercise->max_attempts !== null && $simulatedAttemptNumber >= (int) $exercise->max_attempts)
            : true;

        $showScore = $resultsReleased && (bool) $settings['show_score'];
        $showPercentage = $resultsReleased && (bool) $settings['show_percentage'];
        $showPassFail = $resultsReleased && (bool) $settings['show_pass_fail'];
        $showQuestionResults = $resultsReleased && (bool) $settings['show_question_results'];
        $showStudentAnswers = $resultsReleased && (bool) $settings['show_student_answers'];
        $showCorrectAnswers = $resultsReleased && (bool) $settings['show_question_results'] && (bool) $settings['show_correct_answers'];
        $showExplanations = $resultsReleased && (bool) $settings['show_question_results'] && (bool) $settings['show_explanations'];
        $showPointsPerQuestion = $resultsReleased && (bool) $settings['show_question_results'] && (bool) $settings['show_points_per_question'];

        $totalMaxMinorUnits = 0;
        $totalScoreMinorUnits = 0;
        $correctCount = 0;
        $incorrectCount = 0;
        $unansweredCount = 0;
        $questionPayloads = [];

        foreach ($exercise->questions as $question) {
            $input = $indexedAnswers[(int) $question->id] ?? [];
            $optionsSnapshot = $question->options->map(static fn (QuestionOption $o): array => [
                'id' => $o->id,
                'option_text' => $o->option_text,
                'sort_order' => $o->sort_order,
            ])->values()->all();

            $correctOptionIds = $question->options
                ->filter(static fn (QuestionOption $o): bool => (bool) $o->is_correct)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();

            $selectedOptionId = isset($input['selected_option_id']) && is_numeric($input['selected_option_id'])
                ? (int) $input['selected_option_id']
                : null;
            $selectedOptionIds = isset($input['selected_option_ids']) && is_array($input['selected_option_ids'])
                ? array_values(array_unique(array_map('intval', array_filter($input['selected_option_ids'], 'is_numeric'))))
                : null;

            $textAnswer = isset($input['text_answer']) && is_string($input['text_answer'])
                ? $input['text_answer']
                : null;

            if ($question->question_type === Question::TYPE_FILL_IN_THE_BLANKS && isset($input['blank_answers']) && is_array($input['blank_answers'])) {
                $cleanBlanks = [];
                foreach ($input['blank_answers'] as $blankId => $blankValue) {
                    if (is_string($blankId) && is_string($blankValue)) {
                        $cleanBlanks[$blankId] = $blankValue;
                    }
                }
                $textAnswer = $cleanBlanks !== [] ? json_encode($cleanBlanks, JSON_UNESCAPED_UNICODE) : null;
            }

            $inMemoryAnswer = new ExerciseAttemptAnswer([
                'question_id' => $question->id,
                'question_type_snapshot' => $question->question_type,
                'prompt_snapshot' => $question->prompt,
                'explanation_snapshot' => $question->explanation,
                'points_possible' => (float) $question->points,
                'options_snapshot' => $optionsSnapshot,
                'correct_option_id_snapshot' => $correctOptionIds[0] ?? null,
                'correct_option_ids_snapshot' => $correctOptionIds,
                'answer_config_snapshot' => $question->answer_config,
                'selected_option_id' => $selectedOptionId,
                'selected_option_ids' => $selectedOptionIds,
                'text_answer' => $textAnswer,
            ]);

            $isUnanswered = $this->isSimulatedAnswerUnanswered($inMemoryAnswer);
            $questionPossibleMinor = $this->answerGrader->decimalToMinorUnits((float) $question->points);
            $totalMaxMinorUnits += $questionPossibleMinor;

            $blanksFeedback = null;
            if ($question->question_type === Question::TYPE_FILL_IN_THE_BLANKS) {
                $fillResult = $this->answerGrader->gradeFillInTheBlanks($inMemoryAnswer);
                $isCorrect = $fillResult['is_correct'];
                $awardedMinor = $fillResult['points_awarded_minor_units'];
                $blanksFeedback = $fillResult['blanks_feedback'];
            } else {
                $isCorrect = $this->answerGrader->isCorrect($inMemoryAnswer);
                $awardedMinor = $isCorrect ? $questionPossibleMinor : 0;
            }

            $totalScoreMinorUnits += $awardedMinor;
            $pointsAwarded = (float) $this->answerGrader->minorUnitsToDecimalString($awardedMinor);

            if ($isUnanswered) {
                $unansweredCount += 1;
            } elseif ($isCorrect) {
                $correctCount += 1;
            } else {
                $incorrectCount += 1;
            }

            $qPayload = [
                'id' => $question->id,
                'question_type' => $question->question_type,
                'prompt' => $question->prompt,
                'points_possible' => $showPointsPerQuestion ? (float) $question->points : null,
                'options' => $optionsSnapshot,
            ];

            if ($showStudentAnswers) {
                $qPayload['selected_option_id'] = $selectedOptionId;
                $qPayload['selected_option_ids'] = $selectedOptionIds;
                $qPayload['text_answer'] = $textAnswer;
                if ($question->question_type === Question::TYPE_FILL_IN_THE_BLANKS) {
                    $decoded = $textAnswer !== null ? json_decode($textAnswer, true) : null;
                    $qPayload['blank_answers'] = is_array($decoded) ? $decoded : new \stdClass();
                }
            } else {
                $qPayload['selected_option_id'] = null;
                $qPayload['selected_option_ids'] = null;
                $qPayload['text_answer'] = null;
                if ($question->question_type === Question::TYPE_FILL_IN_THE_BLANKS) {
                    $qPayload['blank_answers'] = new \stdClass();
                }
            }

            if ($showQuestionResults) {
                $qPayload['is_correct'] = $isCorrect;
                $qPayload['result_status'] = $isUnanswered ? 'unanswered' : ($isCorrect ? 'correct' : 'incorrect');

                if ($showPointsPerQuestion) {
                    $qPayload['points_awarded'] = $pointsAwarded;
                }

                if ($showCorrectAnswers) {
                    $qPayload['correct_option_id'] = $correctOptionIds[0] ?? null;
                    $qPayload['correct_option_ids'] = $correctOptionIds;
                    if ($question->question_type === Question::TYPE_SHORT_ANSWER) {
                        $qPayload['accepted_answers'] = $question->answer_config['accepted_answers'] ?? [];
                    }
                }

                if ($question->question_type === Question::TYPE_FILL_IN_THE_BLANKS && $blanksFeedback !== null) {
                    $qPayload['blanks'] = array_map(
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
                        $blanksFeedback
                    );
                }
            }

            if ($showExplanations) {
                $qPayload['explanation'] = $question->explanation;
            }

            $questionPayloads[] = $qPayload;
        }

        $maxScore = (float) $this->answerGrader->minorUnitsToDecimalString($totalMaxMinorUnits);
        $score = (float) $this->answerGrader->minorUnitsToDecimalString($totalScoreMinorUnits);
        $percentage = $totalMaxMinorUnits > 0
            ? round(($totalScoreMinorUnits / $totalMaxMinorUnits) * 100, 2)
            : 0.0;
        $passed = $percentage >= (float) $exercise->passing_score;

        return [
            'id' => 0,
            'exercise_id' => $exercise->id,
            'attempt_number' => $simulatedAttemptNumber,
            'attempt_scenario' => $isFinalScenario ? 'final' : 'first',
            'status' => ExerciseAttempt::STATUS_GRADED,
            'passing_score' => $showPassFail ? (float) $exercise->passing_score : null,
            'max_score' => $showScore ? $maxScore : null,
            'score' => $showScore ? $score : null,
            'percentage' => $showPercentage ? $percentage : null,
            'passed' => $showPassFail ? $passed : null,
            'correct_count' => $showQuestionResults ? $correctCount : null,
            'incorrect_count' => $showQuestionResults ? $incorrectCount : null,
            'unanswered_count' => $showQuestionResults ? $unansweredCount : null,
            'started_at' => now()->toIso8601String(),
            'submitted_at' => now()->toIso8601String(),
            'graded_at' => now()->toIso8601String(),
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
            'questions' => $questionPayloads,
        ];
    }

    private function isSimulatedAnswerUnanswered(ExerciseAttemptAnswer $answer): bool
    {
        return match ($answer->question_type_snapshot) {
            Question::TYPE_SINGLE_CHOICE,
            Question::TYPE_TRUE_FALSE => $answer->selected_option_id === null,
            Question::TYPE_MULTIPLE_SELECT => ! is_array($answer->selected_option_ids) || $answer->selected_option_ids === [],
            Question::TYPE_SHORT_ANSWER => trim((string) ($answer->text_answer ?? '')) === '',
            Question::TYPE_FILL_IN_THE_BLANKS => $this->isSimulatedFillBlankUnanswered($answer->text_answer),
            default => true,
        };
    }

    private function isSimulatedFillBlankUnanswered(?string $textAnswer): bool
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
}
