"use client";

import React, { useState, useEffect, useRef, useCallback } from "react";
import type {
  LessonBlock,
  LearningRouteParams,
  ExerciseAttemptData,
  AttemptQuestion,
  SaveAttemptAnswerPayload,
  ExerciseCurrentAttemptState,
  PaginatedExerciseAttempts,
} from "@/lib/learning";
import {
  getExerciseCurrentAttempt,
  startExerciseAttempt,
  saveExerciseAttemptAnswers,
  submitExerciseAttempt,
  getExerciseAttemptHistory,
  LearningApiError,
} from "@/lib/learning";
import { isQuestionAnswered, toAttemptAnswerPayload } from "@/lib/exercise-authoring.mjs";

type AnswerValue = number | number[] | string | Record<string, string> | null;

function answersFromAttempt(questions: AttemptQuestion[]): Record<number, AnswerValue> {
  return Object.fromEntries(questions.map((question) => {
    if (question.question_type === "multiple_select") {
      return [question.id, question.selected_option_ids ?? []];
    }
    if (question.question_type === "short_answer") {
      return [question.id, question.text_answer ?? ""];
    }
    if (question.question_type === "fill_in_the_blanks") {
      if (question.blank_answers && typeof question.blank_answers === "object") {
        return [question.id, question.blank_answers];
      }
      if (question.text_answer) {
        try {
          const parsed = JSON.parse(question.text_answer);
          if (typeof parsed === "object" && parsed !== null) {
            return [question.id, parsed];
          }
        } catch {}
      }
      return [question.id, {}];
    }
    return [question.id, question.selected_option_id];
  }));
}

function attemptAnswerPayload(
  questions: AttemptQuestion[],
  answers: Record<number, AnswerValue>
): SaveAttemptAnswerPayload[] {
  return questions.map((question) =>
    toAttemptAnswerPayload(question.id, question.question_type, answers[question.id])
  );
}

