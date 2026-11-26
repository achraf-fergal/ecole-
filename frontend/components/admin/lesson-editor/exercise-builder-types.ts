import type {
  AdminFullExercise,
  BlankAnswerConfigPayload,
  BlankDefinitionPayload,
  CreateExerciseOptionPayload,
  CreateQuestionPayload,
  ShortAnswerConfigPayload,
  ShortAnswerNormalizationPayload,
} from "@/lib/admin-learning";
import { createClientId, DEFAULT_SHORT_ANSWER_NORMALIZATION, sumBlankPoints } from "@/lib/exercise-authoring.mjs";
import type { QuestionType } from "@/lib/learning";

export type DraftOption = CreateExerciseOptionPayload & {
  clientId: string;
};

export type DraftQuestion = Omit<CreateQuestionPayload, "id" | "options"> & {
  id?: number;
  clientId: string;
  options: DraftOption[];
  acceptedAnswerClientIds?: string[];
  blankClientIds?: string[];
  blankAnswerClientIds?: Record<number, string[]>;
};

export type ExerciseDraft = Omit<AdminFullExercise, "questions"> & {
  questions: DraftQuestion[];
};

export const QUESTION_TYPE_OPTIONS: { value: QuestionType; label: string; shortLabel: string }[] = [
  { value: "single_choice", label: "Single choice", shortLabel: "Single" },
  { value: "true_false", label: "True / False", shortLabel: "T/F" },
  { value: "multiple_select", label: "Multiple select", shortLabel: "Multi" },
  { value: "short_answer", label: "Short answer", shortLabel: "Short" },
  { value: "fill_in_the_blanks", label: "Fill in the Blanks", shortLabel: "Blanks" },
];

export const NORMALIZATION_RULE_DESCRIPTIONS: {
  key: keyof ShortAnswerNormalizationPayload;
  label: string;
  description: string;
}[] = [
  {
    key: "case_sensitive",
    label: "Case sensitive",
    description: "Require uppercase and lowercase letters to match exactly.",
  },
  {
    key: "trim_whitespace",
    label: "Trim outer whitespace",
    description: "Ignore spaces at the start and end of the student's answer.",
  },
  {
    key: "collapse_internal_whitespace",
    label: "Collapse repeated spaces",
    description: "Treat multiple spaces or tabs between words as a single space.",
  },
  {
    key: "punctuation_sensitive",
    label: "Punctuation matters",
    description: "Require punctuation marks (such as periods or commas) to match.",
  },
  {
    key: "diacritic_sensitive",
    label: "Accents matter",
    description: "Require accented characters (such as café vs cafe) to match.",
  },
];

export function defaultNormalization(): ShortAnswerNormalizationPayload {
  return { ...DEFAULT_SHORT_ANSWER_NORMALIZATION };
}

export function ensureBlankClientIds(question: DraftQuestion): {
  blankClientIds: string[];
  blankAnswerClientIds: Record<number, string[]>;
} {
  const blanks = (question.answer_config as BlankAnswerConfigPayload | null)?.blanks ?? [];
  const blankClientIds = blanks.map((_, idx) => question.blankClientIds?.[idx] ?? createClientId("blk"));
  const blankAnswerClientIds: Record<number, string[]> = {};
  blanks.forEach((blank, bIdx) => {
    const existing = question.blankAnswerClientIds?.[bIdx] ?? [];
    blankAnswerClientIds[bIdx] = (blank.accepted_answers ?? []).map(
      (_, aIdx) => existing[aIdx] ?? createClientId("ans")
    );
  });
  return { blankClientIds, blankAnswerClientIds };
}

export function toDraftQuestion(
  question: AdminFullExercise["questions"][number],
  existing?: DraftQuestion
): DraftQuestion {
  const shortConfig =
    question.question_type === "short_answer"
      ? (question.answer_config as ShortAnswerConfigPayload | null)
      : null;
  const blankConfig =
    question.question_type === "fill_in_the_blanks"
      ? (question.answer_config as BlankAnswerConfigPayload | null)
      : null;

  const blankClientIds = blankConfig
    ? (blankConfig.blanks ?? []).map((_, idx) => existing?.blankClientIds?.[idx] ?? createClientId("blk"))
    : undefined;

  const blankAnswerClientIds: Record<number, string[]> | undefined = blankConfig
    ? Object.fromEntries(
        (blankConfig.blanks ?? []).map((blank, bIdx) => [
          bIdx,
          (blank.accepted_answers ?? []).map(
            (_, aIdx) => existing?.blankAnswerClientIds?.[bIdx]?.[aIdx] ?? createClientId("ans")
          ),
        ])
      )
    : undefined;

  const acceptedAnswerClientIds = shortConfig
    ? (shortConfig.accepted_answers ?? []).map(
        (_, idx) => existing?.acceptedAnswerClientIds?.[idx] ?? createClientId("ans")
      )
    : undefined;

  const points =
    question.question_type === "fill_in_the_blanks"
      ? sumBlankPoints(blankConfig?.blanks ?? [])
      : question.points;

  return {
    id: question.id,
    clientId: existing?.clientId ?? createClientId("q"),
    prompt: question.prompt,
    question_type: question.question_type,
    answer_config: question.answer_config,
    explanation: question.explanation,
    points,
    sort_order: question.sort_order,
    options: question.options.map((option, idx) => ({
      ...option,
      clientId:
        existing?.options.find((o) => o.id && o.id === option.id)?.clientId ??
        existing?.options[idx]?.clientId ??
        createClientId("opt"),
    })),
    acceptedAnswerClientIds,
    blankClientIds,
    blankAnswerClientIds,
  };
}

export function toDraftExercise(exercise: AdminFullExercise, existingDraft?: ExerciseDraft | null): ExerciseDraft {
  return {
    ...exercise,
    questions: exercise.questions.map((question, idx) => {
      const existingQuestion =
        existingDraft?.questions.find((q) => q.id && q.id === question.id) ??
        existingDraft?.questions[idx];
      return toDraftQuestion(question, existingQuestion);
    }),
  };
}

export function createClientQuestionDraft(
  type: QuestionType,
  createBase: (t: QuestionType) => Omit<CreateQuestionPayload, "id" | "options"> & { options: CreateExerciseOptionPayload[] }
): DraftQuestion {
  const base = createBase(type);
  const shortConfig = type === "short_answer" ? (base.answer_config as ShortAnswerConfigPayload | null) : null;
  const blankConfig = type === "fill_in_the_blanks" ? (base.answer_config as BlankAnswerConfigPayload | null) : null;

  return {
    ...base,
    clientId: createClientId("q"),
    options: (base.options ?? []).map((opt) => ({
      ...opt,
      clientId: createClientId("opt"),
    })),
    acceptedAnswerClientIds: shortConfig
      ? (shortConfig.accepted_answers ?? []).map(() => createClientId("ans"))
      : undefined,
    blankClientIds: blankConfig
      ? (blankConfig.blanks ?? []).map(() => createClientId("blk"))
      : undefined,
    blankAnswerClientIds: blankConfig
      ? Object.fromEntries(
          (blankConfig.blanks ?? []).map((b: BlankDefinitionPayload, bIdx: number) => [
            bIdx,
            (b.accepted_answers ?? []).map(() => createClientId("ans")),
          ])
        )
      : undefined,
  };
}
