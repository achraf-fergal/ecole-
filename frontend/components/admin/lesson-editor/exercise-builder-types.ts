import type {
  AdminFullExercise,
  BlankAnswerConfigPayload,
  BlankDefinitionPayload,
  CreateExerciseOptionPayload,
  CreateQuestionPayload,
  ExerciseAnswerConfigPayload,
  ExerciseResultSettingsPayload,
  ShortAnswerConfigPayload,
  ShortAnswerNormalizationPayload,
} from "@/lib/admin-learning";
import { createClientId, DEFAULT_SHORT_ANSWER_NORMALIZATION, sumBlankPoints } from "@/lib/exercise-authoring.mjs";
import type { QuestionType } from "@/lib/learning";

export type DraftOption = CreateExerciseOptionPayload & {
  clientId: string;
};

export interface DraftQuestionTypeConfiguration {
  prompt: string;
  options: DraftOption[];
  answer_config: ExerciseAnswerConfigPayload | null;
  points: number;
  acceptedAnswerClientIds?: string[];
  blankClientIds?: string[];
  blankAnswerClientIds?: Record<number, string[]>;
}

export type DraftQuestion = Omit<CreateQuestionPayload, "id" | "options"> & {
  id?: number;
  clientId: string;
  options: DraftOption[];
  acceptedAnswerClientIds?: string[];
  blankClientIds?: string[];
  blankAnswerClientIds?: Record<number, string[]>;
  typeConfigurations?: Partial<Record<QuestionType, DraftQuestionTypeConfiguration>>;
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

export function defaultResultSettings(showCorrect = false): ExerciseResultSettingsPayload {
  return {
    show_score: true,
    show_percentage: true,
    show_pass_fail: true,
    show_question_results: true,
    show_student_answers: true,
    show_correct_answers: showCorrect,
    show_explanations: showCorrect,
    show_points_per_question: true,
    release_timing: "immediately",
  };
}

export function resolveDraftResultSettings(exercise: {
  result_settings?: ExerciseResultSettingsPayload;
  show_correct_answers_after_submission: boolean;
}): ExerciseResultSettingsPayload {
  const base = defaultResultSettings(Boolean(exercise.show_correct_answers_after_submission));
  if (!exercise.result_settings) return base;
  const merged: ExerciseResultSettingsPayload = { ...base, ...exercise.result_settings };
  if (!merged.show_question_results) {
    merged.show_correct_answers = false;
    merged.show_explanations = false;
  }
  return merged;
}

export function defaultNormalization(): ShortAnswerNormalizationPayload {
  return { ...DEFAULT_SHORT_ANSWER_NORMALIZATION };
}

export function cloneAnswerConfig(
  config: ExerciseAnswerConfigPayload | null | undefined
): ExerciseAnswerConfigPayload | null {
  if (!config) return null;

  if ("blanks" in config) {
    return {
      blanks: config.blanks.map((blank) => ({
        ...blank,
        accepted_answers: [...blank.accepted_answers],
        normalization: blank.normalization ? { ...blank.normalization } : undefined,
      })),
    };
  }

  return {
    accepted_answers: [...config.accepted_answers],
    normalization: { ...config.normalization },
  };
}

export function cloneQuestionTypeConfiguration(
  config: DraftQuestionTypeConfiguration,
  preserveDatabaseIds = true
): DraftQuestionTypeConfiguration {
  return {
    prompt: config.prompt,
    options: config.options.map((option, index) => ({
      ...(preserveDatabaseIds && option.id ? { id: option.id } : {}),
      option_text: option.option_text,
      is_correct: option.is_correct,
      sort_order: option.sort_order ?? index,
      clientId: preserveDatabaseIds ? option.clientId : createClientId("opt"),
    })),
    answer_config: cloneAnswerConfig(config.answer_config),
    points: config.points,
    acceptedAnswerClientIds: config.acceptedAnswerClientIds
      ? preserveDatabaseIds
        ? [...config.acceptedAnswerClientIds]
        : config.acceptedAnswerClientIds.map(() => createClientId("ans"))
      : undefined,
    blankClientIds: config.blankClientIds
      ? preserveDatabaseIds
        ? [...config.blankClientIds]
        : config.blankClientIds.map(() => createClientId("blk"))
      : undefined,
    blankAnswerClientIds: config.blankAnswerClientIds
      ? Object.fromEntries(
          Object.entries(config.blankAnswerClientIds).map(([index, ids]) => [
            index,
            preserveDatabaseIds ? [...ids] : ids.map(() => createClientId("ans")),
          ])
        )
      : undefined,
  };
}

export function captureQuestionTypeConfiguration(
  question: DraftQuestion
): DraftQuestionTypeConfiguration {
  return {
    prompt: question.prompt,
    options: question.options.map((option, index) => ({
      ...option,
      sort_order: option.sort_order ?? index,
    })),
    answer_config: cloneAnswerConfig(question.answer_config),
    points: question.points ?? 1,
    acceptedAnswerClientIds: question.acceptedAnswerClientIds
      ? [...question.acceptedAnswerClientIds]
      : undefined,
    blankClientIds: question.blankClientIds ? [...question.blankClientIds] : undefined,
    blankAnswerClientIds: question.blankAnswerClientIds
      ? Object.fromEntries(
          Object.entries(question.blankAnswerClientIds).map(([index, ids]) => [index, [...ids]])
        )
      : undefined,
  };
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
    answer_config: cloneAnswerConfig(question.answer_config),
    explanation: question.explanation,
    points,
    sort_order: question.sort_order,
    options: question.options.map((option) => ({
      ...option,
      clientId:
        existing?.options.find((o) => o.id && o.id === option.id)?.clientId ??
        existing?.options.find(
          (o) => !o.id && o.sort_order === option.sort_order
        )?.clientId ??
        createClientId("opt"),
    })),
    acceptedAnswerClientIds,
    blankClientIds,
    blankAnswerClientIds,
    typeConfigurations: existing?.typeConfigurations
      ? Object.fromEntries(
          Object.entries(existing.typeConfigurations).map(([type, config]) => [
            type,
            config ? cloneQuestionTypeConfiguration(config) : config,
          ])
        ) as DraftQuestion["typeConfigurations"]
      : undefined,
  };
}

export function toDraftExercise(exercise: AdminFullExercise, existingDraft?: ExerciseDraft | null): ExerciseDraft {
  return {
    ...exercise,
    result_settings: resolveDraftResultSettings(exercise),
    questions: exercise.questions.map((question) => {
      const existingQuestion =
        existingDraft?.questions.find((q) => q.id && q.id === question.id) ??
        existingDraft?.questions.find(
          (q) => !q.id && q.sort_order === question.sort_order
        );
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
    options: (base.options ?? []).map((opt, sort_order) => ({
      ...opt,
      clientId: createClientId("opt"),
      sort_order,
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