function answerSignature(payload: SaveAttemptAnswerPayload[]): string {
  return JSON.stringify(payload.map((answer) => ({
    ...answer,
    selected_option_ids: answer.selected_option_ids
      ? [...answer.selected_option_ids].sort((left, right) => left - right)
      : answer.selected_option_ids,
    blank_answers: answer.blank_answers
      ? Object.keys(answer.blank_answers).sort().reduce((acc, key) => {
          acc[key] = (answer.blank_answers as Record<string, string>)[key];
          return acc;
        }, {} as Record<string, string>)
      : answer.blank_answers,
  })));
}

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
    Record<number, AnswerValue>
  >({});
  const latestAnswersRef = useRef(selectedAnswers);
  const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
  const lastSavedSignatureRef = useRef<string | null>(null);
  const autoSaveTimerRef = useRef<number | null>(null);
  const saveInFlightRef = useRef(false);
  const submitInFlightRef = useRef(false);
  const autoSaveRetryRef = useRef<{ signature: string; count: number } | null>(null);
  const saveHandlerRef = useRef<(quiet: boolean) => Promise<void>>(async () => {});
  const [isLoading, setIsLoading] = useState(
    () => !isAdminPreview && Boolean(params) && Boolean(exercise?.id)
  );
  const [initError, setInitError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [isStarting, setIsStarting] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [saveSuccessMessage, setSaveSuccessMessage] = useState<string | null>(
    null
  );

  // Attempt history state
  const [historyData, setHistoryData] = useState<PaginatedExerciseAttempts | null>(null);
  const [historyPage, setHistoryPage] = useState(1);
  const [isLoadingHistory, setIsLoadingHistory] = useState(false);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const [isHistoryOpen, setIsHistoryOpen] = useState(false);
  const [inspectingAttemptId, setInspectingAttemptId] = useState<number | null>(
    null
  );

  // Administrative preview selection state
  const [previewSelectedAnswers, setPreviewSelectedAnswers] = useState<
    Record<number, AnswerValue>
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
        setInitError(null);
        if (state.active_attempt) {
          setActiveAttempt(state.active_attempt);
          const initialAnswers = answersFromAttempt(state.active_attempt.questions);
          latestAnswersRef.current = initialAnswers;
          setSelectedAnswers(initialAnswers);
          lastSavedSignatureRef.current = answerSignature(
            attemptAnswerPayload(state.active_attempt.questions, initialAnswers)
          );
        } else {
          setActiveAttempt(null);
        }
      } catch (err: unknown) {
        if (!isMounted) return;
        if (err instanceof LearningApiError) {
          setInitError(err.message);
        } else {
          setInitError("Failed to load assessment information.");
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
  }, [isAdminPreview, params, exerciseId, reloadKey]);

  // --- Student Assessment Handlers ---

  const handleRetryInit = () => {
    setIsLoading(true);
    setInitError(null);
    setReloadKey((k) => k + 1);
  };

  const canStartNew = Boolean(attemptState?.exercise.can_start_new_attempt);
  const answeredCount = activeAttempt
    ? activeAttempt.questions.filter((question) =>
        isQuestionAnswered(question.question_type, selectedAnswers[question.id])
      ).length
    : 0;

  const navigateToQuestion = (index: number) => {
    if (!activeAttempt) return;
    const boundedIndex = Math.min(Math.max(index, 0), activeAttempt.questions.length - 1);
    setCurrentQuestionIndex(boundedIndex);
    window.setTimeout(() => {
      document.getElementById(`attempt-question-${activeAttempt.questions[boundedIndex]?.id}`)
        ?.scrollIntoView({ behavior: "smooth", block: "center" });
    }, 0);
  };

  const handleStartAttempt = async () => {
    if (isStarting || !params || !exerciseId || !canStartNew) return;

    setIsStarting(true);
    setActionError(null);
    setSaveSuccessMessage(null);

    try {
      const newAttempt = await startExerciseAttempt(params, exerciseId);
      setActiveAttempt(newAttempt);
      const initialAnswers = answersFromAttempt(newAttempt.questions);
      latestAnswersRef.current = initialAnswers;
      setSelectedAnswers(initialAnswers);
      lastSavedSignatureRef.current = answerSignature(
        attemptAnswerPayload(newAttempt.questions, initialAnswers)
      );
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

  const handleSelectOption = (questionId: number, optionId: number, questionType: string) => {
    const update = (previous: Record<number, AnswerValue>) => {
      if (questionType === "multiple_select") {
        const current = Array.isArray(previous[questionId]) ? previous[questionId] as number[] : [];
        return {
          ...previous,
          [questionId]: current.includes(optionId)
            ? current.filter((id) => id !== optionId)
            : [...current, optionId],
        };
      }

      return { ...previous, [questionId]: optionId };
    };

    if (isAdminPreview) {
      setPreviewSelectedAnswers(update);
      return;
    }

    if (!activeAttempt || activeAttempt.status !== "in_progress") {
      return;
    }

    const updated = update(selectedAnswers);
    latestAnswersRef.current = updated;
    setSelectedAnswers(updated);
    setSaveSuccessMessage(null);
  };

  const handleSelectTextAnswer = (questionId: number, value: string) => {
    if (isAdminPreview) {
      setPreviewSelectedAnswers((previous) => ({ ...previous, [questionId]: value }));
      return;
    }
    if (!activeAttempt || activeAttempt.status !== "in_progress") return;
    const updated = { ...selectedAnswers, [questionId]: value };
    latestAnswersRef.current = updated;
    setSelectedAnswers(updated);
    setSaveSuccessMessage(null);
  };

  const handleBlankAnswer = (questionId: number, blankId: string, value: string) => {
    const update = (previous: Record<number, AnswerValue>) => {
      const current = (typeof previous[questionId] === "object" && previous[questionId] !== null
        ? previous[questionId]
        : {}) as Record<string, string>;
      return {
        ...previous,
        [questionId]: {
          ...current,
          [blankId]: value,
        },
      };
    };

    if (isAdminPreview) {
      setPreviewSelectedAnswers(update);
      return;
    }
    if (!activeAttempt || activeAttempt.status !== "in_progress") return;
    const updated = update(selectedAnswers);
    latestAnswersRef.current = updated;
    setSelectedAnswers(updated);
    setSaveSuccessMessage(null);
  };

  const handleSaveAnswers = useCallback(async (quiet = false) => {
    if (!params || !exerciseId || !activeAttempt) return;
    if (saveInFlightRef.current) return;

    const answersPayload = attemptAnswerPayload(activeAttempt.questions, latestAnswersRef.current);
    const signature = answerSignature(answersPayload);
    if (signature === lastSavedSignatureRef.current) return;

    saveInFlightRef.current = true;
    setIsSaving(true);
    if (!quiet) {
      setActionError(null);
      setSaveSuccessMessage(null);
    }

    let wasSaved = false;
    try {
      const updated = await saveExerciseAttemptAnswers(
        params,
        exerciseId,
        answersPayload
      );
      setActiveAttempt(updated);
      lastSavedSignatureRef.current = signature;
      autoSaveRetryRef.current = null;
      wasSaved = true;
      setActionError(null);
      setSaveSuccessMessage("Progress saved successfully.");
    } catch (err: unknown) {
      if (err instanceof LearningApiError) {
        setActionError(err.message);
      } else {
        setActionError("Failed to save progress.");
      }

      if (quiet) {
        if (autoSaveRetryRef.current?.signature !== signature) {
          autoSaveRetryRef.current = { signature, count: 0 };
        }
        if (autoSaveRetryRef.current.count < 1) {
          autoSaveRetryRef.current.count += 1;
          autoSaveTimerRef.current = window.setTimeout(() => {
            void saveHandlerRef.current(true);
          }, 1500);
        }
      }
    } finally {
      saveInFlightRef.current = false;
      setIsSaving(false);

      const latestSignature = answerSignature(
        attemptAnswerPayload(activeAttempt.questions, latestAnswersRef.current)
      );
      if (
        wasSaved
        && latestSignature !== lastSavedSignatureRef.current
        && activeAttempt.status === "in_progress"
      ) {
        autoSaveTimerRef.current = window.setTimeout(() => {
          void saveHandlerRef.current(true);
        }, 100);
      }
    }
  }, [params, exerciseId, activeAttempt]);

  useEffect(() => {
    saveHandlerRef.current = handleSaveAnswers;
  }, [handleSaveAnswers]);

  useEffect(() => {
    if (
      isAdminPreview
      || !activeAttempt
      || activeAttempt.status !== "in_progress"
      || isSubmitting
    ) return;

    const payload = attemptAnswerPayload(activeAttempt.questions, selectedAnswers);
    const signature = answerSignature(payload);
    if (signature === lastSavedSignatureRef.current) return;

    autoSaveTimerRef.current = window.setTimeout(() => {
      void saveHandlerRef.current(true);
    }, 700);

    return () => {
      if (autoSaveTimerRef.current !== null) {
        window.clearTimeout(autoSaveTimerRef.current);
      }
    };
  }, [activeAttempt, selectedAnswers, isAdminPreview, isSubmitting]);

  if (!exercise) {
    return null;
  }

  const handleSubmitAttempt = async () => {
    if (!params || !exerciseId || !activeAttempt) return;
    if (submitInFlightRef.current || isSubmitting || isSaving) return;

    if (autoSaveTimerRef.current !== null) window.clearTimeout(autoSaveTimerRef.current);
    const unansweredCount = activeAttempt.questions.filter((question) =>
      !isQuestionAnswered(question.question_type, selectedAnswers[question.id])
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
    submitInFlightRef.current = true;
    setActionError(null);
    setSaveSuccessMessage(null);

    try {
      const answersPayload = attemptAnswerPayload(activeAttempt.questions, latestAnswersRef.current);

      await submitExerciseAttempt(
        params,
        exerciseId,
        activeAttempt.id,
        answersPayload
      );
      setActiveAttempt(null);
      setHistoryData(null);
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
      submitInFlightRef.current = false;
      setIsSubmitting(false);
    }
  };

  const fetchHistoryPage = async (page: number) => {
    if (!params || !exerciseId) return;

    setIsLoadingHistory(true);
    setHistoryError(null);
    try {
      const paginated = await getExerciseAttemptHistory(params, exerciseId, page);
      setHistoryData(paginated);
      setHistoryPage(paginated.meta.current_page);
    } catch (err: unknown) {
      if (err instanceof LearningApiError) {
        setHistoryError(err.message);
      } else {
        setHistoryError("Failed to load attempt history.");
      }
    } finally {
      setIsLoadingHistory(false);
    }
  };

  const handleToggleHistory = async () => {
    if (isHistoryOpen) {
      setIsHistoryOpen(false);
      return;
    }

    setIsHistoryOpen(true);
    if (historyData !== null) return;
    await fetchHistoryPage(1);
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

                {question.question_type === "fill_in_the_blanks" ? (
                  <div className="mt-4 leading-relaxed text-sm font-semibold text-[#102b4c]">
                    {question.prompt.split(/(\[[a-zA-Z0-9_\-]+\])/g).map((part, pIdx) => {
                      const match = part.match(/^\[([a-zA-Z0-9_\-]+)\]$/);
                      if (match) {
                        const blankId = match[1];
                        const blankValues = (typeof previewSelectedAnswers[question.id] === "object" && previewSelectedAnswers[question.id] !== null
                          ? previewSelectedAnswers[question.id]
                          : {}) as Record<string, string>;
                        return (
                          <input
                            key={`preview-blank-${blankId}-${pIdx}`}
                            type="text"
                            data-blank-id={blankId}
                            aria-label={`Preview blank ${blankId}`}
                            value={blankValues[blankId] ?? ""}
                            onChange={(e) => handleBlankAnswer(question.id, blankId, e.target.value)}
                            className="mx-1.5 inline-block w-36 rounded-lg border border-[#0e8988]/60 bg-white px-2.5 py-1 text-sm font-normal text-slate-800 shadow-inner focus:border-[#0e8988] focus:ring-1 focus:ring-[#0e8988]"
                          />
                        );
                      }
                      return <span key={pIdx}>{part}</span>;
                    })}
                  </div>
                ) : question.question_type === "short_answer" ? (
                  <label className="mt-4 block text-xs font-semibold text-slate-700">
                    Your answer
                    <input type="text" value={typeof previewSelectedAnswers[question.id] === "string" ? previewSelectedAnswers[question.id] as string : ""} onChange={(event) => handleSelectTextAnswer(question.id, event.target.value)} className="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm" />
                  </label>
                ) : (
                  <fieldset className="mt-4 space-y-2" role={question.question_type === "multiple_select" ? "group" : "radiogroup"} aria-label={`Preview question ${questionIndex + 1} answers`}>
                    {question.question_type === "multiple_select" && <legend className="mb-2 text-xs text-slate-600">Select all that apply.</legend>}
                    {question.options.map((option) => {
                      const answer = previewSelectedAnswers[question.id];
                      const isSelected = question.question_type === "multiple_select"
                        ? Array.isArray(answer) && answer.includes(option.id)
                        : answer === option.id;
                      return (
                        <label key={option.id} className={`flex items-center justify-between gap-3 rounded-xl border p-3.5 text-xs ${isSelected ? "border-[#0e8988] bg-teal-50/40 font-bold text-[#102b4c]" : "border-slate-200 bg-white text-slate-700"}`}>
                          <span className="flex items-center gap-3">
                            <input type={question.question_type === "multiple_select" ? "checkbox" : "radio"} name={`preview-answer-${question.id}`} checked={isSelected} onChange={() => handleSelectOption(question.id, option.id, question.question_type)} className="h-4 w-4 accent-[#0e8988]" />
                            {option.option_text}
                          </span>
                          {option.is_correct === true && <span className="rounded-md bg-emerald-50 px-2 py-0.5 text-[9px] font-extrabold text-emerald-700">Correct Answer</span>}
                        </label>
                      );
                    })}
                  </fieldset>
                )}

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

        <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
            <strong aria-live="polite">Question {Math.min(currentQuestionIndex + 1, activeAttempt.questions.length)} of {activeAttempt.questions.length}</strong>
            <span>{answeredCount} answered · {activeAttempt.questions.length - answeredCount} unanswered</span>
          </div>
          <div
            className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"
            role="progressbar"
            aria-label="Assessment progress"
            aria-valuemin={0}
            aria-valuemax={activeAttempt.questions.length}
            aria-valuenow={answeredCount}
          >
            <div className="h-full rounded-full bg-[#0e8988] transition-[width]" style={{ width: `${activeAttempt.questions.length ? (answeredCount / activeAttempt.questions.length) * 100 : 0}%` }} />
          </div>
          <nav aria-label="Question navigation" className="mt-3 flex flex-wrap gap-2">
            {activeAttempt.questions.map((question, index) => {
              const answered = isQuestionAnswered(question.question_type, selectedAnswers[question.id]);
              return (
                <button
                  key={question.id}
                  type="button"
                  aria-label={`Question ${index + 1}, ${answered ? "answered" : "unanswered"}`}
                  aria-current={index === currentQuestionIndex ? "step" : undefined}
                  onClick={() => navigateToQuestion(index)}
                  className={`min-w-10 rounded-lg border px-3 py-2 text-xs font-bold ${index === currentQuestionIndex ? "border-[#0e8988] bg-teal-50 text-teal-900" : "border-slate-300 bg-white text-slate-700"}`}
                >
                  {index + 1} · {answered ? "Answered" : "Open"}
                </button>
              );
            })}
          </nav>
          <div className="mt-3 flex justify-between gap-2">
            <button type="button" disabled={currentQuestionIndex <= 0} onClick={() => navigateToQuestion(currentQuestionIndex - 1)} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40">Previous question</button>
            <button type="button" disabled={currentQuestionIndex >= activeAttempt.questions.length - 1} onClick={() => navigateToQuestion(currentQuestionIndex + 1)} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40">Next question</button>
          </div>
        </div>

        <div className="mt-5 space-y-5">
          {activeAttempt.questions.map((question, questionIndex) => (
            <div
              key={question.id}
              id={`attempt-question-${question.id}`}
              aria-labelledby={`attempt-question-title-${question.id}`}
              onFocusCapture={() => setCurrentQuestionIndex(questionIndex)}
              className="rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:p-5"
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                  Question {questionIndex + 1} ({question.points_possible}{" "}
                  {Number(question.points_possible) === 1 ? "point" : "points"})
                </span>
                {isQuestionAnswered(question.question_type, selectedAnswers[question.id]) && (
                  <span className="text-[10px] font-bold text-teal-700">
                    Answered
                  </span>
                )}
              </div>

              <p id={`attempt-question-title-${question.id}`} className="mt-2 text-sm font-semibold leading-6 text-[#102b4c]">
                {question.prompt}
              </p>

              {question.question_type === "fill_in_the_blanks" ? (
                <div className="mt-4 leading-relaxed text-sm font-semibold text-[#102b4c]">
                  {question.prompt.split(/(\[[a-zA-Z0-9_\-]+\])/g).map((part, pIdx) => {
                    const match = part.match(/^\[([a-zA-Z0-9_\-]+)\]$/);
                    if (match) {
                      const blankId = match[1];
                      const blankValues = (typeof selectedAnswers[question.id] === "object" && selectedAnswers[question.id] !== null
                        ? selectedAnswers[question.id]
                        : {}) as Record<string, string>;
                      return (
                        <input
                          key={`attempt-blank-${blankId}-${pIdx}`}
                          type="text"
                          data-blank-id={blankId}
                          aria-label={`Blank ${blankId}`}
                          maxLength={5000}
                          value={blankValues[blankId] ?? ""}
                          disabled={isSaving || isSubmitting}
                          onChange={(e) => handleBlankAnswer(question.id, blankId, e.target.value)}
                          className="mx-1.5 inline-block w-36 rounded-lg border border-[#0e8988]/60 bg-white px-2.5 py-1 text-sm font-normal text-slate-800 shadow-inner focus:border-[#0e8988] focus:ring-1 focus:ring-[#0e8988]"
                        />
                      );
                    }
                    return <span key={pIdx}>{part}</span>;
                  })}
                  <p className="mt-2 text-[11px] font-normal text-slate-500">Fill in each blank field.</p>
                </div>
              ) : question.question_type === "short_answer" ? (
                <label className="mt-4 block text-xs font-semibold text-slate-700">
                  Your answer
                  <input
                    type="text"
                    maxLength={5000}
                    value={typeof selectedAnswers[question.id] === "string" ? selectedAnswers[question.id] as string : ""}
                    onChange={(event) => handleSelectTextAnswer(question.id, event.target.value)}
                    aria-describedby={`answer-guide-${question.id}`}
                    className="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm"
                  />
                  <span id={`answer-guide-${question.id}`} className="mt-1 block text-[11px] font-normal text-slate-500">Enter a short answer. Matching follows the teacher&apos;s configured rules.</span>
                </label>
              ) : (
                <fieldset className="mt-4 space-y-2" role={question.question_type === "multiple_select" ? "group" : "radiogroup"} aria-label={question.question_type === "multiple_select" ? `Question ${questionIndex + 1} answers, select all that apply` : `Question ${questionIndex + 1} answers`}>
                  {question.question_type === "multiple_select" && <legend className="mb-2 text-xs font-semibold text-slate-600">Select all that apply.</legend>}
                  {question.options.map((option) => {
                    const answer = selectedAnswers[question.id];
                    const isSelected = question.question_type === "multiple_select"
                      ? Array.isArray(answer) && answer.includes(option.id)
                      : answer === option.id;

                    return (
                      <label key={option.id} className={`flex cursor-pointer items-center gap-3 rounded-xl border p-3.5 text-xs transition ${isSelected ? "border-[#0e8988] bg-teal-50/40 font-bold text-[#102b4c]" : "border-slate-200 bg-white text-slate-700 hover:border-slate-300"}`}>
                        <input
                          type={question.question_type === "multiple_select" ? "checkbox" : "radio"}
                          name={`answer-${question.id}`}
                          value={option.id}
                          data-option-id={option.id}
                          checked={isSelected}
                          disabled={isSaving || isSubmitting}
                          onChange={() => handleSelectOption(question.id, option.id, question.question_type)}
                          className="h-4 w-4 accent-[#0e8988]"
                        />
                        <span>{option.option_text}</span>
                      </label>
                    );
                  })}
                </fieldset>
              )}
            </div>
          ))}
        </div>

        <div className="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 pt-5">
          <button
            type="button"
            disabled={isSaving || isSubmitting}
            onClick={() => void handleSaveAnswers()}
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
  // VIEW: Student Loading or Init Error Mode
  // =========================================================================
  if (!isAdminPreview && isLoading) {
    return (
      <article className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-xs">
        <div className="flex items-center gap-3">
          <span className="h-4 w-4 animate-spin rounded-full border-2 border-[#0e8988] border-t-transparent" />
          <p className="text-xs font-semibold text-slate-500">Loading assessment...</p>
        </div>
      </article>
    );
  }

  if (!isAdminPreview && initError && !attemptState) {
    return (
      <article className="rounded-2xl border border-rose-200 bg-rose-50/60 p-5 sm:p-7 shadow-xs">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h4 className="text-sm font-bold text-rose-800">Assessment Unavailable</h4>
            <p className="mt-1 text-xs text-rose-700">{initError}</p>
          </div>
          <button
            type="button"
            onClick={handleRetryInit}
            className="rounded-xl border border-rose-300 bg-white px-3.5 py-1.5 text-xs font-bold text-rose-800 transition hover:bg-rose-50 shadow-xs"
          >
            Retry
          </button>
        </div>
      </article>
    );
  }

  // =========================================================================
  // VIEW: Completed / Graded Attempt Review or Start Screen
  // =========================================================================
  const completedAttempt = attemptState?.latest_completed_attempt;
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
            {completedAttempt.passed ? "Passed" : "Failed"}
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
                {completedAttempt.show_correct_answers && question.is_correct !== undefined && (
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

              {question.question_type === "fill_in_the_blanks" ? (
                <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-xs space-y-2">
                  <div className="font-semibold text-slate-800 leading-relaxed">
                    {question.prompt.split(/(\[[a-zA-Z0-9_\-]+\])/g).map((part, pIdx) => {
                      const match = part.match(/^\[([a-zA-Z0-9_\-]+)\]$/);
                      if (match) {
                        const blankId = match[1];
                        const fb = (question.blanks ?? []).find((b) => b.id === blankId);
                        const studentAns = fb?.student_answer ?? question.blank_answers?.[blankId] ?? "—";
                        const isCorrect = fb?.is_correct;
                        return (
                          <span
                            key={pIdx}
                            className={`mx-1 inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs ${
                              isCorrect === true
                                ? "border-emerald-300 bg-emerald-50 text-emerald-900 font-bold"
                                : isCorrect === false
                                ? "border-rose-300 bg-rose-50 text-rose-900 font-bold"
                                : "border-slate-300 bg-slate-50 text-slate-700"
                            }`}
                          >
                            <span>{studentAns || "—"}</span>
                            {completedAttempt.show_correct_answers && isCorrect !== undefined && (
                              <span className={isCorrect ? "text-emerald-700" : "text-rose-700"}>
                                {isCorrect ? "✓" : "✗"}
                              </span>
                            )}
                          </span>
                        );
                      }
                      return <span key={pIdx}>{part}</span>;
                    })}
                  </div>
                  {completedAttempt.show_correct_answers && question.blanks && question.blanks.length > 0 && (
                    <div className="mt-2 space-y-1 border-t border-slate-100 pt-2 text-[11px] text-slate-600">
                      {question.blanks.map((b) => (
                        <div key={b.id} className="flex flex-wrap items-center gap-2">
                          <span className="font-bold text-slate-700">[{b.id}]:</span>
                          <span>Accepted: {b.accepted_answers.join(" · ")}</span>
                          <span className="text-[10px] text-slate-400">({b.points_awarded} / {b.points_possible} pts)</span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              ) : question.question_type === "short_answer" ? (
                <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-xs">
                  <p><span className="font-bold">Your answer:</span> {question.text_answer || "No answer"}</p>
                  {completedAttempt.show_correct_answers && question.accepted_answers && (
                    <p className="mt-2"><span className="font-bold">Accepted answers:</span> {question.accepted_answers.join(" · ")}</p>
                  )}
                </div>
              ) : (

              <div className="mt-4 space-y-2">
                {question.options.map((option) => {
                  const wasSelected = question.selected_option_id === option.id
                    || (question.selected_option_ids ?? []).includes(option.id);
                  const isCorrect = completedAttempt.show_correct_answers
                    ? question.correct_option_ids
                      ? question.correct_option_ids.includes(option.id)
                      : question.correct_option_id === option.id
                    : undefined;

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
              )}

              {completedAttempt.show_correct_answers && question.explanation && (
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

      {/* Attempt History Section */}
      {attemptsCount > 0 && (
        <div className="mt-6 border-t border-slate-100 pt-5">
          <button
            type="button"
            onClick={() => void handleToggleHistory()}
            className="flex items-center gap-2 text-xs font-bold text-[#0e8988] hover:text-[#0c7776]"
          >
            <span>{isHistoryOpen ? "▼" : "▶"}</span>
            <span>
              View Attempt History ({attemptsCount}{" "}
              {attemptsCount === 1 ? "attempt" : "attempts"})
            </span>
          </button>

          {isHistoryOpen && (
            <div className="mt-4 space-y-3">
              {isLoadingHistory && (
                <div className="flex items-center justify-center gap-2 rounded-xl border border-slate-100 bg-slate-50 p-4 text-xs text-slate-500">
                  <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-[#0e8988] border-t-transparent" />
                  <span>Loading attempt history...</span>
                </div>
              )}

              {historyError && (
                <div className="flex items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                  <span>{historyError}</span>
                  <button
                    type="button"
                    onClick={() => void fetchHistoryPage(historyPage)}
                    className="rounded-lg border border-rose-300 bg-white px-2.5 py-1 font-bold text-rose-800 hover:bg-rose-50"
                  >
                    Retry
                  </button>
                </div>
              )}

              {historyData &&
                historyData.data.length === 0 &&
                !isLoadingHistory && (
                  <p className="text-xs italic text-slate-500">
                    No attempts recorded yet.
                  </p>
                )}

              {historyData &&
                historyData.data.map((hist) => {
                  const isInspecting = inspectingAttemptId === hist.id;

                  return (
                    <div
                      key={hist.id}
                      className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 transition"
                    >
                      <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                          <div className="flex items-center gap-2">
                            <span className="text-xs font-black text-[#102b4c]">
                              Attempt #{hist.attempt_number}
                            </span>
                            <span
                              className={`rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase ${
                                hist.passed === true
                                  ? "bg-emerald-50 text-emerald-700"
                                  : "bg-rose-50 text-rose-700"
                              }`}
                            >
                              {hist.passed === true ? "Passed" : "Failed"}
                            </span>
                          </div>

                          <p className="mt-1 text-[11px] text-slate-500">
                            {hist.submitted_at
                              ? `Submitted on ${new Date(
                                  hist.submitted_at
                                ).toLocaleDateString()} at ${new Date(
                                  hist.submitted_at
                                ).toLocaleTimeString([], {
                                  hour: "2-digit",
                                  minute: "2-digit",
                                })}`
                              : `Started on ${new Date(
                                  hist.started_at
                                ).toLocaleDateString()}`}
                          </p>
                        </div>

                        <div className="flex items-center gap-4">
                          <div className="text-right">
                            <span className="text-sm font-black text-[#102b4c]">
                              {hist.score ?? 0} / {hist.max_score}
                            </span>
                            <span className="ml-1 text-xs text-slate-500">
                              ({hist.percentage ?? 0}%)
                            </span>
                          </div>

                          <button
                            type="button"
                            onClick={() =>
                              setInspectingAttemptId(
                                isInspecting ? null : hist.id
                              )
                            }
                            className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-bold text-slate-700 hover:bg-slate-100"
                          >
                            {isInspecting ? "Close Review" : "Review Answers"}
                          </button>
                        </div>
                      </div>

                      {/* Detailed Review for this historical attempt */}
                      {isInspecting && hist.questions.length > 0 && (
                        <div className="mt-4 space-y-4 border-t border-slate-200/60 pt-4">
                          {hist.questions.map((q, qIdx) => (
                            <div
                              key={q.id}
                              className="rounded-lg border border-slate-100 bg-white p-3.5"
                            >
                              <div className="flex items-center justify-between gap-2">
                                <span className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                                  Question {qIdx + 1} ({q.points_possible}{" "}
                                  {Number(q.points_possible) === 1
                                    ? "point"
                                    : "points"}
                                  )
                                </span>
                                {hist.show_correct_answers && q.is_correct !== undefined && (
                                  <span
                                    className={`text-[10px] font-bold ${
                                      q.is_correct
                                        ? "text-emerald-700"
                                        : "text-rose-700"
                                    }`}
                                  >
                                    {q.is_correct
                                      ? `+${
                                          q.points_awarded ?? q.points_possible
                                        } pts`
                                      : "0 pts"}
                                  </span>
                                )}
                              </div>

                              <p className="mt-1 text-xs font-semibold leading-5 text-[#102b4c]">
                                {q.prompt}
                              </p>

                              {q.question_type === "fill_in_the_blanks" ? (
                                <div className="mt-2 rounded-lg border border-slate-200 bg-white p-2.5 text-xs space-y-1.5">
                                  <div className="font-semibold text-slate-800 leading-relaxed">
                                    {q.prompt.split(/(\[[a-zA-Z0-9_\-]+\])/g).map((part, pIdx) => {
                                      const match = part.match(/^\[([a-zA-Z0-9_\-]+)\]$/);
                                      if (match) {
                                        const blankId = match[1];
                                        const fb = (q.blanks ?? []).find((b) => b.id === blankId);
                                        const studentAns = fb?.student_answer ?? q.blank_answers?.[blankId] ?? "—";
                                        const isCorrect = fb?.is_correct;
                                        return (
                                          <span
                                            key={pIdx}
                                            className={`mx-1 inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs ${
                                              isCorrect === true
                                                ? "border-emerald-300 bg-emerald-50 text-emerald-900 font-bold"
                                                : isCorrect === false
                                                ? "border-rose-300 bg-rose-50 text-rose-900 font-bold"
                                                : "border-slate-300 bg-slate-50 text-slate-700"
                                            }`}
                                          >
                                            <span>{studentAns || "—"}</span>
                                            {hist.show_correct_answers && isCorrect !== undefined && (
                                              <span className={isCorrect ? "text-emerald-700" : "text-rose-700"}>
                                                {isCorrect ? "✓" : "✗"}
                                              </span>
                                            )}
                                          </span>
                                        );
                                      }
                                      return <span key={pIdx}>{part}</span>;
                                    })}
                                  </div>
                                  {hist.show_correct_answers && q.blanks && q.blanks.length > 0 && (
                                    <div className="mt-1 space-y-0.5 border-t border-slate-100 pt-1 text-[10px] text-slate-600">
                                      {q.blanks.map((b) => (
                                        <div key={b.id} className="flex flex-wrap items-center gap-1.5">
                                          <span className="font-bold text-slate-700">[{b.id}]:</span>
                                          <span>Accepted: {b.accepted_answers.join(" · ")}</span>
                                        </div>
                                      ))}
                                    </div>
                                  )}
                                </div>
                              ) : q.question_type === "short_answer" ? (
                                <div className="mt-2 rounded-lg border border-slate-200 bg-white p-2.5 text-xs">
                                  <p><span className="font-bold">Your answer:</span> {q.text_answer || "No answer"}</p>
                                  {hist.show_correct_answers && q.accepted_answers && (
                                    <p className="mt-1"><span className="font-bold">Accepted answers:</span> {q.accepted_answers.join(" · ")}</p>
                                  )}
                                </div>
                              ) : (

                              <div className="mt-3 space-y-1.5">
                                {q.options.map((opt) => {
                                  const wasSelected = q.selected_option_id === opt.id
                                    || (q.selected_option_ids ?? []).includes(opt.id);
                                  const isCorrect = hist.show_correct_answers
                                    ? q.correct_option_ids
                                      ? q.correct_option_ids.includes(opt.id)
                                      : q.correct_option_id === opt.id
                                    : undefined;

                                  let optBorder =
                                    "border-slate-200 bg-white text-slate-700";
                                  if (wasSelected && isCorrect === true) {
                                    optBorder =
                                      "border-emerald-300 bg-emerald-50/70 text-emerald-900 font-bold";
                                  } else if (
                                    wasSelected &&
                                    isCorrect === false
                                  ) {
                                    optBorder =
                                      "border-rose-300 bg-rose-50/70 text-rose-900 font-bold";
                                  } else if (isCorrect === true) {
                                    optBorder =
                                      "border-emerald-200 bg-emerald-50/40 text-emerald-900";
                                  } else if (wasSelected) {
                                    optBorder =
                                      "border-[#0e8988] bg-teal-50/40 text-[#102b4c] font-bold";
                                  }

                                  return (
                                    <div
                                      key={opt.id}
                                      className={`flex items-center justify-between rounded-lg border p-2.5 text-xs ${optBorder}`}
                                    >
                                      <div className="flex items-center gap-2">
                                        <span
                                          className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-full border ${
                                            wasSelected
                                              ? "border-[#0e8988] bg-[#0e8988] text-white"
                                              : "border-slate-300 bg-white"
                                          }`}
                                        >
                                          {wasSelected && (
                                            <span className="h-1 w-1 rounded-full bg-white" />
                                          )}
                                        </span>
                                        <span>{opt.option_text}</span>
                                      </div>

                                      <div className="flex items-center gap-1.5">
                                        {wasSelected && (
                                          <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold text-slate-600">
                                            Your Answer
                                          </span>
                                        )}
                                        {isCorrect === true && (
                                          <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-extrabold text-emerald-800">
                                            ✓ Correct
                                          </span>
                                        )}
                                        {wasSelected && isCorrect === false && (
                                          <span className="rounded bg-rose-100 px-1.5 py-0.5 text-[9px] font-extrabold text-rose-800">
                                            ✗ Incorrect
                                          </span>
                                        )}
                                      </div>
                                    </div>
                                  );
                                })}
                              </div>
                              )}

                              {hist.show_correct_answers && q.explanation && (
                                <div className="mt-3 rounded-lg border border-indigo-100 bg-indigo-50/80 p-2.5">
                                  <p className="text-[9px] font-extrabold uppercase tracking-wide text-indigo-700">
                                    Explanation
                                  </p>
                                  <p className="mt-0.5 text-xs text-indigo-950">
                                    {q.explanation}
                                  </p>
                                </div>
                              )}
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  );
                })}

              {/* Pagination Controls */}
              {historyData && historyData.meta.last_page > 1 && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-200/60 pt-3 text-xs">
                  <button
                    type="button"
                    disabled={historyData.meta.current_page <= 1 || isLoadingHistory}
                    onClick={() => void fetchHistoryPage(historyData.meta.current_page - 1)}
                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-40"
                  >
                    Previous
                  </button>
                  <span className="text-slate-500">
                    Page {historyData.meta.current_page} of {historyData.meta.last_page} ({historyData.meta.total} total)
                  </span>
                  <button
                    type="button"
                    disabled={historyData.meta.current_page >= historyData.meta.last_page || isLoadingHistory}
                    onClick={() => void fetchHistoryPage(historyData.meta.current_page + 1)}
                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-40"
                  >
                    Next
                  </button>
                </div>
              )}
            </div>
          )}
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
