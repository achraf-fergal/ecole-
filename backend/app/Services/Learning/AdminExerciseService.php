<?php

namespace App\Services\Learning;

use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($lesson, $data, $existing): Exercise {
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

                $fillable = array_intersect_key($data, array_flip([
                    'title',
                    'instructions',
                    'max_attempts',
                    'passing_score',
                    'show_correct_answers_after_submission',
                    'sort_order',
                ]));

                $lockedExercise->update($fillable);
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

                $exercise = Exercise::query()->create([
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
                $points = $qData['points'] ?? 1.00;
                $sortOrder = $qData['sort_order'] ?? ($qIndex + 1);

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
                        'answer_config' => match ($questionType) {
                            Question::TYPE_SHORT_ANSWER => isset($qData['answer_config'])
                                ? $this->answerGrader->normalizeConfiguration($qData['answer_config'])
                                : null,
                            Question::TYPE_FILL_IN_THE_BLANKS => isset($qData['answer_config'])
                                ? $this->answerGrader->normalizeBlankConfiguration($qData['answer_config'])
                                : null,
                            default => null,
                        },
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
                        'answer_config' => match ($questionType) {
                            Question::TYPE_SHORT_ANSWER => isset($qData['answer_config'])
                                ? $this->answerGrader->normalizeConfiguration($qData['answer_config'])
                                : null,
                            Question::TYPE_FILL_IN_THE_BLANKS => isset($qData['answer_config'])
                                ? $this->answerGrader->normalizeBlankConfiguration($qData['answer_config'])
                                : null,
                            default => null,
                        },
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
}
