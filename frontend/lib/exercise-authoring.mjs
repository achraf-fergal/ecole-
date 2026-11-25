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

/**
 * Extract placeholder IDs and any invalid `[...]` tokens from a FITB prompt.
 * @param {string} prompt
 * @returns {{ ids: string[], counts: Record<string, number>, invalidTokens: string[] }}
 */
export function extractPromptPlaceholders(prompt) {
  const ids = [];
  /** @type {Record<string, number>} */
  const counts = {};
  /** @type {string[]} */
  const invalidTokens = [];
  const matches = String(prompt ?? "").matchAll(/\[([^\[\]]*)\]/g);
  for (const match of matches) {
    const token = match[1] ?? "";
    if (!/^[a-zA-Z0-9_\-]+$/.test(token)) {
      invalidTokens.push(token);
      continue;
    }
    ids.push(token);
    counts[token] = (counts[token] ?? 0) + 1;
  }
  return { ids, counts, invalidTokens };
}

/**
 * Validate 1-to-1 mapping between prompt `[blank_id]` placeholders and blank definitions.
 * @param {string} prompt
 * @param {Array<{ id?: string }>} blanks
 * @returns {{ valid: boolean, promptErrors: string[], blanksErrors: string[] }}
 */
export function validateFitbPromptPlaceholders(prompt, blanks) {
  /** @type {string[]} */
  const promptErrors = [];
  /** @type {string[]} */
  const blanksErrors = [];
  const definedIds = (Array.isArray(blanks) ? blanks : [])
    .map((b) => (typeof b?.id === "string" ? b.id.trim() : ""))
    .filter((id) => id !== "");

  const { counts, invalidTokens } = extractPromptPlaceholders(prompt);

  for (const invalid of invalidTokens) {
    promptErrors.push(
      `Invalid placeholder syntax '[${invalid}]'. Use alphanumeric characters, underscores, or hyphens.`
    );
  }

  for (const [id, count] of Object.entries(counts)) {
    if (count > 1) {
      promptErrors.push(
        `Placeholder '[${id}]' appears ${count} times in the prompt. Each placeholder may appear only once.`
      );
    }
    if (!definedIds.includes(id)) {
      promptErrors.push(`Placeholder '[${id}]' has no matching blank definition.`);
    }
  }

  for (const definedId of definedIds) {
    if (!counts[definedId]) {
      blanksErrors.push(
        `Blank '${definedId}' is defined but not referenced as '[${definedId}]' in the prompt.`
      );
    }
  }

  return {
    valid: promptErrors.length === 0 && blanksErrors.length === 0,
    promptErrors,
    blanksErrors,
  };
}

/**
 * Generate a collision-free blank ID considering existing blanks and prompt placeholders.
 * @param {Array<{ id?: string }>} existingBlanks
 * @param {string} [prompt]
 * @returns {string}
 */
export function generateUniqueBlankId(existingBlanks, prompt = "") {
  const used = new Set(
    (Array.isArray(existingBlanks) ? existingBlanks : [])
      .map((b) => (typeof b?.id === "string" ? b.id.trim() : ""))
      .filter(Boolean)
  );
  for (const id of extractPromptPlaceholders(prompt).ids) {
    used.add(id);
  }
  let counter = 1;
  while (used.has(`blank_${counter}`)) {
    counter += 1;
  }
  return `blank_${counter}`;
}

/**
 * Sum blank points in integer minor units (hundredths) and return a 2-decimal number.
 * @param {Array<{ points?: number | string }>} blanks
 * @returns {number}
 */
export function sumBlankPoints(blanks) {
  if (!Array.isArray(blanks)) return 0;
  let totalMinorUnits = 0;
  for (const blank of blanks) {
    const num = Number(blank?.points);
    if (Number.isFinite(num) && num > 0) {
      totalMinorUnits += Math.round(num * 100);
    }
  }
  return totalMinorUnits / 100;
}

/**
 * Check whether every blank ID has a non-whitespace student answer.
 * @param {string[]} blankIds
 * @param {Record<string, string>|number|string|number[]|null|undefined} studentAnswers
 * @returns {boolean}
 */
export function areAllBlanksAnswered(blankIds, studentAnswers) {
  if (!Array.isArray(blankIds) || blankIds.length === 0) return false;
  if (typeof studentAnswers !== "object" || studentAnswers === null || Array.isArray(studentAnswers)) {
    return false;
  }
  return blankIds.every((id) => {
    const val = studentAnswers[id];
    return typeof val === "string" && val.trim().length > 0;
  });
}

/**
 * Return completion state for a Fill in the Blanks question.
 * @param {string[]} blankIds
 * @param {Record<string, string>|number|string|number[]|null|undefined} studentAnswers
 * @returns {"answered"|"partial"|"unanswered"|"invalid"}
 */
export function getBlankCompletionState(blankIds, studentAnswers) {
  if (!Array.isArray(blankIds) || blankIds.length === 0) return "invalid";
  if (typeof studentAnswers !== "object" || studentAnswers === null || Array.isArray(studentAnswers)) {
    return "unanswered";
  }
  const answeredCount = blankIds.filter((id) => {
    const val = studentAnswers[id];
    return typeof val === "string" && val.trim().length > 0;
  }).length;
  if (answeredCount === 0) return "unanswered";
  if (answeredCount === blankIds.length) return "answered";
  return "partial";
}

/**
 * @param {"single_choice"|"true_false"|"multiple_select"|"short_answer"|"fill_in_the_blanks"} type
 * @param {number|string|number[]|Record<string, string>|null|undefined} value
 * @param {string[]} [blankIds]
 */
export function isQuestionAnswered(type, value, blankIds) {
  if (type === "multiple_select") return Array.isArray(value) && value.length > 0;
  if (type === "short_answer") return typeof value === "string" && value.trim().length > 0;
  if (type === "fill_in_the_blanks") {
    if (Array.isArray(blankIds) && blankIds.length > 0) {
      return areAllBlanksAnswered(blankIds, value);
    }
    if (typeof value !== "object" || value === null || Array.isArray(value)) return false;
    const vals = Object.values(value);
    return vals.length > 0 && vals.every((v) => typeof v === "string" && v.trim().length > 0);
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
