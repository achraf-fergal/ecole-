"use client";

import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  AdminLearningApiError,
  createAdminExercise,
  deleteAdminExercise,
  getAdminExercises,
  publishAdminExercise,
  updateAdminExercise,
  type AdminFullExercise,
  type BlankAnswerConfigPayload,
  type CreateExercisePayload,
  type CreateQuestionPayload,
  type ShortAnswerConfigPayload,
} from "@/lib/admin-learning";
import {
  createClientId,
  createQuestionDraft,
  extractFirstErrorQuestionIndex,
  sumBlankPoints,
  validateExerciseDraft,
} from "@/lib/exercise-authoring.mjs";
import type { QuestionType } from "@/lib/learning";
import {
  createClientQuestionDraft,
  QUESTION_TYPE_OPTIONS,
  toDraftExercise,
  type DraftQuestion,
  type ExerciseDraft,
} from "./exercise-builder-types";
import ExercisePreview from "./exercise-preview";
import {
  FillInTheBlanksEditor,
  MultipleSelectEditor,
  ShortAnswerEditor,
  SingleChoiceEditor,
  TrueFalseEditor,
} from "./exercise-question-editors";

interface ExerciseDraftBuilderProps {
  centerSlug: string;
  courseSlug: string;
  lessonId: number;
  onInsert: (exerciseId: number) => Promise<void>;
  onDirtyChange: (dirty: boolean) => void;
}

type SaveStatus = "idle" | "saving" | "saved" | "error";

