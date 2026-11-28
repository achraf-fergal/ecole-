"use client";

import React, { useState, useEffect, useRef, useCallback } from "react";
import type {
  LessonBlock,
  LearningRouteParams,
  ExerciseAttemptData,
  AttemptQuestion,
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
import { simulateAdminExerciseGrade } from "@/lib/admin-learning";
import {
  answerSignature,
  answersFromAttempt,
  attemptAnswerPayload,
  getQuestionCompletionStatus,
  type AnswerValue,
} from "./exercise-attempt-utils";
import { ExerciseAttemptRunner } from "./exercise-attempt-runner";
import ExerciseStartCard from "./exercise-start-card";
import { ExerciseResultSummary } from "./exercise-result-summary";
import { ExerciseQuestionReview } from "./exercise-question-review";
import type { QuestionResponseValue } from "./question-response-renderer";

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
  const [adminViewMode, setAdminViewMode] = useState<"author" | "simulation">("author");
  const [previewSelectedAnswers, setPreviewSelectedAnswers] = useState<
    Record<number, AnswerValue>
  >({});

  // Isolated student simulation state (never persists to student attempt tables)
  const [simPhase, setSimPhase] = useState<"start" | "in_progress" | "result">("start");
  const [simAttemptScenario, setSimAttemptScenario] = useState<"first" | "final">("first");
  const [simQuestionIndex, setSimQuestionIndex] = useState(0);
  const [simAnswers, setSimAnswers] = useState<Record<number, AnswerValue>>({});
  const [simResult, setSimResult] = useState<ExerciseAttemptData | null>(null);
  const [isSimSubmitting, setIsSimSubmitting] = useState(false);
  const [simError, setSimError] = useState<string | null>(null);

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
        getQuestionCompletionStatus(question, selectedAnswers[question.id]) === "answered"
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
      setCurrentQuestionIndex(0);
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

  const handleStudentResponseChange = (
    question: AttemptQuestion,
    value: QuestionResponseValue
  ) => {
    if (!activeAttempt || activeAttempt.status !== "in_progress") return;

    let answer: AnswerValue;
    if (question.question_type === "fill_in_the_blanks") {
      answer = typeof value === "object" && value !== null && !Array.isArray(value)
        ? value
        : {};
    } else if (question.question_type === "short_answer") {
      answer = typeof value === "string" ? value : "";
    } else if (question.question_type === "multiple_select") {
      answer = Array.isArray(value)
        ? value.filter((optionId): optionId is number => typeof optionId === "number")
        : [];
    } else {
      answer = typeof value === "number" ? value : null;
    }

    const updated = { ...selectedAnswers, [question.id]: answer };
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
    const incompleteCount = activeAttempt.questions.filter((question) =>
      getQuestionCompletionStatus(question, selectedAnswers[question.id]) !== "answered"
    ).length;

    if (
      incompleteCount > 0 &&
      !window.confirm(
        `You have ${incompleteCount} unanswered or incomplete ${
          incompleteCount === 1 ? "question" : "questions"
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

  const hasValidStudentParams = Boolean(
    params && params.centerSlug && params.courseSlug && params.lessonId
  );

  if (!isAdminPreview && !hasValidStudentParams) {
    return (
      <article className="rounded-2xl border border-rose-200 bg-rose-50/60 p-5 sm:p-7 shadow-xs">
        <h4 className="text-sm font-bold text-rose-800">Assessment Unavailable</h4>
        <p className="mt-1 text-xs text-rose-700">
          Missing required course or lesson context. Please reopen this lesson from your course dashboard.
        </p>
      </article>
    );
  }

  if (isAdminPreview) {
    const simAttemptQuestions: AttemptQuestion[] = exercise.questions.map((q) => ({
      id: q.id,
      question_type: q.question_type,
      prompt: q.prompt,
      points_possible: Number(q.points),
      selected_option_id: null,
      selected_option_ids: null,
      text_answer: null,
      options: q.options.map((o) => ({
        id: o.id,
        option_text: o.option_text,
        sort_order: o.sort_order,
      })),
    }));

    const simulatedAttemptShell: ExerciseAttemptData = {
      id: 0,
      exercise_id: exercise.id,
      attempt_number: 1,
      status: "in_progress",
      passing_score: Number(exercise.passing_score),
      max_score: null,
      score: null,
      percentage: null,
      passed: null,
      correct_count: null,
      incorrect_count: null,
      unanswered_count: null,
      started_at: "",
      submitted_at: null,
      graded_at: null,
      show_correct_answers: false,
      questions: simAttemptQuestions,
    };

    const simAnsweredCount = simAttemptQuestions.filter(
      (q) => getQuestionCompletionStatus(q, simAnswers[q.id]) === "answered"
    ).length;

    const handleResetSimulation = () => {
      setSimPhase("start");
      setSimQuestionIndex(0);
      setSimAnswers({});
      setSimResult(null);
      setSimError(null);
    };

    const handleSimResponseChange = (question: AttemptQuestion, value: QuestionResponseValue) => {
      setSimError(null);
      let answer: AnswerValue;
      if (question.question_type === "fill_in_the_blanks") {
        answer = typeof value === "object" && value !== null && !Array.isArray(value)
          ? value
          : {};
      } else if (question.question_type === "short_answer") {
        answer = typeof value === "string" ? value : "";
      } else if (question.question_type === "multiple_select") {
        answer = Array.isArray(value)
          ? value.filter((optionId): optionId is number => typeof optionId === "number")
          : [];
      } else {
        answer = typeof value === "number" ? value : null;
      }

      setSimAnswers((prev) => ({
        ...prev,
        [question.id]: answer,
      }));
    };

    const handleSubmitSimulation = async () => {
      if (isSimSubmitting) return;
      if (!params?.centerSlug || !params?.courseSlug || !params?.lessonId) {
        setSimError("Missing lesson context to run server simulation grading.");
        return;
      }

      const incompleteCount = simAttemptQuestions.filter(
        (q) => getQuestionCompletionStatus(q, simAnswers[q.id]) !== "answered"
      ).length;

      if (incompleteCount > 0) {
        const proceed = window.confirm(
          `You still have ${incompleteCount} unanswered or incomplete question(s). Submit simulation anyway?`
        );
        if (!proceed) return;
      }

      setIsSimSubmitting(true);
      setSimError(null);
      try {
        const payload = attemptAnswerPayload(simAttemptQuestions, simAnswers);
        const graded = await simulateAdminExerciseGrade(
          params.centerSlug,
          params.courseSlug,
          params.lessonId,
          exercise.id,
          payload,
          simAttemptScenario
        );
        setSimResult(graded);
        setSimPhase("result");
      } catch (err: unknown) {
        setSimError(err instanceof Error ? err.message : "Failed to grade simulation.");
      } finally {
        setIsSimSubmitting(false);
      }
    };

    return (
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5">
          <span className="text-xs font-bold text-slate-700">
            Preview Mode:
          </span>
          <div className="flex flex-wrap gap-2" role="tablist" aria-label="Exercise preview mode">
            <button
              type="button"
              role="tab"
              aria-selected={adminViewMode === "author"}
              onClick={() => setAdminViewMode("author")}
              className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                adminViewMode === "author"
                  ? "bg-amber-600 text-white shadow-xs"
                  : "border border-slate-200 bg-white text-slate-700 hover:bg-slate-100"
              }`}
            >
              Author Preview
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={adminViewMode === "simulation"}
              onClick={() => setAdminViewMode("simulation")}
              className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                adminViewMode === "simulation"
                  ? "bg-[#0e8988] text-white shadow-xs"
                  : "border border-slate-200 bg-white text-slate-700 hover:bg-slate-100"
              }`}
            >
              Student Simulation
            </button>
          </div>
        </div>

        {adminViewMode === "author" ? (
          <ExerciseAttemptRunner
            mode="preview"
            exercise={exercise}
            previewSelectedAnswers={previewSelectedAnswers}
            handleBlankAnswer={handleBlankAnswer}
            handleSelectTextAnswer={handleSelectTextAnswer}
            handleSelectOption={handleSelectOption}
          />
        ) : simPhase === "start" ? (
          <article className="rounded-2xl border border-[#0e8988]/30 bg-white p-5 sm:p-7 shadow-xs">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h4 className="text-lg font-black text-[#102b4c]">{exercise.title}</h4>
                {exercise.instructions && (
                  <p className="mt-2 text-xs leading-6 text-slate-600">{exercise.instructions}</p>
                )}
                <div className="mt-3 flex flex-wrap gap-3 text-xs text-slate-500">
                  <span>{exercise.questions.length} questions</span>
                  <span>·</span>
                  <span>Passing score: {exercise.passing_score}%</span>
                  <span>·</span>
                  <span>{exercise.max_attempts ? `Max attempts: ${exercise.max_attempts}` : "Unlimited attempts"}</span>
                </div>
                {exercise.max_attempts !== null && exercise.max_attempts > 1 && (
                  <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p className="text-[11px] font-bold text-slate-700">
                      Attempt Scenario (simulates release timing policy)
                    </p>
                    <div className="mt-2 flex flex-wrap gap-2">
                      <button
                        type="button"
                        onClick={() => setSimAttemptScenario("first")}
                        className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                          simAttemptScenario === "first"
                            ? "bg-[#0e8988] text-white shadow-xs"
                            : "border border-slate-200 bg-white text-slate-700 hover:bg-slate-100"
                        }`}
                      >
                        First Attempt (1 of {exercise.max_attempts})
                      </button>
                      <button
                        type="button"
                        onClick={() => setSimAttemptScenario("final")}
                        className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                          simAttemptScenario === "final"
                            ? "bg-[#0e8988] text-white shadow-xs"
                            : "border border-slate-200 bg-white text-slate-700 hover:bg-slate-100"
                        }`}
                      >
                        Final Permitted Attempt ({exercise.max_attempts} of {exercise.max_attempts})
                      </button>
                    </div>
                  </div>
                )}
              </div>
              <span className="rounded-full bg-teal-50 px-3 py-1 text-[10px] font-extrabold uppercase text-[#0e8988]">
                Student Simulation · {simAttemptScenario === "final" && exercise.max_attempts ? `Attempt ${exercise.max_attempts}/${exercise.max_attempts}` : "Attempt 1"}
              </span>
            </div>
            <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
              <p className="text-[11px] text-slate-500">
                Simulates the real student experience without creating attempts or updating progress.
              </p>
              <button
                type="button"
                disabled={exercise.questions.length === 0}
                onClick={() => {
                  setSimQuestionIndex(0);
                  setSimError(null);
                  setSimPhase("in_progress");
                }}
                className="rounded-xl bg-[#0e8988] px-5 py-2.5 text-xs font-extrabold text-white shadow-xs transition hover:bg-[#087b83] disabled:opacity-50"
              >
                Start Simulation
              </button>
            </div>
          </article>
        ) : simPhase === "in_progress" ? (
          <ExerciseAttemptRunner
            mode="attempt"
            isSimulation={true}
            onResetSimulation={handleResetSimulation}
            exercise={exercise}
            activeAttempt={simulatedAttemptShell}
            currentQuestionIndex={simQuestionIndex}
            answeredCount={simAnsweredCount}
            selectedAnswers={simAnswers}
            isSaving={false}
            isSubmitting={isSimSubmitting}
            actionError={simError}
            saveSuccessMessage={null}
            navigateToQuestion={(idx) =>
              setSimQuestionIndex(Math.max(0, Math.min(idx, simAttemptQuestions.length - 1)))
            }
            handleStudentResponseChange={handleSimResponseChange}
            handleSaveAnswers={() => undefined}
            handleSubmitAttempt={handleSubmitSimulation}
          />
        ) : (
          <article className="space-y-5 rounded-2xl border border-[#0e8988]/30 bg-white p-5 sm:p-7 shadow-xs">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
              <div>
                <h4 className="text-lg font-black text-[#102b4c]">
                  {exercise.title} — Simulation Result
                </h4>
                <p className="mt-1 text-xs text-slate-500">
                  Graded via server rules with zero database persistence · Scenario:{" "}
                  <strong className="text-slate-700">
                    {simAttemptScenario === "final" && exercise.max_attempts
                      ? `Final Permitted Attempt (${exercise.max_attempts} of ${exercise.max_attempts})`
                      : "First Attempt (Attempt 1)"}
                  </strong>
                </p>
              </div>
              <button
                type="button"
                onClick={handleResetSimulation}
                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
              >
                Reset Simulation
              </button>
            </div>
            {simResult && (
              <>
                <ExerciseResultSummary attempt={simResult} />
                <ExerciseQuestionReview attempt={simResult} />
              </>
            )}
          </article>
        )}
      </div>
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

  if (activeAttempt && activeAttempt.status === "in_progress") {
    return (
      <ExerciseAttemptRunner
        mode="attempt"
        exercise={exercise}
        activeAttempt={activeAttempt}
        currentQuestionIndex={currentQuestionIndex}
        answeredCount={answeredCount}
        selectedAnswers={selectedAnswers}
        isSaving={isSaving}
        isSubmitting={isSubmitting}
        actionError={actionError}
        saveSuccessMessage={saveSuccessMessage}
        navigateToQuestion={navigateToQuestion}
        handleStudentResponseChange={handleStudentResponseChange}
        handleSaveAnswers={handleSaveAnswers}
        handleSubmitAttempt={handleSubmitAttempt}
      />
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

  return (
    <ExerciseStartCard
      exercise={exercise}
      attemptState={attemptState}
      canStartNew={canStartNew}
      isStarting={isStarting}
      actionError={actionError}
      handleStartAttempt={handleStartAttempt}
      history={{
        attemptsCount: attemptState?.exercise.attempts_count ?? 0,
        isHistoryOpen,
        handleToggleHistory,
        historyData,
        isLoadingHistory,
        historyError,
        historyPage,
        fetchHistoryPage,
        inspectingAttemptId,
        setInspectingAttemptId,
      }}
    />
  );
}
