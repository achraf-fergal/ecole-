"use client";

import React, { useState, useEffect } from "react";
import type {
  LessonBlock,
  LearningRouteParams,
  ExerciseAttemptData,
  ExerciseCurrentAttemptState,
} from "@/lib/learning";
import {
  getExerciseCurrentAttempt,
  startExerciseAttempt,
  saveExerciseAttemptAnswers,
  submitExerciseAttempt,
  LearningApiError,
} from "@/lib/learning";

interface LessonExerciseBlockProps {
  block: LessonBlock;
  isAdminPreview?: boolean;
  params?: LearningRouteParams;
}

export default function LessonExerciseBlock({
  block,
  isAdminPreview = false,
  params,
}: LessonExerciseBlockProps) {
  const exercise = block.exercise;

  // Student assessment state
  const [attemptState, setAttemptState] =
    useState<ExerciseCurrentAttemptState | null>(null);
  const [activeAttempt, setActiveAttempt] =
    useState<ExerciseAttemptData | null>(null);
  const [selectedAnswers, setSelectedAnswers] = useState<
    Record<number, number>
  >({});
  const [isLoading, setIsLoading] = useState(
    () => !isAdminPreview && Boolean(params) && Boolean(exercise?.id)
  );
  const [isStarting, setIsStarting] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [saveSuccessMessage, setSaveSuccessMessage] = useState<string | null>(
    null
  );

  // Administrative preview selection state
  const [previewSelectedAnswers, setPreviewSelectedAnswers] = useState<
    Record<number, number>
  >({});

  const exerciseId = exercise?.id;

  useEffect(() => {
    if (isAdminPreview || !params || !exerciseId) return;

    let isMounted = true;

    async function fetchAttempt() {
      try {
        const state = await getExerciseCurrentAttempt(params!, exerciseId!);
        if (!isMounted) return;
        setAttemptState(state);
        if (state.active_attempt) {
          setActiveAttempt(state.active_attempt);
          const initialAnswers: Record<number, number> = {};
          state.active_attempt.questions.forEach((q) => {
            if (q.selected_option_id) {
              initialAnswers[q.id] = q.selected_option_id;
            }
          });
          setSelectedAnswers(initialAnswers);
        } else {
          setActiveAttempt(null);
        }
      } catch (err: unknown) {
        if (!isMounted) return;
        if (err instanceof LearningApiError) {
          setActionError(err.message);
        } else {
          setActionError("Failed to load assessment information.");
        }
      } finally {
        if (isMounted) {
          setIsLoading(false);
        }
      }
    }

    void fetchAttempt();

    return () => {
      isMounted = false;
    };
  }, [isAdminPreview, params, exerciseId]);

  if (!exercise) {
    return null;
  }

  // --- Student Assessment Handlers ---

  const handleStartAttempt = async () => {
    if (!params || !exerciseId) return;

    setIsStarting(true);
    setActionError(null);
    setSaveSuccessMessage(null);

    try {
      const newAttempt = await startExerciseAttempt(params, exerciseId);
      setActiveAttempt(newAttempt);
      const initialAnswers: Record<number, number> = {};
      newAttempt.questions.forEach((q) => {
        if (q.selected_option_id) {
          initialAnswers[q.id] = q.selected_option_id;
        }
      });
      setSelectedAnswers(initialAnswers);
      // Reload overall state to update attempts remaining
      const state = await getExerciseCurrentAttempt(params, exerciseId);
      setAttemptState(state);
    } catch (err: unknown) {
      if (err instanceof LearningApiError) {
        setActionError(err.message);
      } else {
        setActionError("Could not start assessment attempt.");
      }
    } finally {
      setIsStarting(false);
    }
  };

  const handleSelectOption = (questionId: number, optionId: number) => {
    if (isAdminPreview) {
      setPreviewSelectedAnswers((prev) => ({
        ...prev,
        [questionId]: optionId,
      }));
      return;
    }

    if (!activeAttempt || activeAttempt.status !== "in_progress") {
      return;
    }

    setSelectedAnswers((prev) => ({
      ...prev,
      [questionId]: optionId,
    }));
    setSaveSuccessMessage(null);
  };

  const handleSaveAnswers = async () => {
    if (!params || !exerciseId || !activeAttempt) return;

    setIsSaving(true);
    setActionError(null);
    setSaveSuccessMessage(null);

    try {
      const answersPayload = activeAttempt.questions.map((q) => ({
        question_id: q.id,
        selected_option_id: selectedAnswers[q.id] ?? null,
      }));

      const updated = await saveExerciseAttemptAnswers(
        params,
        exerciseId,
        answersPayload
      );
      setActiveAttempt(updated);
      setSaveSuccessMessage("Progress saved successfully.");
    } catch (err: unknown) {
      if (err instanceof LearningApiError) {
        setActionError(err.message);
      } else {
        setActionError("Failed to save progress.");
      }
    } finally {
      setIsSaving(false);
    }
  };

  const handleSubmitAttempt = async () => {
    if (!params || !exerciseId || !activeAttempt) return;

    const unansweredCount = activeAttempt.questions.filter(
      (q) => !selectedAnswers[q.id]
    ).length;

    if (
      unansweredCount > 0 &&
      !window.confirm(
        `You have ${unansweredCount} unanswered ${
          unansweredCount === 1 ? "question" : "questions"
        }. Do you want to submit your assessment anyway?`
      )
    ) {
      return;
    }

    setIsSubmitting(true);
    setActionError(null);
    setSaveSuccessMessage(null);

    try {
      const answersPayload = activeAttempt.questions.map((q) => ({
        question_id: q.id,
        selected_option_id: selectedAnswers[q.id] ?? null,
      }));

      await submitExerciseAttempt(
        params,
        exerciseId,
        answersPayload
      );
      setActiveAttempt(null);
      // Reload attempt state to display graded result and updated attempt counts
      const state = await getExerciseCurrentAttempt(params, exerciseId);
      setAttemptState(state);
    } catch (err: unknown) {
      if (err instanceof LearningApiError) {
        setActionError(err.message);
      } else {
        setActionError("Failed to submit assessment.");
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  // =========================================================================
  // VIEW: Admin Preview Mode
  // =========================================================================
  if (isAdminPreview || !params) {
    return (
      <article className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-xs">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-teal-50 text-xs font-bold text-[#0e8988]">
                ✓
              </span>
              <h4 className="text-lg font-black text-[#102b4c]">
                {exercise.title}
              </h4>
            </div>

            {exercise.instructions && (
              <p className="mt-2 text-xs leading-6 text-slate-600">
                {exercise.instructions}
              </p>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
              <span>{exercise.questions.length} questions</span>
              <span>·</span>
              <span>Passing score: {exercise.passing_score}%</span>
              {exercise.max_attempts && (
                <>
                  <span>·</span>
                  <span>Max attempts: {exercise.max_attempts}</span>
                </>
              )}
            </div>
          </div>

          <span className="rounded-md bg-amber-50 px-2.5 py-1 text-[10px] font-extrabold uppercase text-amber-700">
            {exercise.status} (Preview)
          </span>
        </div>

        {exercise.questions.length > 0 && (
          <div className="mt-5 space-y-5">
            {exercise.questions.map((question, questionIndex) => (
              <div
                key={question.id}
                className="rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:p-5"
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                    Question {questionIndex + 1} ({question.points}{" "}
                    {Number(question.points) === 1 ? "point" : "points"})
                  </span>
                  <span className="text-[10px] text-slate-400 capitalize">
                    {question.question_type.replace("_", " ")}
                  </span>
                </div>

                <p className="mt-2 text-sm font-semibold leading-6 text-[#102b4c]">
                  {question.prompt}
                </p>

                <div className="mt-4 space-y-2">
                  {question.options.map((option) => {
                    const isSelected =
                      previewSelectedAnswers[question.id] === option.id;

                    return (
                      <button
                        type="button"
                        key={option.id}
                        onClick={() =>
                          handleSelectOption(question.id, option.id)
                        }
                        className={`flex w-full items-center justify-between gap-3 rounded-xl border p-3.5 text-left text-xs transition ${
                          isSelected
                            ? "border-[#0e8988] bg-teal-50/40 text-[#102b4c] font-bold"
                            : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50/50"
                        }`}
                      >
                        <div className="flex items-center gap-3">
                          <span
                            className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                              isSelected
                                ? "border-[#0e8988] bg-[#0e8988] text-white"
                                : "border-slate-300 bg-white"
                            }`}
                          >
                            {isSelected && (
                              <span className="h-1.5 w-1.5 rounded-full bg-white" />
                            )}
                          </span>
                          <span>{option.option_text}</span>
                        </div>

                        {option.is_correct === true && (
                          <span className="rounded-md bg-emerald-50 px-2 py-0.5 text-[9px] font-extrabold text-emerald-700">
                            Correct Answer
                          </span>
                        )}
                      </button>
                    );
                  })}
                </div>

                {question.explanation && (
                  <div className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/80 p-3.5">
                    <p className="text-[10px] font-extrabold uppercase tracking-wide text-indigo-700">
                      Instructor Explanation
                    </p>
                    <p className="mt-1 text-xs leading-6 text-indigo-950">
                      {question.explanation}
                    </p>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </article>
    );
  }

  // =========================================================================
  // VIEW: Student Loading State
  // =========================================================================
  if (isLoading && !attemptState) {
    return (
      <article className="animate-pulse rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-xs">
        <div className="h-6 w-1/3 rounded-md bg-slate-200" />
        <div className="mt-3 h-4 w-2/3 rounded-md bg-slate-100" />
        <div className="mt-6 h-24 w-full rounded-xl bg-slate-100" />
      </article>
    );
  }

  // =========================================================================
  // VIEW: Active Attempt In Progress
  // =========================================================================
  if (activeAttempt && activeAttempt.status === "in_progress") {
    return (
      <article className="rounded-2xl border border-[#0e8988]/30 bg-white p-5 sm:p-7 shadow-xs">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-teal-50 text-xs font-bold text-[#0e8988]">
                📝
              </span>
              <h4 className="text-lg font-black text-[#102b4c]">
                {exercise.title} — Attempt #{activeAttempt.attempt_number}
              </h4>
            </div>

            {exercise.instructions && (
              <p className="mt-2 text-xs leading-6 text-slate-600">
                {exercise.instructions}
              </p>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
              <span>{activeAttempt.questions.length} questions</span>
              <span>·</span>
              <span>Passing score: {exercise.passing_score}%</span>
              <span>·</span>
              <span className="font-semibold text-teal-700">In Progress</span>
            </div>
          </div>

          <span className="rounded-full bg-teal-50 px-3 py-1 text-[10px] font-extrabold uppercase text-[#0e8988]">
            Assessment in Progress
          </span>
        </div>

        {actionError && (
          <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
            {actionError}
          </div>
        )}

        {saveSuccessMessage && (
          <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
            {saveSuccessMessage}
          </div>
        )}

        <div className="mt-5 space-y-5">
          {activeAttempt.questions.map((question, questionIndex) => (
            <div
              key={question.id}
              className="rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:p-5"
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                  Question {questionIndex + 1} ({question.points_possible}{" "}
                  {Number(question.points_possible) === 1 ? "point" : "points"})
                </span>
                {selectedAnswers[question.id] && (
                  <span className="text-[10px] font-bold text-teal-700">
                    Answered
                  </span>
                )}
              </div>

              <p className="mt-2 text-sm font-semibold leading-6 text-[#102b4c]">
                {question.prompt}
              </p>

              <div className="mt-4 space-y-2">
                {question.options.map((option) => {
                  const isSelected = selectedAnswers[question.id] === option.id;

                  return (
                    <button
                      type="button"
                      key={option.id}
                      onClick={() => handleSelectOption(question.id, option.id)}
                      className={`flex w-full items-center justify-between gap-3 rounded-xl border p-3.5 text-left text-xs transition ${
                        isSelected
                          ? "border-[#0e8988] bg-teal-50/40 text-[#102b4c] font-bold"
                          : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50/50"
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        <span
                          className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                            isSelected
                              ? "border-[#0e8988] bg-[#0e8988] text-white"
                              : "border-slate-300 bg-white"
                          }`}
                        >
                          {isSelected && (
                            <span className="h-1.5 w-1.5 rounded-full bg-white" />
                          )}
                        </span>
                        <span>{option.option_text}</span>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          ))}
        </div>

        <div className="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 pt-5">
          <button
            type="button"
            disabled={isSaving || isSubmitting}
            onClick={handleSaveAnswers}
            className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
          >
            {isSaving ? "Saving..." : "Save Progress"}
          </button>

          <button
            type="button"
            disabled={isSaving || isSubmitting}
            onClick={handleSubmitAttempt}
            className="rounded-xl bg-[#0e8988] px-5 py-2.5 text-xs font-bold text-white shadow-xs transition hover:bg-[#0c7776] disabled:opacity-50"
          >
            {isSubmitting ? "Submitting..." : "Submit Assessment"}
          </button>
        </div>
      </article>
    );
  }

  // =========================================================================
  // VIEW: Completed / Graded Attempt Review or Start Screen
  // =========================================================================
  const completedAttempt = attemptState?.latest_completed_attempt;
  const canStartNew = attemptState?.exercise.can_start_new_attempt ?? true;
  const attemptsRemaining = attemptState?.exercise.attempts_remaining;
  const attemptsCount = attemptState?.exercise.attempts_count ?? 0;
  const maxAttempts = attemptState?.exercise.max_attempts;

  return (
    <article className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-xs">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
        <div>
          <div className="flex items-center gap-2">
            <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-teal-50 text-xs font-bold text-[#0e8988]">
              ✓
            </span>
            <h4 className="text-lg font-black text-[#102b4c]">
              {exercise.title}
            </h4>
          </div>

          {exercise.instructions && (
            <p className="mt-2 text-xs leading-6 text-slate-600">
              {exercise.instructions}
            </p>
          )}

          <div className="mt-3 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
            <span>{exercise.questions.length} questions</span>
            <span>·</span>
            <span>Passing score: {exercise.passing_score}%</span>
            {maxAttempts && (
              <>
                <span>·</span>
                <span>Max attempts: {maxAttempts}</span>
              </>
            )}
            <span>·</span>
            <span>
              {attemptsCount} {attemptsCount === 1 ? "attempt" : "attempts"} taken
            </span>
          </div>
        </div>

        {completedAttempt ? (
          <span
            className={`rounded-full px-3 py-1 text-[10px] font-extrabold uppercase ${
              completedAttempt.passed
                ? "bg-emerald-50 text-emerald-700"
                : "bg-rose-50 text-rose-700"
            }`}
          >
            {completedAttempt.passed ? "Passed" : "Needs Review"}
          </span>
        ) : (
          <span className="rounded-full bg-[#e5f5f2] px-3 py-1 text-[10px] font-extrabold uppercase text-[#087b83]">
            Practice Assessment
          </span>
        )}
      </div>

      {actionError && (
        <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
          {actionError}
        </div>
      )}

      {/* Score Summary Card if completed attempt exists */}
      {completedAttempt && (
        <div className="mt-5 rounded-2xl border border-slate-100 bg-slate-50/80 p-5">
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div>
              <p className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                Latest Result (Attempt #{completedAttempt.attempt_number})
              </p>
              <div className="mt-1 flex items-baseline gap-2">
                <span className="text-2xl font-black text-[#102b4c]">
                  {completedAttempt.score ?? 0} / {completedAttempt.max_score}
                </span>
                <span className="text-sm font-bold text-slate-500">
                  ({completedAttempt.percentage ?? 0}%)
                </span>
              </div>
            </div>

            <div className="text-right">
              <span
                className={`inline-block rounded-xl px-3 py-1.5 text-xs font-black ${
                  completedAttempt.passed
                    ? "bg-emerald-100 text-emerald-800"
                    : "bg-rose-100 text-rose-800"
                }`}
              >
                {completedAttempt.passed
                  ? "✓ Assessment Passed"
                  : "✗ Passing Score Not Reached"}
              </span>
              <p className="mt-1 text-[10px] text-slate-400">
                Passing requirement: {exercise.passing_score}%
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Review of questions from completed attempt */}
      {completedAttempt && completedAttempt.questions.length > 0 && (
        <div className="mt-5 space-y-5">
          <h5 className="text-xs font-extrabold uppercase tracking-wider text-slate-600">
            Question Review
          </h5>

          {completedAttempt.questions.map((question, questionIndex) => (
            <div
              key={question.id}
              className="rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:p-5"
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                  Question {questionIndex + 1} ({question.points_possible}{" "}
                  {Number(question.points_possible) === 1 ? "point" : "points"})
                </span>
                {question.is_correct !== undefined && (
                  <span
                    className={`text-[10px] font-bold ${
                      question.is_correct
                        ? "text-emerald-700"
                        : "text-rose-700"
                    }`}
                  >
                    {question.is_correct
                      ? `+${question.points_awarded ?? question.points_possible} pts`
                      : "0 pts"}
                  </span>
                )}
              </div>

              <p className="mt-2 text-sm font-semibold leading-6 text-[#102b4c]">
                {question.prompt}
              </p>

              <div className="mt-4 space-y-2">
                {question.options.map((option) => {
                  const wasSelected =
                    question.selected_option_id === option.id;
                  const isCorrect = option.is_correct;

                  let optionBorder = "border-slate-200 bg-white text-slate-700";
                  if (wasSelected && isCorrect === true) {
                    optionBorder =
                      "border-emerald-300 bg-emerald-50/70 text-emerald-900 font-bold";
                  } else if (wasSelected && isCorrect === false) {
                    optionBorder =
                      "border-rose-300 bg-rose-50/70 text-rose-900 font-bold";
                  } else if (isCorrect === true) {
                    optionBorder =
                      "border-emerald-200 bg-emerald-50/40 text-emerald-900";
                  } else if (wasSelected) {
                    optionBorder =
                      "border-[#0e8988] bg-teal-50/40 text-[#102b4c] font-bold";
                  }

                  return (
                    <div
                      key={option.id}
                      className={`flex w-full items-center justify-between gap-3 rounded-xl border p-3.5 text-xs ${optionBorder}`}
                    >
                      <div className="flex items-center gap-3">
                        <span
                          className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                            wasSelected
                              ? "border-[#0e8988] bg-[#0e8988] text-white"
                              : "border-slate-300 bg-white"
                          }`}
                        >
                          {wasSelected && (
                            <span className="h-1.5 w-1.5 rounded-full bg-white" />
                          )}
                        </span>
                        <span>{option.option_text}</span>
                      </div>

                      <div className="flex items-center gap-2">
                        {wasSelected && (
                          <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[9px] font-semibold text-slate-600">
                            Your Answer
                          </span>
                        )}
                        {isCorrect === true && (
                          <span className="rounded-md bg-emerald-100 px-2 py-0.5 text-[9px] font-extrabold text-emerald-800">
                            ✓ Correct
                          </span>
                        )}
                        {wasSelected && isCorrect === false && (
                          <span className="rounded-md bg-rose-100 px-2 py-0.5 text-[9px] font-extrabold text-rose-800">
                            ✗ Incorrect
                          </span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>

              {question.explanation && (
                <div className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/80 p-3.5">
                  <p className="text-[10px] font-extrabold uppercase tracking-wide text-indigo-700">
                    Explanation
                  </p>
                  <p className="mt-1 text-xs leading-6 text-indigo-950">
                    {question.explanation}
                  </p>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Start or Retake Actions */}
      <div className="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 pt-5">
        <div className="text-xs text-slate-500">
          {attemptsRemaining !== null && attemptsRemaining !== undefined ? (
            <span>
              {attemptsRemaining}{" "}
              {attemptsRemaining === 1 ? "attempt" : "attempts"} remaining
            </span>
          ) : (
            <span>Unlimited attempts allowed</span>
          )}
        </div>

        {canStartNew ? (
          <button
            type="button"
            disabled={isStarting}
            onClick={handleStartAttempt}
            className="rounded-xl bg-[#0e8988] px-5 py-2.5 text-xs font-bold text-white shadow-xs transition hover:bg-[#0c7776] disabled:opacity-50"
          >
            {isStarting
              ? "Starting..."
              : completedAttempt
              ? "Retake Assessment"
              : "Start Assessment"}
          </button>
        ) : (
          <span className="text-xs font-bold text-slate-400">
            Maximum attempts reached
          </span>
        )}
      </div>
    </article>
  );
}