export default function ExerciseDraftBuilder({
  centerSlug,
  courseSlug,
  lessonId,
  onInsert,
  onDirtyChange,
}: ExerciseDraftBuilderProps) {
  const [exercises, setExercises] = useState<AdminFullExercise[]>([]);
  const [draft, setDraft] = useState<ExerciseDraft | null>(null);
  const [selectedQuestionClientId, setSelectedQuestionClientId] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isBusy, setIsBusy] = useState(false);
  const [saveStatus, setSaveStatus] = useState<SaveStatus>("idle");
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [hasUnsavedEdits, setHasUnsavedEdits] = useState(false);
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const [previewSession, setPreviewSession] = useState(0);

  const editRevisionRef = useRef(0);
  const inFlightActionRef = useRef<string | null>(null);
  const draftRef = useRef<ExerciseDraft | null>(null);

  useEffect(() => {
    draftRef.current = draft;
  }, [draft]);

  const liveValidation = useMemo(
    () => (draft ? validateExerciseDraft(draft) : null),
    [draft]
  );

  const selectQuestionByIndex = (questions: DraftQuestion[], index: number | null | undefined) => {
    if (questions.length === 0) {
      setSelectedQuestionClientId(null);
      return;
    }
    const safeIdx =
      index !== null && index !== undefined && index >= 0 && index < questions.length ? index : 0;
    setSelectedQuestionClientId(questions[safeIdx].clientId);
  };

  const loadExercises = async (preferredId?: number, preserveLocalDraft = false) => {
    const rows = await getAdminExercises(centerSlug, courseSlug, lessonId);
    setExercises(rows);
    if (preferredId && !preserveLocalDraft) {
      const preferred = rows.find((row) => row.id === preferredId);
      if (preferred) {
        const nextDraft = toDraftExercise(preferred, draftRef.current);
        setDraft(nextDraft);
        const stillExists = nextDraft.questions.some((q) => q.clientId === selectedQuestionClientId);
        if (!stillExists) {
          selectQuestionByIndex(nextDraft.questions, 0);
        }
      }
    }
    return rows;
  };

  useEffect(() => {
    let mounted = true;
    void getAdminExercises(centerSlug, courseSlug, lessonId)
      .then((rows) => {
        if (mounted) setExercises(rows);
      })
      .catch((cause: unknown) => {
        if (mounted) setError(cause instanceof Error ? cause.message : "Could not load exercises.");
      })
      .finally(() => {
        if (mounted) setIsLoading(false);
      });
    return () => {
      mounted = false;
    };
  }, [centerSlug, courseSlug, lessonId]);

  const markDirty = (changedPaths?: string[]) => {
    editRevisionRef.current += 1;
    setHasUnsavedEdits(true);
    setSaveStatus("idle");
    onDirtyChange(true);
    if (changedPaths && changedPaths.length > 0) {
      setFieldErrors((prev) => {
        const next = { ...prev };
        for (const path of changedPaths) {
          delete next[path];
        }
        return next;
      });
    } else {
      setFieldErrors({});
    }
  };

  const updateDraft = (update: (current: ExerciseDraft) => ExerciseDraft, clearPaths?: string[]) => {
    markDirty(clearPaths);
    setDraft((current) => (current ? update(current) : current));
  };

  const handleSelectExercise = (exercise: AdminFullExercise) => {
    if (isBusy) return;
    if (hasUnsavedEdits && draft && draft.id !== exercise.id) {
      if (!window.confirm("Discard unsaved draft edits and open another exercise?")) return;
    }
    const nextDraft = toDraftExercise(exercise);
    setDraft(nextDraft);
    selectQuestionByIndex(nextDraft.questions, 0);
    setHasUnsavedEdits(false);
    setSaveStatus("idle");
    setFieldErrors({});
    setError(null);
    setIsPreviewOpen(false);
    onDirtyChange(false);
  };

  const handleNewDraft = async () => {
    if (inFlightActionRef.current) return;
    if (hasUnsavedEdits && !window.confirm("Discard unsaved edits and create another draft?")) return;
    inFlightActionRef.current = "create";
    setIsBusy(true);
    setError(null);
    try {
      const created = await createAdminExercise(centerSlug, courseSlug, lessonId, {
        title: "Untitled Exercise",
        passing_score: 70,
      });
      const nextDraft = toDraftExercise(created);
      setDraft(nextDraft);
      selectQuestionByIndex(nextDraft.questions, 0);
      setHasUnsavedEdits(false);
      setSaveStatus("saved");
      setFieldErrors({});
      setIsPreviewOpen(false);
      onDirtyChange(false);
      await loadExercises(created.id);
    } catch (cause: unknown) {
      setError(cause instanceof Error ? cause.message : "Could not create an exercise draft.");
    } finally {
      inFlightActionRef.current = null;
      setIsBusy(false);
    }
  };

  const buildPayload = (value: ExerciseDraft): CreateExercisePayload => ({
    title: value.title,
    instructions: value.instructions,
    passing_score: value.passing_score,
    max_attempts: value.max_attempts,
    show_correct_answers_after_submission: value.show_correct_answers_after_submission,
    sort_order: value.sort_order,
    questions: value.questions.map((question, index): CreateQuestionPayload => {
      const isTextBased =
        question.question_type === "short_answer" || question.question_type === "fill_in_the_blanks";
      const points =
        question.question_type === "fill_in_the_blanks"
          ? sumBlankPoints((question.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? [])
          : question.points;

      return {
        ...(question.id ? { id: question.id } : {}),
        prompt: question.prompt,
        question_type: question.question_type,
        explanation: question.explanation ?? null,
        points,
        sort_order: index,
        answer_config: isTextBased ? (question.answer_config ?? null) : null,
        options: isTextBased
          ? []
          : question.options.map((option, optionIndex) => ({
              ...(option.id ? { id: option.id } : {}),
              option_text: option.option_text,
              is_correct: option.is_correct,
              sort_order: optionIndex,
            })),
      };
    }),
  });

  const saveDraft = async (): Promise<AdminFullExercise | null> => {
    const snapshot = draftRef.current;
    if (!snapshot || inFlightActionRef.current) return null;

    inFlightActionRef.current = "save";
    const requestRevision = editRevisionRef.current;
    setIsBusy(true);
    setSaveStatus("saving");
    setError(null);
    setFieldErrors({});

    try {
      const saved = await updateAdminExercise(
        centerSlug,
        courseSlug,
        lessonId,
        snapshot.id,
        buildPayload(snapshot)
      );

      const userEditedWhileSaving = editRevisionRef.current !== requestRevision;
      if (userEditedWhileSaving) {
        // Reconcile server IDs without overwriting newer local edits
        setDraft((current) => {
          if (!current || current.id !== saved.id) return current;
          return {
            ...current,
            questions: current.questions.map((q, qIdx) => {
              const serverQ = saved.questions[qIdx];
              if (!serverQ) return q;
              return {
                ...q,
                id: q.id ?? serverQ.id,
                options: q.options.map((opt, oIdx) => ({
                  ...opt,
                  id: opt.id ?? serverQ.options[oIdx]?.id,
                })),
              };
            }),
          };
        });
        setSaveStatus("idle");
        await loadExercises(saved.id, true);
      } else {
        const reconciled = toDraftExercise(saved, snapshot);
        setDraft(reconciled);
        setHasUnsavedEdits(false);
        setSaveStatus("saved");
        onDirtyChange(false);
        await loadExercises(saved.id, true);
      }
      return saved;
    } catch (cause: unknown) {
      setSaveStatus("error");
      if (cause instanceof AdminLearningApiError && cause.errors) {
        const mapped = Object.fromEntries(
          Object.entries(cause.errors).map(([field, messages]) => [field, messages[0] ?? "Invalid value."])
        );
        setFieldErrors(mapped);
        const errIdx = extractFirstErrorQuestionIndex(mapped);
        if (errIdx !== null && draftRef.current) {
          selectQuestionByIndex(draftRef.current.questions, errIdx);
        }
      }
      setError(cause instanceof Error ? cause.message : "Could not save the exercise draft.");
      return null;
    } finally {
      inFlightActionRef.current = null;
      setIsBusy(false);
    }
  };

  const publish = async () => {
    const current = draftRef.current;
    if (!current || inFlightActionRef.current) return;

    const validation = validateExerciseDraft(current);
    if (!validation.valid) {
      setFieldErrors(validation.errors);
      setError("Review the marked fields before publishing.");
      if (validation.firstInvalidQuestionIndex !== null) {
        selectQuestionByIndex(current.questions, validation.firstInvalidQuestionIndex);
      }
      return;
    }

    const saved = await saveDraft();
    if (!saved) return;

    if (editRevisionRef.current !== 0 && hasUnsavedEdits) {
      // Wait for user to finish concurrent edits before publishing stale state
    }

    inFlightActionRef.current = "publish";
    setIsBusy(true);
    setError(null);
    try {
      const published = await publishAdminExercise(centerSlug, courseSlug, lessonId, saved.id);
      const nextDraft = toDraftExercise(published, draftRef.current);
      setDraft(nextDraft);
      setHasUnsavedEdits(false);
      setSaveStatus("saved");
      onDirtyChange(false);
      await loadExercises(published.id, true);
    } catch (cause: unknown) {
      setSaveStatus("error");
      if (cause instanceof AdminLearningApiError && cause.errors) {
        const mapped = Object.fromEntries(
          Object.entries(cause.errors).map(([field, messages]) => [field, messages[0] ?? "Invalid value."])
        );
        setFieldErrors(mapped);
        const errIdx = extractFirstErrorQuestionIndex(mapped);
        if (errIdx !== null && draftRef.current) {
          selectQuestionByIndex(draftRef.current.questions, errIdx);
        }
      }
      setError(cause instanceof Error ? cause.message : "Could not publish the exercise.");
    } finally {
      inFlightActionRef.current = null;
      setIsBusy(false);
    }
  };

  const addQuestion = () => {
    const newQuestion = createClientQuestionDraft("single_choice", createQuestionDraft);
    updateDraft((current) => ({
      ...current,
      questions: [
        ...current.questions,
        { ...newQuestion, sort_order: current.questions.length },
      ],
    }));
    setSelectedQuestionClientId(newQuestion.clientId);
  };

  const changeQuestionType = (index: number, type: QuestionType) =>
    updateDraft((current) => ({
      ...current,
      questions: current.questions.map((question, questionIndex) => {
        if (questionIndex !== index) return question;
        const replacement = createClientQuestionDraft(type, createQuestionDraft);
        const nextPrompt =
          type === "fill_in_the_blanks" && !question.prompt.trim()
            ? replacement.prompt
            : question.prompt;
        return {
          ...replacement,
          id: question.id,
          clientId: question.clientId,
          sort_order: question.sort_order,
          prompt: nextPrompt,
          points: type === "fill_in_the_blanks" ? replacement.points : question.points,
          explanation: question.explanation,
        };
      }),
    }));

  const updateQuestion = (index: number, patch: Partial<DraftQuestion>) =>
    updateDraft((current) => ({
      ...current,
      questions: current.questions.map((question, questionIndex) =>
        questionIndex === index ? { ...question, ...patch } : question
      ),
    }));

  const moveQuestion = (index: number, direction: -1 | 1) =>
    updateDraft((current) => {
      const destination = index + direction;
      if (destination < 0 || destination >= current.questions.length) return current;
      const questions = [...current.questions];
      [questions[index], questions[destination]] = [questions[destination], questions[index]];
      return {
        ...current,
        questions: questions.map((question, sort_order) => ({ ...question, sort_order })),
      };
    });

  const duplicateQuestion = (index: number) => {
    const source = draft?.questions[index];
    if (!source) return;
    const copyClientId = createClientId("q");
    const copy: DraftQuestion = {
      ...source,
      id: undefined,
      clientId: copyClientId,
      sort_order: index + 1,
      answer_config: source.answer_config
        ? source.question_type === "fill_in_the_blanks"
          ? {
              blanks: ((source.answer_config as BlankAnswerConfigPayload).blanks ?? []).map((b) => ({
                ...b,
                accepted_answers: [...(b.accepted_answers ?? [])],
                normalization: { ...(b.normalization ?? {}) },
              })),
            }
          : {
              accepted_answers: [...(source.answer_config as ShortAnswerConfigPayload).accepted_answers],
              normalization: { ...(source.answer_config as ShortAnswerConfigPayload).normalization },
            }
        : null,
      options: source.options.map(({ option_text, is_correct, sort_order }) => ({
        clientId: createClientId("opt"),
        option_text,
        is_correct,
        sort_order,
      })),
      acceptedAnswerClientIds: source.acceptedAnswerClientIds?.map(() => createClientId("ans")),
      blankClientIds: source.blankClientIds?.map(() => createClientId("blk")),
      blankAnswerClientIds: source.blankAnswerClientIds
        ? Object.fromEntries(
            Object.entries(source.blankAnswerClientIds).map(([k, ids]) => [
              k,
              ids.map(() => createClientId("ans")),
            ])
          )
        : undefined,
    };
    updateDraft((current) => {
      const questions = [...current.questions];
      questions.splice(index + 1, 0, copy);
      return {
        ...current,
        questions: questions.map((question, sort_order) => ({ ...question, sort_order })),
      };
    });
    setSelectedQuestionClientId(copyClientId);
  };

  const deleteQuestion = (index: number) => {
    if (!draft) return;
    const remaining = draft.questions.filter((_, qIdx) => qIdx !== index);
    updateDraft((current) => ({
      ...current,
      questions: current.questions
        .filter((_, qIdx) => qIdx !== index)
        .map((q, sort_order) => ({ ...q, sort_order })),
    }));
    if (remaining.length === 0) {
      setSelectedQuestionClientId(null);
    } else {
      const nextIndex = Math.min(index, remaining.length - 1);
      setSelectedQuestionClientId(remaining[nextIndex].clientId);
    }
  };

  const handleDeleteDraft = async () => {
    if (!draft || draft.status !== "draft" || inFlightActionRef.current) return;
    if (!window.confirm("Delete this exercise draft?")) return;
    inFlightActionRef.current = "delete";
    setIsBusy(true);
    try {
      await deleteAdminExercise(centerSlug, courseSlug, lessonId, draft.id);
      setDraft(null);
      setSelectedQuestionClientId(null);
      setHasUnsavedEdits(false);
      setSaveStatus("idle");
      onDirtyChange(false);
      await loadExercises();
    } catch (cause: unknown) {
      setError(cause instanceof Error ? cause.message : "Could not delete the draft.");
    } finally {
      inFlightActionRef.current = null;
      setIsBusy(false);
    }
  };

  const readError = (path: string) => fieldErrors[path];
  const editable = draft?.status === "draft";

  const selectedQuestionIndex = draft
    ? Math.max(
        0,
        draft.questions.findIndex((q) => q.clientId === selectedQuestionClientId)
      )
    : -1;
  const selectedQuestion =
    draft && selectedQuestionIndex >= 0 && selectedQuestionIndex < draft.questions.length
      ? draft.questions[selectedQuestionIndex]
      : null;

  return (
    <section
      className="mt-4 rounded-2xl border border-teal-200 bg-white p-4 sm:p-5"
      aria-label="Exercise draft builder"
    >
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
        <div>
          <h3 className="text-sm font-black text-[#102b4c]">Exercise Draft Builder</h3>
          <p className="mt-1 text-xs text-slate-600">
            Author questions across all five formats, preview as a student, then publish and insert into this lesson.
          </p>
        </div>
        <div className="flex items-center gap-2">
          {draft && (
            <span
              role="status"
              className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${
                saveStatus === "saving"
                  ? "bg-amber-50 text-amber-800"
                  : saveStatus === "error"
                  ? "bg-rose-50 text-rose-800"
                  : hasUnsavedEdits
                  ? "bg-amber-50 text-amber-800"
                  : saveStatus === "saved"
                  ? "bg-emerald-50 text-emerald-800"
                  : "bg-slate-100 text-slate-600"
              }`}
            >
              {saveStatus === "saving"
                ? "Saving…"
                : saveStatus === "error"
                ? "Save failed"
                : hasUnsavedEdits
                ? "Unsaved changes"
                : saveStatus === "saved"
                ? "Saved"
                : "Up to date"}
            </span>
          )}
          <button
            type="button"
            disabled={isBusy}
            onClick={() => void handleNewDraft()}
            className="rounded-xl bg-[#0e8988] px-3 py-2 text-xs font-bold text-white disabled:opacity-50"
          >
            + New Draft
          </button>
        </div>
      </div>

      {error && (
        <div role="alert" className="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
          {error}
        </div>
      )}

      <div className="mt-4 grid gap-4 lg:grid-cols-[240px_minmax(0,1fr)]">
        {/* A. Exercise Navigation */}
        <aside aria-label="Saved exercises" className="space-y-2">
          <p className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
            Lesson Exercises ({exercises.length})
          </p>
          {isLoading ? <p className="text-xs text-slate-500">Loading exercises…</p> : null}
          {!isLoading && exercises.length === 0 ? (
            <p className="rounded-xl border border-dashed border-slate-200 p-3 text-xs text-slate-500">
              No exercises for this lesson yet. Click &quot;+ New Draft&quot; to start.
            </p>
          ) : null}
          {exercises.map((exercise) => {
            const isSelected = draft?.id === exercise.id;
            return (
              <button
                type="button"
                disabled={isBusy}
                key={exercise.id}
                aria-current={isSelected ? "true" : undefined}
                onClick={() => handleSelectExercise(exercise)}
                className={`w-full rounded-xl border p-3 text-left transition ${
                  isSelected
                    ? "border-teal-500 bg-teal-50"
                    : "border-slate-200 bg-white hover:bg-slate-50"
                }`}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="truncate text-xs font-bold text-slate-800">{exercise.title}</span>
                  {isSelected && hasUnsavedEdits && (
                    <span className="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-bold text-amber-800">
                      Unsaved
                    </span>
                  )}
                </div>
                <div className="mt-1 flex items-center gap-2 text-[10px] text-slate-500">
                  <span
                    className={`rounded px-1.5 py-0.5 font-semibold capitalize ${
                      exercise.status === "published"
                        ? "bg-emerald-100 text-emerald-800"
                        : "bg-slate-100 text-slate-700"
                    }`}
                  >
                    {exercise.status}
                  </span>
                  <span>{exercise.questions.length} questions</span>
                </div>
              </button>
            );
          })}
        </aside>

        {!draft ? (
          <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-xs text-slate-500">
            Choose an exercise from the list or create a new draft to begin authoring.
          </div>
        ) : (
          <div className="min-w-0 space-y-4">
            {!editable && (
              <div role="status" className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                This exercise is <strong>{draft.status}</strong>. Published content is protected by lesson revision immutability rules.
              </div>
            )}

            {/* Validation Summary */}
            {Object.keys(fieldErrors).length > 0 && liveValidation && !liveValidation.valid && (
              <div className="rounded-xl border border-rose-200 bg-rose-50/70 p-3 text-xs text-rose-900">
                <p className="font-bold">Please resolve the following before publishing:</p>
                <ul className="mt-1.5 list-disc space-y-1 pl-4">
                  {fieldErrors.title && <li>{fieldErrors.title}</li>}
                  {fieldErrors.passing_score && <li>{fieldErrors.passing_score}</li>}
                  {fieldErrors.questions && <li>{fieldErrors.questions}</li>}
                  {liveValidation.incompleteQuestions.map(({ index, count }) => (
                    <li key={draft.questions[index]?.clientId ?? index}>
                      <button
                        type="button"
                        onClick={() => selectQuestionByIndex(draft.questions, index)}
                        className="font-semibold underline hover:text-rose-700"
                      >
                        Question {index + 1} ({count} {count === 1 ? "issue" : "issues"})
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <fieldset disabled={!editable || isBusy} className="space-y-4 disabled:opacity-80">
              {/* B. Exercise Settings */}
              <section aria-label="Exercise settings" className="rounded-xl border border-slate-200 bg-slate-50/50 p-3 sm:p-4">
                <h4 className="mb-3 text-xs font-black uppercase tracking-wider text-slate-600">
                  Exercise Settings
                </h4>
                <div className="grid gap-3 sm:grid-cols-2">
                  <label className="text-xs font-semibold text-slate-700 sm:col-span-2">
                    Exercise title
                    <input
                      value={draft.title}
                      maxLength={255}
                      onChange={(event) =>
                        updateDraft((current) => ({ ...current, title: event.target.value }), ["title"])
                      }
                      aria-invalid={Boolean(readError("title"))}
                      className={`mt-1 w-full rounded-lg border bg-white p-2 ${readError("title") ? "border-rose-400" : "border-slate-300"}`}
                    />
                    {readError("title") && (
                      <span className="mt-1 block text-rose-700" role="alert">
                        {readError("title")}
                      </span>
                    )}
                  </label>

                  <label className="text-xs font-semibold text-slate-700 sm:col-span-2">
                    Instructions
                    <textarea
                      value={draft.instructions ?? ""}
                      onChange={(event) =>
                        updateDraft((current) => ({
                          ...current,
                          instructions: event.target.value || null,
                        }))
                      }
                      rows={2}
                      className="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2"
                    />
                  </label>

                  <label className="text-xs font-semibold text-slate-700">
                    Passing score (%)
                    <input
                      type="number"
                      min={0}
                      max={100}
                      value={draft.passing_score}
                      onChange={(event) =>
                        updateDraft(
                          (current) => ({ ...current, passing_score: Number(event.target.value) }),
                          ["passing_score"]
                        )
                      }
                      aria-invalid={Boolean(readError("passing_score"))}
                      className={`mt-1 w-full rounded-lg border bg-white p-2 ${readError("passing_score") ? "border-rose-400" : "border-slate-300"}`}
                    />
                    {readError("passing_score") && (
                      <span className="mt-1 block text-rose-700" role="alert">
                        {readError("passing_score")}
                      </span>
                    )}
                  </label>

                  <div className="space-y-1.5 text-xs font-semibold text-slate-700">
                    <div className="flex items-center justify-between">
                      <span>Maximum attempts</span>
                      <label className="flex items-center gap-1.5 text-[11px] font-medium text-slate-600">
                        <input
                          type="checkbox"
                          checked={draft.max_attempts === null}
                          onChange={(event) =>
                            updateDraft((current) => ({
                              ...current,
                              max_attempts: event.target.checked ? null : 1,
                            }))
                          }
                        />
                        Unlimited
                      </label>
                    </div>
                    <input
                      type="number"
                      min={1}
                      disabled={draft.max_attempts === null}
                      placeholder="Unlimited"
                      aria-label="Maximum attempts"
                      value={draft.max_attempts ?? ""}
                      onChange={(event) =>
                        updateDraft((current) => ({
                          ...current,
                          max_attempts: event.target.value === "" ? null : Number(event.target.value),
                        }))
                      }
                      className="w-full rounded-lg border border-slate-300 bg-white p-2 disabled:bg-slate-100 disabled:text-slate-400"
                    />
                  </div>

                  <label className="flex items-center gap-2 text-xs font-medium text-slate-700 sm:col-span-2">
                    <input
                      type="checkbox"
                      checked={draft.show_correct_answers_after_submission}
                      onChange={(event) =>
                        updateDraft((current) => ({
                          ...current,
                          show_correct_answers_after_submission: event.target.checked,
                        }))
                      }
                    />
                    Show correct answers and explanations after submission
                  </label>
                </div>
              </section>

              {/* C. Question Management */}
              <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                <h4 className="text-xs font-black text-[#102b4c]">
                  Questions ({draft.questions.length})
                </h4>
                <button
                  type="button"
                  onClick={addQuestion}
                  className="rounded-lg border border-teal-300 px-3 py-1.5 text-xs font-bold text-teal-800 hover:bg-teal-50"
                >
                  + Add question
                </button>
              </div>

              {draft.questions.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-xs text-slate-500">
                  No questions yet. Click &quot;+ Add question&quot; to create your first question.
                </div>
              ) : (
                <div className="grid gap-4 xl:grid-cols-[230px_minmax(0,1fr)]">
                  {/* Question List */}
                  <nav aria-label="Question list" className="space-y-2">
                    {draft.questions.map((question, qIndex) => {
                      const isSelected = question.clientId === selectedQuestion?.clientId;
                      const typeMeta = QUESTION_TYPE_OPTIONS.find((t) => t.value === question.question_type);
                      const hasErr = Boolean(liveValidation?.questionErrors[qIndex]);
                      return (
                        <button
                          key={question.clientId}
                          type="button"
                          aria-current={isSelected ? "step" : undefined}
                          onClick={() => setSelectedQuestionClientId(question.clientId)}
                          className={`w-full rounded-xl border p-2.5 text-left transition ${
                            isSelected
                              ? "border-[#0e8988] bg-teal-50/70"
                              : "border-slate-200 bg-white hover:bg-slate-50"
                          }`}
                        >
                          <div className="flex items-center justify-between gap-2">
                            <span className="text-xs font-bold text-slate-800">
                              Q{qIndex + 1} · {typeMeta?.shortLabel ?? question.question_type}
                            </span>
                            <span className="text-[10px] font-semibold text-slate-500">
                              {question.points ?? 1} pts
                            </span>
                          </div>
                          <p className="mt-1 truncate text-[11px] text-slate-600">
                            {question.prompt || "Empty prompt"}
                          </p>
                          <div className="mt-1.5 flex items-center justify-between">
                            <span
                              className={`text-[10px] font-semibold ${
                                hasErr ? "text-amber-700" : "text-emerald-700"
                              }`}
                            >
                              {hasErr ? "Needs attention" : "Ready"}
                            </span>
                          </div>
                        </button>
                      );
                    })}
                  </nav>

                  {/* D. Active Question Editor */}
                  {selectedQuestion && (
                    <fieldset
                      key={selectedQuestion.clientId}
                      className="rounded-xl border border-slate-200 p-3 sm:p-4"
                    >
                      <legend className="px-1 text-xs font-bold text-slate-700">
                        Question {selectedQuestionIndex + 1}
                      </legend>

                      <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_160px]">
                        <label className="text-xs font-semibold text-slate-700">
                          Question type
                          <select
                            value={selectedQuestion.question_type}
                            onChange={(event) =>
                              changeQuestionType(selectedQuestionIndex, event.target.value as QuestionType)
                            }
                            className="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2"
                          >
                            {QUESTION_TYPE_OPTIONS.map((type) => (
                              <option key={type.value} value={type.value}>
                                {type.label}
                              </option>
                            ))}
                          </select>
                        </label>

                        <label className="text-xs font-semibold text-slate-700">
                          {selectedQuestion.question_type === "fill_in_the_blanks"
                            ? "Total points (from blanks)"
                            : "Points"}
                          <input
                            type="number"
                            min={0.01}
                            step={0.25}
                            readOnly={selectedQuestion.question_type === "fill_in_the_blanks"}
                            aria-readonly={
                              selectedQuestion.question_type === "fill_in_the_blanks" ? "true" : undefined
                            }
                            value={
                              selectedQuestion.question_type === "fill_in_the_blanks"
                                ? sumBlankPoints(
                                    (selectedQuestion.answer_config as BlankAnswerConfigPayload | null)
                                      ?.blanks ?? []
                                  )
                                : (selectedQuestion.points ?? 1)
                            }
                            onChange={(event) => {
                              if (selectedQuestion.question_type !== "fill_in_the_blanks") {
                                updateQuestion(selectedQuestionIndex, {
                                  points: Number(event.target.value),
                                });
                              }
                            }}
                            className={`mt-1 w-full rounded-lg border border-slate-300 p-2 ${
                              selectedQuestion.question_type === "fill_in_the_blanks"
                                ? "cursor-not-allowed bg-slate-100 text-slate-600"
                                : "bg-white"
                            }`}
                          />
                          {readError(`questions.${selectedQuestionIndex}.points`) && (
                            <span role="alert" className="mt-1 block text-rose-700">
                              {readError(`questions.${selectedQuestionIndex}.points`)}
                            </span>
                          )}
                        </label>

                        {selectedQuestion.question_type !== "fill_in_the_blanks" && (
                          <label className="text-xs font-semibold text-slate-700 sm:col-span-2">
                            Prompt
                            <textarea
                              value={selectedQuestion.prompt}
                              onChange={(event) =>
                                updateQuestion(selectedQuestionIndex, { prompt: event.target.value })
                              }
                              rows={2}
                              aria-invalid={Boolean(readError(`questions.${selectedQuestionIndex}.prompt`))}
                              className={`mt-1 w-full rounded-lg border bg-white p-2 ${
                                readError(`questions.${selectedQuestionIndex}.prompt`)
                                  ? "border-rose-400"
                                  : "border-slate-300"
                              }`}
                            />
                            {readError(`questions.${selectedQuestionIndex}.prompt`) && (
                              <span role="alert" className="mt-1 block text-rose-700">
                                {readError(`questions.${selectedQuestionIndex}.prompt`)}
                              </span>
                            )}
                          </label>
                        )}
                      </div>

                      {selectedQuestion.question_type === "single_choice" && (
                        <SingleChoiceEditor
                          question={selectedQuestion}
                          questionIndex={selectedQuestionIndex}
                          readError={readError}
                          onUpdateQuestion={(patch) => updateQuestion(selectedQuestionIndex, patch)}
                        />
                      )}
                      {selectedQuestion.question_type === "true_false" && (
                        <TrueFalseEditor
                          question={selectedQuestion}
                          questionIndex={selectedQuestionIndex}
                          readError={readError}
                          onUpdateQuestion={(patch) => updateQuestion(selectedQuestionIndex, patch)}
                        />
                      )}
                      {selectedQuestion.question_type === "multiple_select" && (
                        <MultipleSelectEditor
                          question={selectedQuestion}
                          questionIndex={selectedQuestionIndex}
                          readError={readError}
                          onUpdateQuestion={(patch) => updateQuestion(selectedQuestionIndex, patch)}
                        />
                      )}
                      {selectedQuestion.question_type === "short_answer" && (
                        <ShortAnswerEditor
                          question={selectedQuestion}
                          questionIndex={selectedQuestionIndex}
                          readError={readError}
                          onUpdateQuestion={(patch) => updateQuestion(selectedQuestionIndex, patch)}
                        />
                      )}
                      {selectedQuestion.question_type === "fill_in_the_blanks" && (
                        <FillInTheBlanksEditor
                          question={selectedQuestion}
                          questionIndex={selectedQuestionIndex}
                          readError={readError}
                          onUpdateQuestion={(patch) => updateQuestion(selectedQuestionIndex, patch)}
                        />
                      )}

                      <label className="mt-3 block text-xs font-semibold text-slate-700">
                        Explanation (shown only when answer review is allowed)
                        <textarea
                          value={selectedQuestion.explanation ?? ""}
                          onChange={(event) =>
                            updateQuestion(selectedQuestionIndex, { explanation: event.target.value })
                          }
                          rows={2}
                          className="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2"
                        />
                      </label>

                      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                        <span className="text-[10px] text-slate-500">
                          {selectedQuestion.question_type.replaceAll("_", " ")} · {selectedQuestion.points} points ·{" "}
                          {selectedQuestion.id ? `ID ${selectedQuestion.id}` : "new"}
                        </span>
                        <div className="flex flex-wrap gap-2">
                          <button
                            type="button"
                            disabled={selectedQuestionIndex === 0}
                            onClick={() => moveQuestion(selectedQuestionIndex, -1)}
                            className="rounded border px-2 py-1 text-xs disabled:opacity-40"
                          >
                            Move up
                          </button>
                          <button
                            type="button"
                            disabled={selectedQuestionIndex === draft.questions.length - 1}
                            onClick={() => moveQuestion(selectedQuestionIndex, 1)}
                            className="rounded border px-2 py-1 text-xs disabled:opacity-40"
                          >
                            Move down
                          </button>
                          <button
                            type="button"
                            onClick={() => duplicateQuestion(selectedQuestionIndex)}
                            className="rounded border px-2 py-1 text-xs"
                          >
                            Duplicate
                          </button>
                          <button
                            type="button"
                            onClick={() => deleteQuestion(selectedQuestionIndex)}
                            className="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700"
                          >
                            Delete
                          </button>
                        </div>
                      </div>
                    </fieldset>
                  )}
                </div>
              )}
            </fieldset>

            {/* Footer Action Bar */}
            <div className="flex flex-wrap justify-between gap-2 border-t border-slate-100 pt-3">
              <div className="flex flex-wrap gap-2">
                {editable && (
                  <button
                    type="button"
                    disabled={isBusy}
                    onClick={() => void handleDeleteDraft()}
                    className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 disabled:opacity-50"
                  >
                    Delete draft
                  </button>
                )}
                <button
                  type="button"
                  disabled={!draft.questions.length}
                  onClick={() => {
                    setPreviewSession((k) => k + 1);
                    setIsPreviewOpen(true);
                  }}
                  className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-50"
                >
                  Preview
                </button>
              </div>
              <div className="flex flex-wrap gap-2">
                {editable && (
                  <button
                    type="button"
                    disabled={isBusy}
                    onClick={() => void saveDraft()}
                    className="rounded-lg border border-teal-300 px-3 py-2 text-xs font-bold text-teal-900 disabled:opacity-50"
                  >
                    {saveStatus === "saving" ? "Saving…" : "Save Draft"}
                  </button>
                )}
                {editable && (
                  <button
                    type="button"
                    disabled={isBusy}
                    onClick={() => void publish()}
                    className="rounded-lg bg-[#0e8988] px-3 py-2 text-xs font-bold text-white disabled:opacity-50"
                  >
                    Publish
                  </button>
                )}
                {draft.status === "published" && (
                  <button
                    type="button"
                    disabled={isBusy}
                    onClick={() => void onInsert(draft.id)}
                    className="rounded-lg bg-[#0e8988] px-3 py-2 text-xs font-bold text-white disabled:opacity-50"
                  >
                    Insert into lesson
                  </button>
                )}
              </div>
            </div>
          </div>
        )}
      </div>

      {isPreviewOpen && draft && (
        <ExercisePreview
          key={`preview-${draft.id}-${previewSession}`}
          draft={draft}
          onClose={() => setIsPreviewOpen(false)}
        />
      )}
    </section>
  );
}
