"use client";

import React, { useEffect, useState } from "react";
import {
  AdminLearningApiError,
  createAdminExercise,
  deleteAdminExercise,
  getAdminExercises,
  publishAdminExercise,
  updateAdminExercise,
  type AdminFullExercise,
  type BlankAnswerConfigPayload,
  type BlankDefinitionPayload,
  type CreateExerciseOptionPayload,
  type CreateExercisePayload,
  type CreateQuestionPayload,
  type ShortAnswerConfigPayload,
} from "@/lib/admin-learning";
import type { QuestionType } from "@/lib/learning";
import {
  createQuestionDraft,
  findDuplicateAcceptedAnswerIndexes,
  findDuplicateOptionIndexes,
  generateUniqueBlankId,
  normalizeShortAnswer,
  sumBlankPoints,
  validateFitbPromptPlaceholders,
} from "@/lib/exercise-authoring.mjs";

interface ExerciseDraftBuilderProps {
  centerSlug: string;
  courseSlug: string;
  lessonId: number;
  onInsert: (exerciseId: number) => Promise<void>;
  onDirtyChange: (dirty: boolean) => void;
}

type DraftQuestion = Omit<CreateQuestionPayload, "id" | "options"> & {
  id?: number;
  options: CreateExerciseOptionPayload[];
};

type ExerciseDraft = Omit<AdminFullExercise, "questions"> & {
  questions: DraftQuestion[];
};

const QUESTION_TYPES: { value: QuestionType; label: string }[] = [
  { value: "single_choice", label: "Single choice" },
  { value: "true_false", label: "True / False" },
  { value: "multiple_select", label: "Multiple select" },
  { value: "short_answer", label: "Short answer" },
  { value: "fill_in_the_blanks", label: "Fill in the Blanks" },
];

function toDraftQuestion(question: AdminFullExercise["questions"][number]): DraftQuestion {
  return {
    id: question.id,
    prompt: question.prompt,
    question_type: question.question_type,
    answer_config: question.answer_config,
    explanation: question.explanation,
    points: question.points,
    sort_order: question.sort_order,
    options: question.options.map((option) => ({ ...option })),
  };
}

function toDraftExercise(exercise: AdminFullExercise): ExerciseDraft {
  return { ...exercise, questions: exercise.questions.map(toDraftQuestion) };
}

