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
        protected LessonRevisionService $revisionService
    ) {}

    /**
     * Create an exercise in draft status for a lesson.
     */
    public function createExercise(
        Lesson $lesson,
        array $data,
        User $actor
    ): Exercise {
        return DB::transaction(function () use ($lesson, $data): Exercise {
            $sortOrder = $data['sort_order'] ?? null;
            if ($sortOrder === null) {
                $maxOrder = Exercise::query()
                    ->where('center_id', $lesson->center_id)
                    ->where('lesson_id', $lesson->id)
                    ->max('sort_order');
                $sortOrder = $maxOrder !== null ? $maxOrder + 1 : 0;
            }

            return Exercise::query()->create([
                'center_id' => $lesson->center_id,
                'lesson_id' => $lesson->id,
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
     */
    public function updateExercise(
        Exercise $exercise,
        array $data
    ): Exercise {
        $this->revisionService->assertExerciseCanBeModified($exercise);

        return DB::transaction(function () use ($exercise, $data): Exercise {
            $fillable = array_intersect_key($data, array_flip([
                'title',
                'instructions',
                'max_attempts',
                'passing_score',
                'show_correct_answers_after_submission',
                'sort_order',
            ]));

            $exercise->update($fillable);

            return $exercise->fresh();
        });
    }

    /**
     * Save an entire exercise with questions and options atomically.
     */
    public function saveAggregateExercise(
        Lesson $lesson,
        array $data,
        User $actor,
        ?Exercise $existing = null
    ): Exercise {
        return DB::transaction(function () use ($lesson, $data, $actor, $existing): Exercise {
            if ($existing !== null) {
                $this->revisionService->assertExerciseCanBeModified($existing);
                $exercise = $this->updateExercise($existing, $data);
            } else {
                $exercise = $this->createExercise($lesson, $data, $actor);
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
     * Enforces strict ID validation to prevent accidental deletion, foreign ID injection, or duplicate IDs.
     */
    public function syncQuestionsAndOptions(
        Exercise $exercise,
        array $questionsData
    ): void {
        $this->revisionService->assertExerciseCanBeModified($exercise);

        // Pre-validation: Strictly validate question and option IDs before modifying anything.
        $submittedQuestionIds = [];
        $existingQuestionIdsInDb = Question::query()
            ->where('center_id', $exercise->center_id)
            ->where('exercise_id', $exercise->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($questionsData as $qIndex => $qData) {
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
                $submittedOptionIds = [];
                $existingOptionIdsForQuestion = [];
                if ($qId !== null) {
                    $existingOptionIdsForQuestion = QuestionOption::query()
                        ->where('center_id', $exercise->center_id)
                        ->where('question_id', $qId)
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
                        // If question is new ($qId is null), it cannot have existing option IDs
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

        DB::transaction(function () use ($exercise, $questionsData, $submittedQuestionIds): void {
            // Delete questions not present in the payload
            Question::query()
                ->where('center_id', $exercise->center_id)
                ->where('exercise_id', $exercise->id)
                ->whereNotIn('id', $submittedQuestionIds)
                ->delete();

            foreach ($questionsData as $qIndex => $qData) {
                $qId = isset($qData['id']) && $qData['id'] !== null ? (int) $qData['id'] : null;
                $questionType = $qData['question_type'] ?? Question::TYPE_SINGLE_CHOICE;
                $points = $qData['points'] ?? 1.00;
                $sortOrder = $qData['sort_order'] ?? ($qIndex + 1);

                if ($qId !== null) {
                    $q = Question::query()
                        ->where('center_id', $exercise->center_id)
                        ->where('exercise_id', $exercise->id)
                        ->findOrFail($qId);

                    $q->update([
                        'question_type' => $questionType,
                        'prompt' => $qData['prompt'],
                        'explanation' => $qData['explanation'] ?? null,
                        'points' => $points,
                        'sort_order' => $sortOrder,
                    ]);
                } else {
                    $q = Question::query()->create([
                        'center_id' => $exercise->center_id,
                        'exercise_id' => $exercise->id,
                        'question_type' => $questionType,
                        'prompt' => $qData['prompt'],
                        'explanation' => $qData['explanation'] ?? null,
                        'points' => $points,
                        'sort_order' => $sortOrder,
                    ]);
                }

                if (isset($qData['options']) && is_array($qData['options'])) {
                    $submittedOptIds = array_values(array_filter(
                        array_map(fn ($o) => isset($o['id']) && $o['id'] !== null ? (int) $o['id'] : null, $qData['options']),
                        fn ($id) => $id !== null
                    ));

                    QuestionOption::query()
                        ->where('center_id', $exercise->center_id)
                        ->where('question_id', $q->id)
                        ->whereNotIn('id', $submittedOptIds)
                        ->delete();

                    foreach ($qData['options'] as $oIndex => $oData) {
                        $oId = isset($oData['id']) && $oData['id'] !== null ? (int) $oData['id'] : null;
                        $isCorrect = (bool) ($oData['is_correct'] ?? false);
                        $oSortOrder = $oData['sort_order'] ?? ($oIndex + 1);

                        if ($oId !== null) {
                            $opt = QuestionOption::query()
                                ->where('center_id', $exercise->center_id)
                                ->where('question_id', $q->id)
                                ->findOrFail($oId);

                            $opt->update([
                                'option_text' => $oData['option_text'],
                                'is_correct' => $isCorrect,
                                'sort_order' => $oSortOrder,
                            ]);
                        } else {
                            QuestionOption::query()->create([
                                'center_id' => $exercise->center_id,
                                'question_id' => $q->id,
                                'option_text' => $oData['option_text'],
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
     */
    public function publishExercise(
        Exercise $exercise,
        User $actor
    ): Exercise {
        $this->revisionService->assertExerciseCanBeModified($exercise);

        if (trim((string) $exercise->title) === '') {
            throw ValidationException::withMessages([
                'title' => ['An exercise title is required before publication.'],
            ]);
        }

        $questions = $exercise->questions()->with('options')->get();

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
        }

        $exercise->update([
            'status' => Exercise::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return $exercise->fresh(['questions.options']);
    }

    /**
     * Delete an exercise.
     */
    public function deleteExercise(Exercise $exercise): void
    {
        $this->revisionService->assertExerciseCanBeModified($exercise);

        DB::transaction(function () use ($exercise): void {
            // Delete options first, then questions, then exercise.
            QuestionOption::query()
                ->where('center_id', $exercise->center_id)
                ->whereIn(
                    'question_id',
                    $exercise->questions()->select('id')
                )
                ->delete();

            $exercise->questions()->delete();
            $exercise->delete();
        });
    }
}
