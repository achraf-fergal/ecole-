export const DEFAULT_SHORT_ANSWER_NORMALIZATION = Object.freeze({
  case_sensitive: false,
  trim_whitespace: true,
  collapse_internal_whitespace: true,
  punctuation_sensitive: true,
  diacritic_sensitive: true,
});

/** @param {string} type */
export function createQuestionDraft(type = "single_choice") {
  const question = {
    prompt: "",
    points: 1,
    question_type: type,
    explanation: "",
    options: [],
  };

  if (type === "short_answer") {
    return {
      ...question,
      answer_config: {
        accepted_answers: [""],
        normalization: { ...DEFAULT_SHORT_ANSWER_NORMALIZATION },
      },
    };
  }

  if (type === "fill_in_the_blanks") {
    return {
      ...question,
      prompt: "The capital of France is [blank_1].",
      points: 1,
      answer_config: {
        blanks: [
          {
            id: "blank_1",
            points: 1,
            accepted_answers: [""],
            normalization: { ...DEFAULT_SHORT_ANSWER_NORMALIZATION },
          },
        ],
      },
    };
  }

  if (type === "true_false") {
    return {
      ...question,
      options: [
        { option_text: "True", is_correct: true },
        { option_text: "False", is_correct: false },
      ],
    };
  }

  if (type === "multiple_select") {
    return {
      ...question,
      options: [
        { option_text: "", is_correct: true },
        { option_text: "", is_correct: true },
        { option_text: "", is_correct: false },
      ],
    };
  }

  return {
    ...question,
    options: [
      { option_text: "", is_correct: true },
      { option_text: "", is_correct: false },
    ],
  };
}

/**
 * Apply the short-answer rules used by the editor preview.
 *
 * @param {string} value
 * @param {Partial<{case_sensitive: boolean, trim_whitespace: boolean, collapse_internal_whitespace: boolean, punctuation_sensitive: boolean, diacritic_sensitive: boolean}>} settings
 */
export function normalizeShortAnswer(value, settings = {}) {
  const rules = { ...DEFAULT_SHORT_ANSWER_NORMALIZATION, ...settings };
  let normalized = value.normalize("NFC");

  if (rules.trim_whitespace) normalized = normalized.trim();
  if (rules.collapse_internal_whitespace) normalized = normalized.replace(/[\p{Z}\s]+/gu, " ");
  if (!rules.case_sensitive) normalized = normalized.toLowerCase();
  if (!rules.punctuation_sensitive) normalized = normalized.replace(/\p{P}+/gu, "");
  if (!rules.diacritic_sensitive) {
    normalized = normalized.normalize("NFD").replace(/\p{Mn}+/gu, "");
  }

  return normalized.normalize("NFC");
}

/** @param {string} value */
export function normalizeOptionLabel(value) {
  return value.normalize("NFC").trim().replace(/[\p{Z}\s]+/gu, " ");
}

/** @param {string[]} values @param {(value: string) => string} normalize */
function duplicateIndexes(values, normalize) {
  const firstByNormalizedValue = new Map();
  const duplicates = new Set();

  values.forEach((value, index) => {
    if (typeof value !== "string" || value.trim() === "") return;
    const key = normalize(value);
    if (firstByNormalizedValue.has(key)) duplicates.add(index);
    else firstByNormalizedValue.set(key, index);
  });

  return [...duplicates];
}

/** @param {string[]} values */
export function findDuplicateOptionIndexes(values) {
  return duplicateIndexes(values, normalizeOptionLabel);
}

/**
 * @param {string[]} values
 * @param {Partial<{case_sensitive: boolean, trim_whitespace: boolean, collapse_internal_whitespace: boolean, punctuation_sensitive: boolean, diacritic_sensitive: boolean}>} settings
 */
export function findDuplicateAcceptedAnswerIndexes(values, settings = {}) {
  return duplicateIndexes(values, (value) => normalizeShortAnswer(value, settings));
}

/** @param {"single_choice"|"true_false"|"multiple_select"|"short_answer"|"fill_in_the_blanks"} type @param {number|string|number[]|Record<string, string>|null|undefined} value */
export function isQuestionAnswered(type, value) {
  if (type === "multiple_select") return Array.isArray(value) && value.length > 0;
  if (type === "short_answer") return typeof value === "string" && value.trim().length > 0;
  if (type === "fill_in_the_blanks") {
    if (typeof value !== "object" || value === null) return false;
    return Object.values(value).some((v) => typeof v === "string" && v.trim().length > 0);
  }
  return typeof value === "number" && Number.isFinite(value);
}

/** @param {number} questionId @param {string} type @param {number|string|number[]|Record<string, string>|null|undefined} value */
export function toAttemptAnswerPayload(questionId, type, value) {
  if (type === "multiple_select") {
    return { question_id: questionId, selected_option_ids: Array.isArray(value) ? value : [] };
  }
  if (type === "short_answer") {
    return { question_id: questionId, text_answer: typeof value === "string" ? value : null };
  }
  if (type === "fill_in_the_blanks") {
    return { question_id: questionId, blank_answers: typeof value === "object" && value !== null && !Array.isArray(value) ? value : {} };
  }
  return {
    question_id: questionId,
    selected_option_id: typeof value === "number" ? value : null,
  };
}