function validationErrors(draft: ExerciseDraft): Record<string, string> {
  const errors: Record<string, string> = {};

  if (!draft.title.trim()) errors.title = "An exercise title is required.";
  if (!Number.isFinite(draft.passing_score) || draft.passing_score < 0 || draft.passing_score > 100) {
    errors.passing_score = "Passing score must be between 0 and 100.";
  }

  draft.questions.forEach((question, questionIndex) => {
    const prefix = `questions.${questionIndex}`;
    if (!question.prompt.trim()) errors[`${prefix}.prompt`] = "Question prompt is required.";
    if (!Number.isFinite(Number(question.points)) || Number(question.points) <= 0) {
      errors[`${prefix}.points`] = "Points must be greater than zero.";
    }

    if (question.question_type === "fill_in_the_blanks") {
      const blanksConfig = question.answer_config as { blanks?: Array<{ id?: string; points?: number; accepted_answers?: string[]; normalization?: Record<string, boolean> }> } | undefined;
      const blanks = blanksConfig?.blanks ?? [];
      if (!blanks || blanks.length === 0) {
        errors[`${prefix}.answer_config.blanks`] = "Add at least one blank.";
      } else {
        const seenIds = new Set<string>();
        blanks.forEach((blank, blankIndex) => {
          const bPrefix = `${prefix}.answer_config.blanks.${blankIndex}`;
          if (!blank.id || !blank.id.trim()) {
            errors[`${bPrefix}.id`] = "Blank ID is required.";
          } else if (!/^[a-zA-Z0-9_\-]+$/.test(blank.id)) {
            errors[`${bPrefix}.id`] = "Blank ID must contain only alphanumeric characters, underscores, and hyphens.";
          } else if (seenIds.has(blank.id)) {
            errors[`${bPrefix}.id`] = `Blank ID '${blank.id}' is duplicated.`;
          } else {
            seenIds.add(blank.id);
          }

          if (blank.points !== undefined && (!Number.isFinite(Number(blank.points)) || Number(blank.points) <= 0)) {
            errors[`${bPrefix}.points`] = "Points must be greater than zero.";
          }

          const accepted = blank.accepted_answers ?? [];
          if (!accepted.some((a) => a.trim() !== "")) {
            errors[`${bPrefix}.accepted_answers`] = "Add at least one accepted answer.";
          }
          const duplicateAccepted = findDuplicateAcceptedAnswerIndexes(accepted, blank.normalization ?? {});
          if (duplicateAccepted.length > 0) {
            errors[`${bPrefix}.accepted_answers`] = `Accepted answer ${duplicateAccepted[0] + 1} duplicates an earlier answer.`;
          }
          accepted.forEach((ans, aIdx) => {
            if (ans.length > 500) {
              errors[`${bPrefix}.accepted_answers.${aIdx}`] = "Accepted answers may not exceed 500 characters.";
            }
          });
        });

        const placeholderCheck = validateFitbPromptPlaceholders(question.prompt, blanks);
        if (placeholderCheck.promptErrors.length > 0 && !errors[`${prefix}.prompt`]) {
          errors[`${prefix}.prompt`] = placeholderCheck.promptErrors[0];
        }
        if (placeholderCheck.blanksErrors.length > 0 && !errors[`${prefix}.answer_config.blanks`]) {
          errors[`${prefix}.answer_config.blanks`] = placeholderCheck.blanksErrors[0];
        }
        if (sumBlankPoints(blanks) <= 0) {
          errors[`${prefix}.points`] = "Total blank points must be greater than zero.";
        }
      }
      return;
    }

    if (question.question_type === "short_answer") {
      const shortConfig = question.answer_config as ShortAnswerConfigPayload | null | undefined;
      const accepted = shortConfig?.accepted_answers ?? [];
      const normalization = shortConfig?.normalization;
      if (!accepted.some((answer) => answer.trim() !== "")) {
        errors[`${prefix}.answer_config.accepted_answers`] = "Add at least one accepted answer.";
      }
      const duplicateAccepted = findDuplicateAcceptedAnswerIndexes(accepted, normalization ?? {});
      if (duplicateAccepted.length > 0) {
        errors[`${prefix}.answer_config.accepted_answers`] = `Accepted answer ${duplicateAccepted[0] + 1} duplicates an earlier answer after normalization.`;
      }
      accepted.forEach((answer, answerIndex) => {
        if (answer.length > 500) {
          errors[`${prefix}.answer_config.accepted_answers.${answerIndex}`] = "Accepted answers may not exceed 500 characters.";
        }
      });
      if (accepted.length > 20) errors[`${prefix}.answer_config.accepted_answers`] = "A maximum of 20 accepted answers is allowed.";
      return;
    }

    question.options.forEach((option, optionIndex) => {
      if (!option.option_text.trim()) {
        errors[`${prefix}.options.${optionIndex}.option_text`] = "Option text is required before publishing.";
      }
    });

    const duplicateOptions = findDuplicateOptionIndexes(question.options.map((option) => option.option_text));
    if (duplicateOptions.length > 0) {
      errors[`${prefix}.options.${duplicateOptions[0]}.option_text`] = "This option duplicates another option in this question.";
    }

    const correctCount = question.options.filter((option) => option.is_correct).length;
    if (question.question_type === "single_choice" && (question.options.length < 2 || correctCount !== 1)) {
      errors[`${prefix}.options`] = "Add at least two options and mark exactly one correct answer.";
    } else if (question.question_type === "true_false" && (question.options.length !== 2 || correctCount !== 1)) {
      errors[`${prefix}.options`] = "True / False must have exactly two options and one correct answer.";
    } else if (
      question.question_type === "multiple_select"
      && (question.options.length < 3 || correctCount < 2 || correctCount >= question.options.length)
    ) {
      errors[`${prefix}.options`] = "Multiple select needs at least three options, two correct answers, and one incorrect answer.";
    }
  });

  return errors;
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
  const [isLoading, setIsLoading] = useState(true);
  const [isBusy, setIsBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [hasUnsavedEdits, setHasUnsavedEdits] = useState(false);
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const [previewAnswers, setPreviewAnswers] = useState<Record<number, number | number[] | string | Record<string, string>>>({});

  const loadExercises = async (preferredId?: number) => {
    const rows = await getAdminExercises(centerSlug, courseSlug, lessonId);
    setExercises(rows);
    if (preferredId) {
      const preferred = rows.find((row) => row.id === preferredId);
      if (preferred) setDraft(toDraftExercise(preferred));
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
    return () => { mounted = false; };
  }, [centerSlug, courseSlug, lessonId]);

  const markDirty = () => {
    setHasUnsavedEdits(true);
    onDirtyChange(true);
    setFieldErrors({});
  };

  const updateDraft = (update: (current: ExerciseDraft) => ExerciseDraft) => {
    markDirty();
    setDraft((current) => current ? update(current) : current);
  };

  const handleNewDraft = async () => {
    if (hasUnsavedEdits && !window.confirm("Discard unsaved edits and create another draft?")) return;
    setIsBusy(true);
    setError(null);
    try {
      const created = await createAdminExercise(centerSlug, courseSlug, lessonId, {
        title: "Untitled Exercise",
        passing_score: 70,
      });
      setDraft(toDraftExercise(created));
      setHasUnsavedEdits(false);
      onDirtyChange(false);
      await loadExercises(created.id);
    } catch (cause: unknown) {
      setError(cause instanceof Error ? cause.message : "Could not create an exercise draft.");
    } finally {
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
    questions: value.questions.map((question, index) => ({
      ...question,
      points:
        question.question_type === "fill_in_the_blanks"
          ? sumBlankPoints((question.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? [])
          : question.points,
      sort_order: index,
      answer_config:
        question.question_type === "short_answer" || question.question_type === "fill_in_the_blanks"
          ? question.answer_config
          : null,
      options:
        question.question_type === "short_answer" || question.question_type === "fill_in_the_blanks"
          ? []
          : question.options.map((option, optionIndex) => ({ ...option, sort_order: optionIndex })),
    })),
  });

  const saveDraft = async (): Promise<AdminFullExercise | null> => {
    if (!draft) return null;
    setIsBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const saved = await updateAdminExercise(
        centerSlug,
        courseSlug,
        lessonId,
        draft.id,
        buildPayload(draft)
      );
      setDraft(toDraftExercise(saved));
      setHasUnsavedEdits(false);
      onDirtyChange(false);
      await loadExercises(saved.id);
      return saved;
    } catch (cause: unknown) {
      if (cause instanceof AdminLearningApiError && cause.errors) {
        const mapped = Object.fromEntries(
          Object.entries(cause.errors).map(([field, messages]) => [field, messages[0] ?? "Invalid value."])
        );
        setFieldErrors(mapped);
      }
      setError(cause instanceof Error ? cause.message : "Could not save the exercise draft.");
      return null;
    } finally {
      setIsBusy(false);
    }
  };

  const publish = async () => {
    if (!draft) return;
    const localErrors = validationErrors(draft);
    if (Object.keys(localErrors).length > 0) {
      setFieldErrors(localErrors);
      setError("Review the marked fields before publishing.");
      return;
    }

    const saved = await saveDraft();
    if (!saved) return;

    setIsBusy(true);
    setError(null);
    try {
      const published = await publishAdminExercise(centerSlug, courseSlug, lessonId, saved.id);
      setDraft(toDraftExercise(published));
      setHasUnsavedEdits(false);
      await loadExercises(published.id);
    } catch (cause: unknown) {
      if (cause instanceof AdminLearningApiError && cause.errors) {
        setFieldErrors(Object.fromEntries(
          Object.entries(cause.errors).map(([field, messages]) => [field, messages[0] ?? "Invalid value."])
        ));
      }
      setError(cause instanceof Error ? cause.message : "Could not publish the exercise.");
    } finally {
      setIsBusy(false);
    }
  };

  const addQuestion = () => updateDraft((current) => ({
    ...current,
    questions: [...current.questions, {
      ...createQuestionDraft("single_choice") as DraftQuestion,
      sort_order: current.questions.length,
    }],
  }));

  const changeQuestionType = (index: number, type: QuestionType) => updateDraft((current) => ({
    ...current,
    questions: current.questions.map((question, questionIndex) => {
      if (questionIndex !== index) return question;
      const replacement = createQuestionDraft(type) as DraftQuestion;
      return {
        ...replacement,
        id: question.id,
        sort_order: question.sort_order,
        prompt: question.prompt,
        points: question.points,
        explanation: question.explanation,
      };
    }),
  }));

  const updateQuestion = (index: number, patch: Partial<DraftQuestion>) => updateDraft((current) => ({
    ...current,
    questions: current.questions.map((question, questionIndex) =>
      questionIndex === index ? { ...question, ...patch } : question
    ),
  }));

  const updateOption = (questionIndex: number, optionIndex: number, patch: Partial<DraftQuestion["options"][number]>) => {
    const question = draft?.questions[questionIndex];
    if (!question) return;
    updateQuestion(questionIndex, {
      options: question.options.map((option, index) => {
        const updated = index === optionIndex ? { ...option, ...patch } : option;
        if (patch.is_correct && question.question_type !== "multiple_select") {
          return { ...updated, is_correct: index === optionIndex };
        }
        return updated;
      }),
    });
  };

  const updateAcceptedAnswer = (questionIndex: number, answerIndex: number, value: string) => {
    const question = draft?.questions[questionIndex];
    const shortConfig = question?.answer_config as ShortAnswerConfigPayload | null | undefined;
    const answers = shortConfig?.accepted_answers;
    if (!question || !shortConfig || !answers) return;
    updateQuestion(questionIndex, {
      answer_config: {
        ...shortConfig,
        accepted_answers: answers.map((answer, index) => index === answerIndex ? value : answer),
      },
    });
  };

  const updateNormalization = (
    questionIndex: number,
    key: keyof ShortAnswerConfigPayload["normalization"],
    value: boolean
  ) => {
    const question = draft?.questions[questionIndex];
    const shortConfig = question?.answer_config as ShortAnswerConfigPayload | null | undefined;
    if (!shortConfig) return;
    updateQuestion(questionIndex, {
      answer_config: {
        ...shortConfig,
        normalization: { ...shortConfig.normalization, [key]: value },
      },
    });
  };

  // ── FITB blank helpers ──────────────────────────────────────────────────
  const updateBlank = (qIdx: number, blankIdx: number, patch: Partial<BlankDefinitionPayload>) =>
    updateDraft((current) => ({
      ...current,
      questions: current.questions.map((q, qi) => {
        if (qi !== qIdx) return q;
        const blanks = ((q.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? []).map(
          (b, bi) => (bi === blankIdx ? { ...b, ...patch } : b)
        );
        return { ...q, points: sumBlankPoints(blanks), answer_config: { blanks } };
      }),
    }));

  const removeBlank = (qIdx: number, blankIdx: number) =>
    updateDraft((current) => ({
      ...current,
      questions: current.questions.map((q, qi) => {
        if (qi !== qIdx) return q;
        const blanks = ((q.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? []).filter(
          (_, bi) => bi !== blankIdx
        );
        return { ...q, points: sumBlankPoints(blanks), answer_config: { blanks } };
      }),
    }));

  const addBlank = (qIdx: number) =>
    updateDraft((current) => ({
      ...current,
      questions: current.questions.map((q, qi) => {
        if (qi !== qIdx) return q;
        const blanks = (q.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? [];
        const newBlank: BlankDefinitionPayload = {
          id: generateUniqueBlankId(blanks, q.prompt),
          points: 1,
          accepted_answers: [""],
          normalization: {
            case_sensitive: false,
            trim_whitespace: true,
            collapse_internal_whitespace: true,
            punctuation_sensitive: true,
            diacritic_sensitive: true,
          },
        };
        const nextBlanks = [...blanks, newBlank];
        return { ...q, points: sumBlankPoints(nextBlanks), answer_config: { blanks: nextBlanks } };
      }),
    }));

  const updateBlankAnswer = (qIdx: number, blankIdx: number, ansIdx: number, value: string) =>
    updateDraft((current) => ({
      ...current,
      questions: current.questions.map((q, qi) => {
        if (qi !== qIdx) return q;
        const blanks = ((q.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? []).map((b, bi) => {
          if (bi !== blankIdx) return b;
          const accepted = [...(b.accepted_answers ?? [])];
          accepted[ansIdx] = value;
          return { ...b, accepted_answers: accepted };
        });
        return { ...q, answer_config: { blanks } };
      }),
    }));

  const removeBlankAnswer = (qIdx: number, blankIdx: number, ansIdx: number) =>
    updateDraft((current) => ({
      ...current,
      questions: current.questions.map((q, qi) => {
        if (qi !== qIdx) return q;
        const blanks = ((q.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? []).map((b, bi) => {
          if (bi !== blankIdx) return b;
          return { ...b, accepted_answers: (b.accepted_answers ?? []).filter((_, ai) => ai !== ansIdx) };
        });
        return { ...q, answer_config: { blanks } };
      }),
    }));

  const handleDeleteDraft = async () => {
    if (!draft || draft.status !== "draft") return;
    if (!window.confirm("Delete this exercise draft?")) return;
    setIsBusy(true);
    try {
      await deleteAdminExercise(centerSlug, courseSlug, lessonId, draft.id);
      setDraft(null);
      onDirtyChange(false);
      await loadExercises();
    } catch (cause: unknown) {
      setError(cause instanceof Error ? cause.message : "Could not delete the draft.");
    } finally {
      setIsBusy(false);
    }
  };

  const moveQuestion = (index: number, direction: -1 | 1) => updateDraft((current) => {
    const destination = index + direction;
    if (destination < 0 || destination >= current.questions.length) return current;
    const questions = [...current.questions];
    [questions[index], questions[destination]] = [questions[destination], questions[index]];
    return { ...current, questions: questions.map((question, sort_order) => ({ ...question, sort_order })) };
  });

  const duplicateQuestion = (index: number) => updateDraft((current) => {
    const source = current.questions[index];
    if (!source) return current;
    const copy: DraftQuestion = {
      ...source,
      id: undefined,
      sort_order: index + 1,
      answer_config: source.answer_config
        ? source.question_type === "fill_in_the_blanks"
          ? {
              blanks: ((source.answer_config as BlankAnswerConfigPayload).blanks ?? []).map((b) => ({
                ...b,
                accepted_answers: [...(b.accepted_answers ?? [])],
                normalization: { ...b.normalization },
              })),
            }
          : {
              accepted_answers: [...(source.answer_config as ShortAnswerConfigPayload).accepted_answers],
              normalization: { ...(source.answer_config as ShortAnswerConfigPayload).normalization },
            }
        : null,
      options: source.options.map(({ option_text, is_correct, sort_order }) => ({
        option_text,
        is_correct,
        sort_order,
      })),
    };
    const questions = [...current.questions];
    questions.splice(index + 1, 0, copy);
    return { ...current, questions: questions.map((question, sort_order) => ({ ...question, sort_order })) };
  });

  const moveOption = (questionIndex: number, optionIndex: number, direction: -1 | 1) => {
    const question = draft?.questions[questionIndex];
    if (!question) return;
    const destination = optionIndex + direction;
    if (destination < 0 || destination >= question.options.length) return;
    const options = [...question.options];
    [options[optionIndex], options[destination]] = [options[destination], options[optionIndex]];
    updateQuestion(questionIndex, { options: options.map((option, sort_order) => ({ ...option, sort_order })) });
  };

  const readError = (path: string) => fieldErrors[path];
  const editable = draft?.status === "draft";

  return (
    <section className="mt-4 rounded-2xl border border-teal-200 bg-white p-4 sm:p-5" aria-label="Exercise draft builder">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
        <div>
          <h3 className="text-sm font-black text-[#102b4c]">Exercise Draft Builder</h3>
          <p className="mt-1 text-xs text-slate-600">Save and reopen a draft, preview it, then publish and insert it into this lesson.</p>
        </div>
        <button type="button" disabled={isBusy} onClick={() => void handleNewDraft()} className="rounded-xl bg-[#0e8988] px-3 py-2 text-xs font-bold text-white disabled:opacity-50">
          + New Draft
        </button>
      </div>

      {error && <div role="alert" className="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">{error}</div>}

      <div className="mt-4 grid gap-4 lg:grid-cols-[250px_minmax(0,1fr)]">
        <aside aria-label="Saved exercises" className="space-y-2">
          {isLoading ? <p className="text-xs text-slate-500">Loading exercises…</p> : null}
          {!isLoading && exercises.length === 0 ? <p className="text-xs text-slate-500">No exercises for this lesson yet.</p> : null}
          {exercises.map((exercise) => (
            <button
              type="button"
              disabled={isBusy}
              key={exercise.id}
              aria-current={draft?.id === exercise.id ? "true" : undefined}
              onClick={() => {
                if (hasUnsavedEdits && draft && draft.id !== exercise.id && !window.confirm("Discard unsaved draft edits?")) return;
                setDraft(toDraftExercise(exercise));
                setHasUnsavedEdits(false);
                setFieldErrors({});
                setError(null);
                onDirtyChange(false);
              }}
              className={`w-full rounded-xl border p-3 text-left ${draft?.id === exercise.id ? "border-teal-500 bg-teal-50" : "border-slate-200 hover:bg-slate-50"}`}
            >
              <span className="block truncate text-xs font-bold text-slate-800">{exercise.title}</span>
              <span className="mt-1 block text-[10px] capitalize text-slate-500">{exercise.status} · {exercise.questions.length} questions</span>
            </button>
          ))}
        </aside>

        {!draft ? (
          <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-xs text-slate-500">Choose an exercise or create a draft to start.</div>
        ) : (
          <div className="min-w-0 space-y-4">
            {!editable && <div role="status" className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">This exercise is {draft.status}. Published content is protected by the lesson revision rules.</div>}

            <fieldset disabled={!editable || isBusy} className="space-y-3 disabled:opacity-75">
              <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-xs font-semibold text-slate-700 sm:col-span-2">
                  Exercise title
                  <input value={draft.title} maxLength={255} onChange={(event) => updateDraft((current) => ({ ...current, title: event.target.value }))} aria-invalid={Boolean(readError("title"))} className="mt-1 w-full rounded-lg border border-slate-300 p-2" />
                  {readError("title") && <span className="mt-1 block text-rose-700" role="alert">{readError("title")}</span>}
                </label>
                <label className="text-xs font-semibold text-slate-700 sm:col-span-2">
                  Instructions
                  <textarea value={draft.instructions ?? ""} onChange={(event) => updateDraft((current) => ({ ...current, instructions: event.target.value || null }))} rows={2} className="mt-1 w-full rounded-lg border border-slate-300 p-2" />
                </label>
                <label className="text-xs font-semibold text-slate-700">
                  Passing score (%)
                  <input type="number" min={0} max={100} value={draft.passing_score} onChange={(event) => updateDraft((current) => ({ ...current, passing_score: Number(event.target.value) }))} aria-invalid={Boolean(readError("passing_score"))} className="mt-1 w-full rounded-lg border border-slate-300 p-2" />
                  {readError("passing_score") && <span className="mt-1 block text-rose-700" role="alert">{readError("passing_score")}</span>}
                </label>
                <label className="text-xs font-semibold text-slate-700">
                  Maximum attempts (blank means unlimited)
                  <input type="number" min={1} value={draft.max_attempts ?? ""} onChange={(event) => updateDraft((current) => ({ ...current, max_attempts: event.target.value === "" ? null : Number(event.target.value) }))} className="mt-1 w-full rounded-lg border border-slate-300 p-2" />
                </label>
                <label className="flex items-center gap-2 text-xs font-medium text-slate-700 sm:col-span-2">
                  <input type="checkbox" checked={draft.show_correct_answers_after_submission} onChange={(event) => updateDraft((current) => ({ ...current, show_correct_answers_after_submission: event.target.checked }))} />
                  Show correct answers and explanations after submission
                </label>
              </div>

              <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                <h4 className="text-xs font-black text-[#102b4c]">Questions ({draft.questions.length})</h4>
                <button type="button" onClick={addQuestion} className="rounded-lg border border-teal-300 px-3 py-1.5 text-xs font-bold text-teal-800">+ Add question</button>
              </div>

              {draft.questions.map((question, questionIndex) => (
                <fieldset key={question.id ?? `new-${questionIndex}`} className="rounded-xl border border-slate-200 p-3 sm:p-4">
                  <legend className="px-1 text-xs font-bold text-slate-700">Question {questionIndex + 1}</legend>
                  <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_140px]">
                    <label className="text-xs font-semibold text-slate-700">
                      Question type
                      <select value={question.question_type} onChange={(event) => changeQuestionType(questionIndex, event.target.value as QuestionType)} className="mt-1 w-full rounded-lg border border-slate-300 p-2">
                        {QUESTION_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                      </select>
                    </label>
                    <label className="text-xs font-semibold text-slate-700">
                      {question.question_type === "fill_in_the_blanks" ? "Total points (from blanks)" : "Points"}
                      <input
                        type="number"
                        min={0.01}
                        step={0.25}
                        readOnly={question.question_type === "fill_in_the_blanks"}
                        aria-readonly={question.question_type === "fill_in_the_blanks" ? "true" : undefined}
                        value={
                          question.question_type === "fill_in_the_blanks"
                            ? sumBlankPoints((question.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? [])
                            : (question.points ?? 1)
                        }
                        onChange={(event) => {
                          if (question.question_type !== "fill_in_the_blanks") {
                            updateQuestion(questionIndex, { points: Number(event.target.value) });
                          }
                        }}
                        className={`mt-1 w-full rounded-lg border border-slate-300 p-2 ${question.question_type === "fill_in_the_blanks" ? "bg-slate-100 text-slate-600 cursor-not-allowed" : ""}`}
                      />
                      {readError(`questions.${questionIndex}.points`) && <span role="alert" className="mt-1 block text-rose-700">{readError(`questions.${questionIndex}.points`)}</span>}
                    </label>
                    <label className="text-xs font-semibold text-slate-700 sm:col-span-2">
                      Prompt
                      <textarea value={question.prompt} onChange={(event) => updateQuestion(questionIndex, { prompt: event.target.value })} rows={2} aria-invalid={Boolean(readError(`questions.${questionIndex}.prompt`))} className="mt-1 w-full rounded-lg border border-slate-300 p-2" />
                      {readError(`questions.${questionIndex}.prompt`) && <span role="alert" className="mt-1 block text-rose-700">{readError(`questions.${questionIndex}.prompt`)}</span>}
                    </label>
                  </div>

                  {question.question_type === "fill_in_the_blanks" ? (
                    <div className="mt-3 space-y-3 rounded-lg bg-slate-50 p-3">
                      <div>
                        <h5 className="text-xs font-bold text-slate-700">Prompt with Blanks</h5>
                        <p className="mt-1 text-[11px] text-slate-500">
                          Use [blank_id] syntax in the prompt above. e.g. &quot;The capital of France is [blank_1].&quot;
                        </p>
                      </div>
                      <div className="space-y-3">
                        {((question.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? []).map((blank, blankIndex) => (
                          <div key={blankIndex} className="space-y-2 rounded-lg border border-slate-200 bg-white p-3">
                            <div className="flex flex-wrap items-center gap-2">
                              <label className="w-16 text-xs font-semibold text-slate-700">Blank ID</label>
                              <input
                                value={blank.id ?? ""}
                                maxLength={50}
                                aria-label={`Blank ${blankIndex + 1} ID`}
                                onChange={(event) => updateBlank(questionIndex, blankIndex, { id: event.target.value })}
                                className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white p-2 font-mono text-xs"
                              />
                              <label className="text-xs font-semibold text-slate-700">Points</label>
                              <input
                                type="number"
                                min={0.01}
                                step={0.25}
                                value={blank.points ?? 1}
                                aria-label={`Blank ${blankIndex + 1} points`}
                                onChange={(event) => updateBlank(questionIndex, blankIndex, { points: Number(event.target.value) })}
                                className="w-20 rounded-lg border border-slate-300 bg-white p-2 text-xs"
                              />
                              <button
                                type="button"
                                onClick={() => removeBlank(questionIndex, blankIndex)}
                                className="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700"
                              >
                                Remove
                              </button>
                            </div>
                            {readError(`questions.${questionIndex}.answer_config.blanks.${blankIndex}.id`) && (
                              <p role="alert" className="text-xs text-rose-700">
                                {readError(`questions.${questionIndex}.answer_config.blanks.${blankIndex}.id`)}
                              </p>
                            )}
                            {readError(`questions.${questionIndex}.answer_config.blanks.${blankIndex}.points`) && (
                              <p role="alert" className="text-xs text-rose-700">
                                {readError(`questions.${questionIndex}.answer_config.blanks.${blankIndex}.points`)}
                              </p>
                            )}
                            <div>
                              <p className="text-[11px] font-semibold text-slate-700">Accepted answers</p>
                              {(blank.accepted_answers ?? []).map((ans, ansIdx) => (
                                <div key={ansIdx} className="mt-1 flex gap-2">
                                  <input
                                    value={ans}
                                    maxLength={500}
                                    aria-label={`Blank ${blankIndex + 1} accepted answer ${ansIdx + 1}`}
                                    onChange={(event) => updateBlankAnswer(questionIndex, blankIndex, ansIdx, event.target.value)}
                                    className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white p-2 text-xs"
                                  />
                                  <button
                                    type="button"
                                    onClick={() => removeBlankAnswer(questionIndex, blankIndex, ansIdx)}
                                    className="rounded border px-2 text-xs"
                                  >
                                    Remove
                                  </button>
                                </div>
                              ))}
                              <button
                                type="button"
                                onClick={() =>
                                  updateBlank(questionIndex, blankIndex, {
                                    accepted_answers: [...(blank.accepted_answers ?? []), ""],
                                  })
                                }
                                className="mt-1 text-xs font-bold text-teal-800"
                              >
                                + Add answer
                              </button>
                            </div>
                            {readError(`questions.${questionIndex}.answer_config.blanks.${blankIndex}.accepted_answers`) && (
                              <p role="alert" className="text-xs text-rose-700">
                                {readError(`questions.${questionIndex}.answer_config.blanks.${blankIndex}.accepted_answers`)}
                              </p>
                            )}
                          </div>
                        ))}
                        <button
                          type="button"
                          onClick={() => addBlank(questionIndex)}
                          className="text-xs font-bold text-teal-800"
                        >
                          + Add blank
                        </button>
                        {readError(`questions.${questionIndex}.answer_config.blanks`) && (
                          <p role="alert" className="text-xs text-rose-700">
                            {readError(`questions.${questionIndex}.answer_config.blanks`)}
                          </p>
                        )}
                      </div>
                    </div>
                  ) : question.question_type === "short_answer" ? (() => {
                    const shortConfig = question.answer_config as ShortAnswerConfigPayload | null | undefined;
                    return (
                    <div className="mt-3 space-y-3 rounded-lg bg-slate-50 p-3">
                      <div>
                        <h5 className="text-xs font-bold text-slate-700">Accepted answers</h5>
                        <p className="mt-1 text-[11px] text-slate-500">Exact matching after the settings below. No fuzzy or AI grading is used.</p>
                        {(shortConfig?.accepted_answers ?? []).map((answer, answerIndex) => (
                          <div key={answerIndex} className="mt-2 flex gap-2">
                            <input value={answer} maxLength={500} aria-label={`Accepted answer ${answerIndex + 1}`} onChange={(event) => updateAcceptedAnswer(questionIndex, answerIndex, event.target.value)} className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white p-2 text-xs" />
                            <button type="button" aria-label={`Remove accepted answer ${answerIndex + 1}`} onClick={() => updateQuestion(questionIndex, { answer_config: { ...shortConfig!, accepted_answers: shortConfig!.accepted_answers.filter((_, index) => index !== answerIndex) } })} className="rounded-lg border px-2 text-xs">Remove</button>
                          </div>
                        ))}
                        <button type="button" onClick={() => updateQuestion(questionIndex, { answer_config: { ...shortConfig!, accepted_answers: [...shortConfig!.accepted_answers, ""] } })} className="mt-2 text-xs font-bold text-teal-800">+ Add accepted answer</button>
                        {readError(`questions.${questionIndex}.answer_config.accepted_answers`) && <p role="alert" className="mt-1 text-xs text-rose-700">{readError(`questions.${questionIndex}.answer_config.accepted_answers`)}</p>}
                      </div>

                      <fieldset className="grid gap-2 sm:grid-cols-2">
                        <legend className="mb-2 text-xs font-bold text-slate-700">Matching rules</legend>
                        {([
                          ["case_sensitive", "Case sensitive"],
                          ["trim_whitespace", "Trim outer whitespace"],
                          ["collapse_internal_whitespace", "Collapse repeated spaces"],
                          ["punctuation_sensitive", "Punctuation matters"],
                          ["diacritic_sensitive", "Accents matter"],
                        ] as const).map(([key, label]) => (
                          <label key={key} className="flex items-center gap-2 text-[11px] text-slate-700">
                            <input type="checkbox" checked={shortConfig?.normalization[key] ?? true} onChange={(event) => updateNormalization(questionIndex, key, event.target.checked)} />
                            {label}
                          </label>
                        ))}
                      </fieldset>
                      <div className="rounded-md bg-white p-2 text-[11px] text-slate-600" aria-live="polite">
                        Example: {JSON.stringify(shortConfig?.accepted_answers?.[0] ?? "")} matches {JSON.stringify(normalizeShortAnswer(shortConfig?.accepted_answers?.[0] ?? "", shortConfig?.normalization ?? {}))} under these rules.
                      </div>
                    </div>
                    );
                  })() : (
                    <div className="mt-3 rounded-lg bg-slate-50 p-3">
                      {question.question_type === "multiple_select" && <p className="mb-2 text-[11px] text-slate-600">Students must select every correct answer and no incorrect answers to receive full credit.</p>}
                      <div className="space-y-2">
                        {question.options.map((option, optionIndex) => (
                          <div key={option.id ?? `option-${optionIndex}`} className="flex items-center gap-2">
                            <input
                              type={question.question_type === "multiple_select" ? "checkbox" : "radio"}
                              name={`correct-${question.id ?? questionIndex}`}
                              aria-label={`Mark option ${optionIndex + 1} correct`}
                              checked={option.is_correct}
                              onChange={(event) => updateOption(questionIndex, optionIndex, { is_correct: event.target.checked })}
                            />
                            <input value={option.option_text} maxLength={2000} aria-label={`Question ${questionIndex + 1} option ${optionIndex + 1}`} onChange={(event) => updateOption(questionIndex, optionIndex, { option_text: event.target.value })} className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white p-2 text-xs" />
                            {question.question_type !== "true_false" && <>
                              <button type="button" aria-label={`Move option ${optionIndex + 1} up`} disabled={optionIndex === 0} onClick={() => moveOption(questionIndex, optionIndex, -1)} className="rounded border px-2 py-1 text-xs disabled:opacity-40">↑</button>
                              <button type="button" aria-label={`Move option ${optionIndex + 1} down`} disabled={optionIndex === question.options.length - 1} onClick={() => moveOption(questionIndex, optionIndex, 1)} className="rounded border px-2 py-1 text-xs disabled:opacity-40">↓</button>
                              <button type="button" aria-label={`Remove option ${optionIndex + 1}`} onClick={() => updateQuestion(questionIndex, { options: question.options.filter((_, index) => index !== optionIndex) })} className="rounded border px-2 py-1 text-xs">×</button>
                            </>}
                          </div>
                        ))}
                      </div>
                      {question.question_type !== "true_false" && <button type="button" onClick={() => updateQuestion(questionIndex, { options: [...question.options, { option_text: "", is_correct: false }] })} className="mt-2 text-xs font-bold text-teal-800">+ Add option</button>}
                      {readError(`questions.${questionIndex}.options`) && <p role="alert" className="mt-1 text-xs text-rose-700">{readError(`questions.${questionIndex}.options`)}</p>}
                      {question.options.map((_, optionIndex) => readError(`questions.${questionIndex}.options.${optionIndex}.option_text`) && <p key={optionIndex} role="alert" className="text-xs text-rose-700">Option {optionIndex + 1}: {readError(`questions.${questionIndex}.options.${optionIndex}.option_text`)}</p>)}
                    </div>
                  )}

                  <label className="mt-3 block text-xs font-semibold text-slate-700">
                    Explanation (shown only when answer review is allowed)
                    <textarea value={question.explanation ?? ""} onChange={(event) => updateQuestion(questionIndex, { explanation: event.target.value })} rows={2} className="mt-1 w-full rounded-lg border border-slate-300 p-2" />
                  </label>
                  <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                    <span className="text-[10px] text-slate-500">{question.question_type.replace("_", " ")} · {question.points} points · {question.id ? `ID ${question.id}` : "new"}</span>
                    <div className="flex gap-2">
                      <button type="button" disabled={questionIndex === 0} onClick={() => moveQuestion(questionIndex, -1)} className="rounded border px-2 py-1 text-xs disabled:opacity-40">Move up</button>
                      <button type="button" disabled={questionIndex === draft.questions.length - 1} onClick={() => moveQuestion(questionIndex, 1)} className="rounded border px-2 py-1 text-xs disabled:opacity-40">Move down</button>
                      <button type="button" onClick={() => duplicateQuestion(questionIndex)} className="rounded border px-2 py-1 text-xs">Duplicate</button>
                      <button type="button" onClick={() => updateDraft((current) => ({ ...current, questions: current.questions.filter((_, index) => index !== questionIndex) }))} className="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700">Delete</button>
                    </div>
                  </div>
                </fieldset>
              ))}
            </fieldset>

            <div className="flex flex-wrap justify-between gap-2 border-t border-slate-100 pt-3">
              <div className="flex flex-wrap gap-2">
                {editable && <button type="button" disabled={isBusy} onClick={() => void handleDeleteDraft()} className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 disabled:opacity-50">Delete draft</button>}
                <button type="button" disabled={!draft.questions.length} onClick={() => { setPreviewAnswers({}); setIsPreviewOpen(true); }} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-50">Preview</button>
              </div>
              <div className="flex flex-wrap gap-2">
                {editable && <button type="button" disabled={isBusy} onClick={() => void saveDraft()} className="rounded-lg border border-teal-300 px-3 py-2 text-xs font-bold text-teal-900 disabled:opacity-50">{isBusy ? "Saving…" : "Save Draft"}</button>}
                {editable && <button type="button" disabled={isBusy} onClick={() => void publish()} className="rounded-lg bg-[#0e8988] px-3 py-2 text-xs font-bold text-white disabled:opacity-50">Publish</button>}
                {draft.status === "published" && <button type="button" disabled={isBusy} onClick={() => void onInsert(draft.id)} className="rounded-lg bg-[#0e8988] px-3 py-2 text-xs font-bold text-white disabled:opacity-50">Insert into lesson</button>}
              </div>
            </div>
          </div>
        )}
      </div>

      {isPreviewOpen && draft && (
        <div role="dialog" aria-modal="true" aria-labelledby="exercise-preview-title" onKeyDown={(event) => { if (event.key === "Escape") setIsPreviewOpen(false); }} className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3 sm:p-6">
          <section className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
            <div className="flex items-start justify-between gap-4 border-b pb-3">
              <div>
                <p className="text-[10px] font-bold uppercase tracking-wider text-teal-800">Student preview · no attempt is created</p>
                <h3 id="exercise-preview-title" className="mt-1 text-lg font-black text-[#102b4c]">{draft.title}</h3>
                {draft.instructions && <p className="mt-1 text-xs text-slate-600">{draft.instructions}</p>}
              </div>
              <button type="button" autoFocus onClick={() => setIsPreviewOpen(false)} className="rounded-lg border px-3 py-2 text-xs font-bold">Close</button>
            </div>
            <div className="mt-4 space-y-4">
              {draft.questions.map((question, questionIndex) => (
                <fieldset key={question.id ?? `preview-${questionIndex}`} className="rounded-xl border border-slate-200 p-4">
                  <legend className="px-1 text-xs font-bold text-slate-700">Question {questionIndex + 1} · {question.points} points</legend>
                  {question.question_type === "fill_in_the_blanks" ? (
                    <div className="mb-3 text-sm font-semibold leading-relaxed text-[#102b4c]">
                      {(question.prompt || "Question prompt").split(/(\[[a-zA-Z0-9_\-]+\])/).map((part, partIdx) => {
                        const match = /^\[([a-zA-Z0-9_\-]+)\]$/.exec(part);
                        if (!match) return <span key={partIdx}>{part}</span>;
                        const blankId = match[1];
                        const currentMap = (typeof previewAnswers[questionIndex] === "object" && previewAnswers[questionIndex] !== null && !Array.isArray(previewAnswers[questionIndex]))
                          ? (previewAnswers[questionIndex] as Record<string, string>)
                          : {};
                        return (
                          <input
                            key={partIdx}
                            type="text"
                            aria-label={`Blank ${blankId}`}
                            placeholder={blankId}
                            value={currentMap[blankId] ?? ""}
                            onChange={(event) =>
                              setPreviewAnswers((current) => ({
                                ...current,
                                [questionIndex]: {
                                  ...currentMap,
                                  [blankId]: event.target.value,
                                },
                              }))
                            }
                            className="mx-1 inline-block w-32 rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-normal text-slate-900"
                          />
                        );
                      })}
                    </div>
                  ) : (
                    <p className="mb-3 text-sm font-semibold text-[#102b4c]">{question.prompt || "Question prompt"}</p>
                  )}
                  {question.question_type === "fill_in_the_blanks" ? null : question.question_type === "short_answer" ? (
                    <label className="block text-xs text-slate-600">Your answer<input type="text" value={typeof previewAnswers[questionIndex] === "string" ? previewAnswers[questionIndex] as string : ""} onChange={(event) => setPreviewAnswers((current) => ({ ...current, [questionIndex]: event.target.value }))} className="mt-1 w-full rounded-lg border border-slate-300 p-2" /></label>
                  ) : (
                    <div role={question.question_type === "multiple_select" ? "group" : "radiogroup"} aria-label={`Question ${questionIndex + 1} answers`} className="space-y-2">
                      {question.options.map((option, optionIndex) => {
                        const value = previewAnswers[questionIndex];
                        const checked = question.question_type === "multiple_select"
                          ? Array.isArray(value) && value.includes(optionIndex)
                          : value === optionIndex;
                        return (
                          <label key={option.id ?? optionIndex} className="flex items-center gap-2 rounded-lg border p-2 text-xs">
                            <input
                              type={question.question_type === "multiple_select" ? "checkbox" : "radio"}
                              name={`preview-${questionIndex}`}
                              checked={checked}
                              onChange={() => setPreviewAnswers((current) => ({
                                ...current,
                                [questionIndex]: question.question_type === "multiple_select"
                                  ? [...(Array.isArray(current[questionIndex]) ? current[questionIndex] as number[] : []).filter((index) => index !== optionIndex), ...(checked ? [] : [optionIndex])]
                                  : optionIndex,
                              }))}
                            />
                            {option.option_text || `Option ${optionIndex + 1}`}
                          </label>
                        );
                      })}
                    </div>
                  )}
                  {question.explanation && <p className="mt-3 rounded-lg bg-indigo-50 p-3 text-xs text-indigo-950">{question.explanation}</p>}
                </fieldset>
              ))}
            </div>
          </section>
        </div>
      )}
    </section>
  );
}
