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
  type ExerciseResultSettingsPayload,
  type ResultReleaseTiming,
  type ShortAnswerConfigPayload,
} from "@/lib/admin-learning";
import {
  createClientId,
  createQuestionDraft,
  extractPromptPlaceholders,
  extractFirstErrorQuestionIndex,
  sumBlankPoints,
  validateExerciseDraft,
} from "@/lib/exercise-authoring.mjs";
import type { QuestionType } from "@/lib/learning";
import {
  createClientQuestionDraft,
  captureQuestionTypeConfiguration,
  cloneQuestionTypeConfiguration,
  defaultNormalization,
  QUESTION_TYPE_OPTIONS,
  resolveDraftResultSettings,
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

type SaveStatus = "idle" | "saving" | "publishing" | "saved" | "error";

function mergePersistedIds(
  current: ExerciseDraft,
  saved: ExerciseDraft
): ExerciseDraft {
  return {
    ...current,
    questions: current.questions.map((question) => {
      const savedQuestion = saved.questions.find((candidate) => candidate.clientId === question.clientId);
      if (!savedQuestion) return question;

      return {
        ...question,
        id: question.id ?? savedQuestion.id,
        options: question.options.map((option) => {
          const savedOption = savedQuestion.options.find((candidate) => candidate.clientId === option.clientId);
          return { ...option, id: option.id ?? savedOption?.id };
        }),
      };
    }),
  };
}

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
  const [loadedScopeKey, setLoadedScopeKey] = useState<string | null>(null);
  const [isBusy, setIsBusy] = useState(false);
  const [saveStatus, setSaveStatus] = useState<SaveStatus>("idle");
  const [error, setError] = useState<string | null>(null);
  const [warning, setWarning] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [hasUnsavedEdits, setHasUnsavedEdits] = useState(false);
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const [previewSession, setPreviewSession] = useState(0);
  const previewButtonRef = useRef<HTMLButtonElement | null>(null);
  const scopeKey = `${centerSlug}:${courseSlug}:${lessonId}`;
  const isLoading = loadedScopeKey !== scopeKey;

  const editRevisionRef = useRef(0);
  const inFlightActionRef = useRef<string | null>(null);
  const draftRef = useRef<ExerciseDraft | null>(null);
  const listRequestSequenceRef = useRef(0);
  const mountedRef = useRef(false);

  const setActiveDraft = (nextDraft: ExerciseDraft | null) => {
    draftRef.current = nextDraft;
    setDraft(nextDraft);
  };

  const finishAction = (action: string) => {
    if (inFlightActionRef.current !== action) return;
    inFlightActionRef.current = null;
    setIsBusy(false);
  };

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

  const focusFieldPath = (path: string) => {
    const questionIndex = extractFirstErrorQuestionIndex({ [path]: "invalid" });
    if (questionIndex !== null && draftRef.current) {
      selectQuestionByIndex(draftRef.current.questions, questionIndex);
    }
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        const fields = Array.from(document.querySelectorAll<HTMLElement>("[data-field-path]"));
        const field = fields.find((element) => element.dataset.fieldPath === path)
          ?? fields.find((element) => element.dataset.fieldPath?.startsWith(`${path}.`));
        field?.focus();
        field?.scrollIntoView({ block: "center", behavior: "smooth" });
      });
    });
  };

  const refreshExerciseList = async (): Promise<boolean> => {
    const requestSequence = ++listRequestSequenceRef.current;
    let rows: AdminFullExercise[];
    try {
      rows = await getAdminExercises(centerSlug, courseSlug, lessonId);
    } catch (cause) {
      if (!mountedRef.current || requestSequence !== listRequestSequenceRef.current) return false;
      throw cause;
    }

    if (!mountedRef.current || requestSequence !== listRequestSequenceRef.current) return false;
    setExercises(rows);
    return true;
  };

  const refreshAfterWrite = async (successMessage: string) => {
    try {
      await refreshExerciseList();
    } catch {
      setWarning((current) => current ? `${current} ${successMessage}` : successMessage);
    }
  };

  const beginAction = (action: string): boolean => {
    if (isLoading || inFlightActionRef.current) return false;
    inFlightActionRef.current = action;
    setIsBusy(true);
    setError(null);
    setWarning(null);
    return true;
  };

  const applyServerErrors = (cause: unknown) => {
    if (!(cause instanceof AdminLearningApiError) || !cause.errors) return;
    const mapped = Object.fromEntries(
      Object.entries(cause.errors).map(([field, messages]) => [field, messages[0] ?? "Invalid value."])
    );
    setFieldErrors(mapped);
    const firstErrorPath = Object.keys(mapped)[0];
    if (firstErrorPath) {
      focusFieldPath(firstErrorPath);
    }
  };

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      listRequestSequenceRef.current += 1;
    };
  }, []);

  useEffect(() => {
    let mounted = true;
    const requestSequence = ++listRequestSequenceRef.current;
    void getAdminExercises(centerSlug, courseSlug, lessonId)
      .then((rows) => {
        if (mounted && requestSequence === listRequestSequenceRef.current) {
          setExercises(rows);
          setError(null);
        }
      })
      .catch((cause: unknown) => {
        if (mounted && requestSequence === listRequestSequenceRef.current) {
          setError(cause instanceof Error ? cause.message : "Could not load exercises.");
        }
      })
      .finally(() => {
        if (mounted && requestSequence === listRequestSequenceRef.current) setLoadedScopeKey(scopeKey);
      });
    return () => {
      mounted = false;
      if (requestSequence === listRequestSequenceRef.current) listRequestSequenceRef.current += 1;
    };
  }, [centerSlug, courseSlug, lessonId, scopeKey]);

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
    const current = draftRef.current;
    if (!current) return;
    const updated = update(current);
    const nextDraft = {
      ...updated,
      questions: updated.questions.map((question, sort_order) => ({
        ...question,
        sort_order,
        options: question.options.map((option, optionOrder) => ({
          ...option,
          sort_order: optionOrder,
        })),
      })),
    };
    setActiveDraft(nextDraft);
  };

  const handleSelectExercise = (exercise: AdminFullExercise) => {
    if (isBusy || isLoading || inFlightActionRef.current) return;
    if (hasUnsavedEdits && draft && draft.id !== exercise.id) {
      if (!window.confirm("Discard unsaved draft edits and open another exercise?")) return;
    }
    const nextDraft = toDraftExercise(exercise);
    setActiveDraft(nextDraft);
    selectQuestionByIndex(nextDraft.questions, 0);
    setHasUnsavedEdits(false);
    setSaveStatus("idle");
    setFieldErrors({});
    setError(null);
    setWarning(null);
    setIsPreviewOpen(false);
    onDirtyChange(false);
  };

  const handleNewDraft = async () => {
    if (isLoading || inFlightActionRef.current) return;
    if (hasUnsavedEdits && !window.confirm("Discard unsaved edits and create another draft?")) return;
    if (!beginAction("create")) return;
    setSaveStatus("saving");
    try {
      const created = await createAdminExercise(centerSlug, courseSlug, lessonId, {
        title: "Untitled Exercise",
        passing_score: 70,
      });
      const nextDraft = toDraftExercise(created);
      setActiveDraft(nextDraft);
      selectQuestionByIndex(nextDraft.questions, 0);
      setHasUnsavedEdits(false);
      setSaveStatus("saved");
      setFieldErrors({});
      setIsPreviewOpen(false);
      onDirtyChange(false);
      finishAction("create");
      await refreshAfterWrite("Draft created successfully, but the exercise list could not refresh.");
    } catch (cause: unknown) {
      setError(cause instanceof Error ? cause.message : "Could not create an exercise draft.");
      setSaveStatus("error");
    } finally {
      finishAction("create");
    }
  };

  const buildPayload = (value: ExerciseDraft): CreateExercisePayload => {
    const resolvedSettings = resolveDraftResultSettings(value);
    return {
      title: value.title,
      instructions: value.instructions,
      passing_score: value.passing_score,
      max_attempts: value.max_attempts,
      show_correct_answers_after_submission: resolvedSettings.show_correct_answers,
      result_settings: resolvedSettings,
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
    };
  };

  const persistDraft = async (
    snapshot: ExerciseDraft,
    requestRevision: number
  ): Promise<{
    saved: AdminFullExercise;
    savedDraft: ExerciseDraft;
    isActive: boolean;
    isLatest: boolean;
  }> => {
    const saved = await updateAdminExercise(
      centerSlug,
      courseSlug,
      lessonId,
      snapshot.id,
      buildPayload(snapshot)
    );
    if (saved.id !== snapshot.id) {
      throw new Error("The server returned a different exercise after saving.");
    }

    const savedDraft = toDraftExercise(saved, snapshot);
    const current = draftRef.current;
    if (!current || current.id !== snapshot.id) {
      return { saved, savedDraft, isActive: false, isLatest: false };
    }

    if (editRevisionRef.current !== requestRevision) {
      setActiveDraft(mergePersistedIds(current, savedDraft));
      setHasUnsavedEdits(true);
      setSaveStatus("idle");
      setWarning("The submitted changes were saved. Newer edits remain unsaved; save again before publishing.");
      onDirtyChange(true);
      return { saved, savedDraft, isActive: true, isLatest: false };
    }

    setActiveDraft(savedDraft);
    setHasUnsavedEdits(false);
    setSaveStatus("saved");
    onDirtyChange(false);
    return { saved, savedDraft, isActive: true, isLatest: true };
  };

  const saveDraft = async (): Promise<AdminFullExercise | null> => {
    const snapshot = draftRef.current;
    if (!snapshot || !beginAction("save")) return null;

    const requestRevision = editRevisionRef.current;
    setSaveStatus("saving");
    setFieldErrors({});
    let result: Awaited<ReturnType<typeof persistDraft>> | null = null;

    try {
      result = await persistDraft(snapshot, requestRevision);
    } catch (cause: unknown) {
      setSaveStatus("error");
      applyServerErrors(cause);
      setError(cause instanceof Error ? cause.message : "Could not save the exercise draft.");
    } finally {
      finishAction("save");
    }

    if (!result) return null;
    await refreshAfterWrite("Exercise saved successfully, but the exercise list could not refresh.");
    return result.saved;
  };

  const publish = async () => {
    const snapshot = draftRef.current;
    if (!snapshot || isLoading || inFlightActionRef.current) return;

    const validation = validateExerciseDraft(snapshot);
    if (!validation.valid) {
      setFieldErrors(validation.errors);
      setWarning(null);
      setError("Review the marked fields before publishing.");
      const firstErrorPath = Object.keys(validation.errors)[0];
      if (firstErrorPath) {
        focusFieldPath(firstErrorPath);
      } else if (validation.firstInvalidQuestionIndex !== null) {
        selectQuestionByIndex(snapshot.questions, validation.firstInvalidQuestionIndex);
      }
      return;
    }
    if (!beginAction("publish")) return;

    const requestRevision = editRevisionRef.current;
    setSaveStatus("saving");
    setFieldErrors({});
    let persisted: Awaited<ReturnType<typeof persistDraft>>;
    try {
      persisted = await persistDraft(snapshot, requestRevision);
    } catch (cause: unknown) {
      setSaveStatus("error");
      applyServerErrors(cause);
      setError(cause instanceof Error ? `Could not save before publishing: ${cause.message}` : "Could not save before publishing.");
      finishAction("publish");
      return;
    }

    if (
      !persisted.isActive ||
      !persisted.isLatest ||
      draftRef.current?.id !== persisted.saved.id ||
      editRevisionRef.current !== requestRevision
    ) {
      setError("The exercise changed while it was being saved. Save the latest changes before publishing.");
      finishAction("publish");
      await refreshAfterWrite("The exercise was saved, but the exercise list could not refresh.");
      return;
    }

    setSaveStatus("publishing");
    let published: AdminFullExercise;
    try {
      published = await publishAdminExercise(centerSlug, courseSlug, lessonId, persisted.saved.id);
      if (published.id !== persisted.saved.id) {
        throw new Error("The server returned a different exercise after publishing.");
      }
    } catch (cause: unknown) {
      setSaveStatus("saved");
      applyServerErrors(cause);
      setError(cause instanceof Error ? `The draft was saved, but publishing failed: ${cause.message}` : "The draft was saved, but publishing failed.");
      finishAction("publish");
      await refreshAfterWrite("The draft was saved, but the exercise list could not refresh.");
      return;
    }

    const publishedDraft = toDraftExercise(published, persisted.savedDraft);
    setActiveDraft(publishedDraft);
    setHasUnsavedEdits(false);
    setSaveStatus("saved");
    setFieldErrors({});
    onDirtyChange(false);
    finishAction("publish");
    await refreshAfterWrite("Exercise published successfully, but the exercise list could not refresh.");
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
        if (questionIndex !== index || question.question_type === type) return question;

        const configurations = Object.fromEntries(
          Object.entries(question.typeConfigurations ?? {}).map(([cachedType, configuration]) => [
            cachedType,
            configuration ? cloneQuestionTypeConfiguration(configuration) : configuration,
          ])
        ) as NonNullable<DraftQuestion["typeConfigurations"]>;
        configurations[question.question_type] = captureQuestionTypeConfiguration(question);

        let targetConfiguration = configurations[type];
        if (!targetConfiguration) {
          const replacement = createClientQuestionDraft(type, createQuestionDraft);
          if (type === "fill_in_the_blanks") {
            const existingBlankIds = [...new Set(extractPromptPlaceholders(question.prompt).ids)];
            const prompt = question.prompt.trim()
              ? existingBlankIds.length > 0
                ? question.prompt
                : `${question.prompt.trim()} [blank_1]`
              : replacement.prompt;
            const blankIds = [...new Set(extractPromptPlaceholders(prompt).ids)];
            const blanks = blankIds.map((id) => ({
              id,
              points: 1,
              accepted_answers: [""],
              normalization: defaultNormalization(),
            }));
            replacement.prompt = prompt;
            replacement.answer_config = { blanks };
            replacement.points = blanks.reduce((total, blank) => total + blank.points, 0);
            replacement.blankClientIds = blanks.map(() => createClientId("blk"));
            replacement.blankAnswerClientIds = Object.fromEntries(
              blanks.map((blank, blankIndex) => [blankIndex, blank.accepted_answers.map(() => createClientId("ans"))])
            );
          } else {
            replacement.prompt = question.prompt;
          }
          targetConfiguration = captureQuestionTypeConfiguration(replacement);
        }

        configurations[type] = cloneQuestionTypeConfiguration(targetConfiguration);
        return {
          ...question,
          id: question.id,
          clientId: question.clientId,
          sort_order: question.sort_order,
          question_type: type,
          prompt: targetConfiguration.prompt,
          options: targetConfiguration.options.map((option) => ({ ...option })),
          answer_config: targetConfiguration.answer_config,
          points: type === "fill_in_the_blanks"
            ? sumBlankPoints((targetConfiguration.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? [])
            : targetConfiguration.points,
          acceptedAnswerClientIds: targetConfiguration.acceptedAnswerClientIds
            ? [...targetConfiguration.acceptedAnswerClientIds]
            : undefined,
          blankClientIds: targetConfiguration.blankClientIds
            ? [...targetConfiguration.blankClientIds]
            : undefined,
          blankAnswerClientIds: targetConfiguration.blankAnswerClientIds
            ? Object.fromEntries(
                Object.entries(targetConfiguration.blankAnswerClientIds).map(([blankIndex, ids]) => [
                  blankIndex,
                  [...ids],
                ])
              )
            : undefined,
          typeConfigurations: configurations,
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
      typeConfigurations: source.typeConfigurations
        ? Object.fromEntries(
            Object.entries(source.typeConfigurations).map(([type, configuration]) => [
              type,
              configuration ? cloneQuestionTypeConfiguration(configuration, false) : configuration,
            ])
          ) as DraftQuestion["typeConfigurations"]
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
    if (!draft || draft.status !== "draft" || isLoading || inFlightActionRef.current) return;
    if (!window.confirm("Delete this exercise draft?")) return;
    if (!beginAction("delete")) return;
    setSaveStatus("saving");
    let deleted = false;
    try {
      await deleteAdminExercise(centerSlug, courseSlug, lessonId, draft.id);
      setActiveDraft(null);
      setSelectedQuestionClientId(null);
      setHasUnsavedEdits(false);
      setSaveStatus("idle");
      setFieldErrors({});
      onDirtyChange(false);
      deleted = true;
    } catch (cause: unknown) {
      setError(cause instanceof Error ? cause.message : "Could not delete the draft.");
      setSaveStatus("error");
    } finally {
      finishAction("delete");
    }
    if (deleted) {
      await refreshAfterWrite("Draft deleted successfully, but the exercise list could not refresh.");
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
                saveStatus === "saving" || saveStatus === "publishing"
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
                : saveStatus === "publishing"
                ? "Publishing..."
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
          disabled={isBusy || isLoading}
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
      {warning && (
        <div role="status" className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
          {warning}
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
                disabled={isBusy || isLoading}
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
            {Object.keys(fieldErrors).length > 0 && (
              <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50/70 p-3 text-xs text-rose-900">
                <p className="font-bold">Please resolve the following before publishing:</p>
                <ul className="mt-1.5 list-disc space-y-1 pl-4">
                  {Object.entries(fieldErrors).map(([path, message]) => (
                    <li key={path}>
                      <button
                        type="button"
                        onClick={() => focusFieldPath(path)}
                        className="text-left font-semibold underline hover:text-rose-700"
                      >
                        {path.replaceAll(".", " · ")}: {message}
                      </button>
                    </li>
                  ))}
                  {liveValidation?.incompleteQuestions.map(({ index, count }) => (
                    <li key={draft.questions[index]?.clientId ?? index}>
                      <button
                        type="button"
                        onClick={() => {
                          const firstPath = Object.keys(liveValidation?.questionErrors[index] ?? {})[0];
                          if (firstPath) focusFieldPath(firstPath);
                          else selectQuestionByIndex(draft.questions, index);
                        }}
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
                      aria-describedby={readError("title") ? "field-error-title" : undefined}
                      data-field-path="title"
                      className={`mt-1 w-full rounded-lg border bg-white p-2 ${readError("title") ? "border-rose-400" : "border-slate-300"}`}
                    />
                    {readError("title") && (
                      <span id="field-error-title" className="mt-1 block text-rose-700" role="alert">
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
                      aria-describedby={readError("passing_score") ? "field-error-passing-score" : undefined}
                      data-field-path="passing_score"
                      className={`mt-1 w-full rounded-lg border bg-white p-2 ${readError("passing_score") ? "border-rose-400" : "border-slate-300"}`}
                    />
                    {readError("passing_score") && (
                      <span id="field-error-passing-score" className="mt-1 block text-rose-700" role="alert">
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
                          onChange={(event) => {
                            const unlimited = event.target.checked;
                            updateDraft((current) => {
                              const currentSettings = resolveDraftResultSettings(current);
                              return {
                                ...current,
                                max_attempts: unlimited ? null : 1,
                                result_settings: unlimited && currentSettings.release_timing === "after_final_attempt"
                                  ? { ...currentSettings, release_timing: "immediately" }
                                  : currentSettings,
                              };
                            }, ["max_attempts"]);
                          }}
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
                      aria-invalid={Boolean(readError("max_attempts"))}
                      aria-describedby={readError("max_attempts") ? "field-error-max-attempts" : undefined}
                      data-field-path="max_attempts"
                      onChange={(event) =>
                        updateDraft((current) => ({
                          ...current,
                          max_attempts: event.target.value === "" ? null : Number(event.target.value),
                        }), ["max_attempts"])
                      }
                      className="w-full rounded-lg border border-slate-300 bg-white p-2 disabled:bg-slate-100 disabled:text-slate-400"
                    />
                    {readError("max_attempts") && (
                      <span id="field-error-max-attempts" className="block text-rose-700" role="alert">
                        {readError("max_attempts")}
                      </span>
                    )}
                  </div>
                </div>
              </section>

              {/* Results & Feedback */}
              {(() => {
                const rs = resolveDraftResultSettings(draft);
                const updateRs = (patch: Partial<ExerciseResultSettingsPayload>) => {
                  updateDraft((current) => {
                    const base = resolveDraftResultSettings(current);
                    const next: ExerciseResultSettingsPayload = { ...base, ...patch };
                    if (!next.show_question_results) {
                      next.show_correct_answers = false;
                      next.show_explanations = false;
                    }
                    return {
                      ...current,
                      result_settings: next,
                      show_correct_answers_after_submission: next.show_correct_answers,
                    };
                  });
                };

                const visibleSummary: string[] = [];
                if (rs.show_score) visibleSummary.push("Total score");
                if (rs.show_percentage) visibleSummary.push("Percentage");
                if (rs.show_pass_fail) visibleSummary.push("Pass/Fail");
                if (rs.show_question_results) {
                  visibleSummary.push("Question status");
                  if (rs.show_student_answers) visibleSummary.push("Submitted answers");
                  if (rs.show_points_per_question) visibleSummary.push("Points per question");
                  if (rs.show_correct_answers) visibleSummary.push("Correct answers");
                  if (rs.show_explanations) visibleSummary.push("Explanations");
                }

                return (
                  <section aria-label="Results & Feedback" className="rounded-xl border border-slate-200 bg-slate-50/50 p-3 sm:p-4 space-y-4">
                    <div>
                      <h4 className="text-xs font-black uppercase tracking-wider text-slate-600">
                        Results &amp; Feedback
                      </h4>
                      <p className="mt-1 text-[11px] text-slate-500">
                        Control what students see after submitting and when detailed results are released.
                      </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                      <div className="space-y-2 rounded-lg border border-slate-200 bg-white p-3">
                        <p className="text-xs font-bold text-slate-700">Overall Results</p>
                        <label className="flex items-center gap-2 text-xs text-slate-700">
                          <input
                            type="checkbox"
                            checked={rs.show_score}
                            onChange={(e) => updateRs({ show_score: e.target.checked })}
                          />
                          Show total score
                        </label>
                        <label className="flex items-center gap-2 text-xs text-slate-700">
                          <input
                            type="checkbox"
                            checked={rs.show_percentage}
                            onChange={(e) => updateRs({ show_percentage: e.target.checked })}
                          />
                          Show percentage
                        </label>
                        <label className="flex items-center gap-2 text-xs text-slate-700">
                          <input
                            type="checkbox"
                            checked={rs.show_pass_fail}
                            onChange={(e) => updateRs({ show_pass_fail: e.target.checked })}
                          />
                          Show pass/fail status
                        </label>
                      </div>

                      <div className="space-y-2 rounded-lg border border-slate-200 bg-white p-3">
                        <p className="text-xs font-bold text-slate-700">Question Review</p>
                        <label className="flex items-center gap-2 text-xs text-slate-700">
                          <input
                            type="checkbox"
                            checked={rs.show_question_results}
                            onChange={(e) => updateRs({ show_question_results: e.target.checked })}
                          />
                          Show individual question results
                        </label>
                        <label className="flex items-center gap-2 text-xs text-slate-700">
                          <input
                            type="checkbox"
                            checked={rs.show_student_answers}
                            onChange={(e) => updateRs({ show_student_answers: e.target.checked })}
                          />
                          Show student&apos;s submitted answers
                        </label>
                        <label className={`flex items-center gap-2 text-xs ${rs.show_question_results ? "text-slate-700" : "text-slate-400"}`}>
                          <input
                            type="checkbox"
                            disabled={!rs.show_question_results}
                            checked={rs.show_points_per_question}
                            onChange={(e) => updateRs({ show_points_per_question: e.target.checked })}
                          />
                          Show points awarded per question
                        </label>
                        <label className={`flex items-center gap-2 text-xs ${rs.show_question_results ? "text-slate-700" : "text-slate-400"}`}>
                          <input
                            type="checkbox"
                            disabled={!rs.show_question_results}
                            checked={rs.show_correct_answers}
                            onChange={(e) => updateRs({ show_correct_answers: e.target.checked })}
                          />
                          Show correct answers
                        </label>
                        <label className={`flex items-center gap-2 text-xs ${rs.show_question_results ? "text-slate-700" : "text-slate-400"}`}>
                          <input
                            type="checkbox"
                            disabled={!rs.show_question_results}
                            checked={rs.show_explanations}
                            onChange={(e) => updateRs({ show_explanations: e.target.checked })}
                          />
                          Show question explanations
                        </label>
                        {!rs.show_question_results && (
                          <p className="text-[10px] text-amber-700">
                            Correct answers and explanations require &quot;Show individual question results&quot; to be enabled.
                          </p>
                        )}
                      </div>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-3 space-y-2">
                      <p className="text-xs font-bold text-slate-700">Result Release Timing</p>
                      <div className="flex flex-wrap gap-4">
                        <label className="flex items-center gap-2 text-xs text-slate-700">
                          <input
                            type="radio"
                            name={`release-timing-${draft.id}`}
                            value="immediately"
                            checked={rs.release_timing === "immediately"}
                            onChange={() => updateRs({ release_timing: "immediately" as ResultReleaseTiming })}
                          />
                          Immediately after submission
                        </label>
                        <label className={`flex items-center gap-2 text-xs ${draft.max_attempts === null ? "text-slate-400" : "text-slate-700"}`}>
                          <input
                            type="radio"
                            name={`release-timing-${draft.id}`}
                            value="after_final_attempt"
                            disabled={draft.max_attempts === null}
                            checked={rs.release_timing === "after_final_attempt"}
                            onChange={() => updateRs({ release_timing: "after_final_attempt" as ResultReleaseTiming })}
                          />
                          After the student&apos;s final permitted attempt
                        </label>
                      </div>
                      {draft.max_attempts === null && (
                        <p className="text-[10px] text-slate-500">
                          Set a finite Maximum attempts limit above to enable releasing results after the final permitted attempt.
                        </p>
                      )}
                    </div>

                    <div className="rounded-lg border border-teal-100 bg-teal-50/50 p-2.5 text-[11px] text-teal-950">
                      <span className="font-bold">Student visibility summary:</span>{" "}
                      {rs.release_timing === "after_final_attempt"
                        ? "Results withheld until final permitted attempt. "
                        : "Released immediately after submission. "}
                      {visibleSummary.length > 0
                        ? `Visible: ${visibleSummary.join(", ")}.`
                        : "All score and question review details are hidden."}
                    </div>
                  </section>
                );
              })()}

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
                            aria-invalid={Boolean(readError(`questions.${selectedQuestionIndex}.points`))}
                            aria-describedby={readError(`questions.${selectedQuestionIndex}.points`) ? `field-error-question-${selectedQuestionIndex}-points` : undefined}
                            data-field-path={`questions.${selectedQuestionIndex}.points`}
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
                            className={`mt-1 w-full rounded-lg border p-2 ${
                              readError(`questions.${selectedQuestionIndex}.points`)
                                ? "border-rose-400"
                                : "border-slate-300"
                            } ${
                              selectedQuestion.question_type === "fill_in_the_blanks"
                                ? "cursor-not-allowed bg-slate-100 text-slate-600"
                                : "bg-white"
                            }`}
                          />
                          {readError(`questions.${selectedQuestionIndex}.points`) && (
                            <span id={`field-error-question-${selectedQuestionIndex}-points`} role="alert" className="mt-1 block text-rose-700">
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
                              aria-describedby={readError(`questions.${selectedQuestionIndex}.prompt`) ? `field-error-question-${selectedQuestionIndex}-prompt` : undefined}
                              data-field-path={`questions.${selectedQuestionIndex}.prompt`}
                              className={`mt-1 w-full rounded-lg border bg-white p-2 ${
                                readError(`questions.${selectedQuestionIndex}.prompt`)
                                  ? "border-rose-400"
                                  : "border-slate-300"
                              }`}
                            />
                            {readError(`questions.${selectedQuestionIndex}.prompt`) && (
                              <span id={`field-error-question-${selectedQuestionIndex}-prompt`} role="alert" className="mt-1 block text-rose-700">
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
                  ref={previewButtonRef}
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
          onClose={() => {
            setIsPreviewOpen(false);
            window.requestAnimationFrame(() => previewButtonRef.current?.focus());
          }}
        />
      )}
    </section>
  );
}
