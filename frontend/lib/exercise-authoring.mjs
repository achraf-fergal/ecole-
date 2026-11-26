export const DEFAULT_SHORT_ANSWER_NORMALIZATION = Object.freeze({
  case_sensitive: false,
  trim_whitespace: true,
  collapse_internal_whitespace: true,
  punctuation_sensitive: true,
  diacritic_sensitive: true,
});

let clientIdCounter = 0;

/** @param {string} [prefix] */
export function createClientId(prefix = "cid") {
  clientIdCounter += 1;
  if (typeof globalThis !== "undefined" && globalThis.crypto && typeof globalThis.crypto.randomUUID === "function") {
    return `${prefix}_${globalThis.crypto.randomUUID()}`;
  }
  return `${prefix}_${Date.now().toString(36)}_${clientIdCounter}`;
}

/** @param {"single_choice"|"true_false"|"multiple_select"|"short_answer"|"fill_in_the_blanks"} [type] */
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

/**
 * Convert a prompt text selection (or cursor position) into a FITB `[blank_id]` placeholder and blank definition.
 * @param {string} prompt
 * @param {number} selectionStart
 * @param {number} selectionEnd
 * @param {Array<{ id?: string }>} existingBlanks
 */
export function convertPromptSelectionToBlank(prompt, selectionStart, selectionEnd, existingBlanks = []) {
  const source = String(prompt ?? "");
  const start = Math.max(0, Math.min(selectionStart ?? source.length, source.length));
  const end = Math.max(start, Math.min(selectionEnd ?? start, source.length));
  const selectedText = source.slice(start, end).trim();
  const blankId = generateUniqueBlankId(existingBlanks, source);
  const placeholder = `[${blankId}]`;
  const nextPrompt = source.slice(0, start) + placeholder + source.slice(end);
  const blank = {
    id: blankId,
    points: 1,
    accepted_answers: [selectedText || ""],
    normalization: { ...DEFAULT_SHORT_ANSWER_NORMALIZATION },
  };
  return {
    prompt: nextPrompt,
    blank,
    cursorPosition: start + placeholder.length,
  };
}

/**
 * Synchronize a blank ID rename inside the prompt text.
 * @param {string} prompt
 * @param {string} oldId
 * @param {string} newId
 */
export function syncBlankIdRenameInPrompt(prompt, oldId, newId) {
  const source = String(prompt ?? "");
  if (!oldId || !newId || oldId === newId) return source;
  return source.split(`[${oldId}]`).join(`[${newId}]`);
}

/**
 * Remove a deleted blank's `[blank_id]` placeholder from the prompt text.
 * @param {string} prompt
 * @param {string} blankId
 * @param {string} [fallbackText]
 */
export function removeBlankFromPrompt(prompt, blankId, fallbackText = "") {
  const source = String(prompt ?? "");
  if (!blankId) return source;
  return source.split(`[${blankId}]`).join(fallbackText);
}

/**
 * Validate a single question draft for publication readiness.
 * @param {any} question
 * @param {number} questionIndex
 * @returns {Record<string, string>}
 */
export function validateQuestionDraft(question, questionIndex) {
  /** @type {Record<string, string>} */
  const errors = {};
  const prefix = `questions.${questionIndex}`;

  if (!String(question?.prompt ?? "").trim()) {
    errors[`${prefix}.prompt`] = "Question prompt is required.";
  }

  if (question?.question_type === "fill_in_the_blanks") {
    const blanks = Array.isArray(question?.answer_config?.blanks) ? question.answer_config.blanks : [];
    if (blanks.length === 0) {
      errors[`${prefix}.answer_config.blanks`] = "Add at least one blank.";
    } else {
      const seenIds = new Set();
      blanks.forEach((/** @type {any} */ blank, /** @type {number} */ blankIndex) => {
        const bPrefix = `${prefix}.answer_config.blanks.${blankIndex}`;
        const id = typeof blank?.id === "string" ? blank.id.trim() : "";
        if (!id) {
          errors[`${bPrefix}.id`] = "Blank ID is required.";
        } else if (!/^[a-zA-Z0-9_\-]+$/.test(id)) {
          errors[`${bPrefix}.id`] = "Blank ID must contain only alphanumeric characters, underscores, and hyphens.";
        } else if (seenIds.has(id)) {
          errors[`${bPrefix}.id`] = `Blank ID '${id}' is duplicated.`;
        } else {
          seenIds.add(id);
        }

        if (blank?.points !== undefined && (!Number.isFinite(Number(blank.points)) || Number(blank.points) <= 0)) {
          errors[`${bPrefix}.points`] = "Points must be greater than zero.";
        }

        const accepted = Array.isArray(blank?.accepted_answers) ? blank.accepted_answers : [];
        if (!accepted.some((/** @type {any} */ a) => typeof a === "string" && a.trim() !== "")) {
          errors[`${bPrefix}.accepted_answers`] = "Add at least one accepted answer.";
        }
        if (accepted.length > 20) {
          errors[`${bPrefix}.accepted_answers`] = "A maximum of 20 accepted answers is allowed per blank.";
        }
        const duplicateAccepted = findDuplicateAcceptedAnswerIndexes(accepted, blank?.normalization ?? {});
        if (duplicateAccepted.length > 0) {
          errors[`${bPrefix}.accepted_answers`] = `Accepted answer ${duplicateAccepted[0] + 1} duplicates an earlier answer.`;
        }
        accepted.forEach((/** @type {any} */ ans, /** @type {number} */ aIdx) => {
          if (typeof ans === "string" && ans.length > 500) {
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
    return errors;
  }

  if (!Number.isFinite(Number(question?.points)) || Number(question.points) <= 0) {
    errors[`${prefix}.points`] = "Points must be greater than zero.";
  }

  if (question?.question_type === "short_answer") {
    const accepted = Array.isArray(question?.answer_config?.accepted_answers)
      ? question.answer_config.accepted_answers
      : [];
    const normalization = question?.answer_config?.normalization ?? {};
    if (!accepted.some((/** @type {any} */ answer) => typeof answer === "string" && answer.trim() !== "")) {
      errors[`${prefix}.answer_config.accepted_answers`] = "Add at least one accepted answer.";
    }
    const duplicateAccepted = findDuplicateAcceptedAnswerIndexes(accepted, normalization);
    if (duplicateAccepted.length > 0) {
      errors[`${prefix}.answer_config.accepted_answers`] = `Accepted answer ${duplicateAccepted[0] + 1} duplicates an earlier answer after normalization.`;
    }
    accepted.forEach((/** @type {any} */ answer, /** @type {number} */ answerIndex) => {
      if (typeof answer === "string" && answer.length > 500) {
        errors[`${prefix}.answer_config.accepted_answers.${answerIndex}`] = "Accepted answers may not exceed 500 characters.";
      }
    });
    if (accepted.length > 20) {
      errors[`${prefix}.answer_config.accepted_answers`] = "A maximum of 20 accepted answers is allowed.";
    }
    return errors;
  }

  const options = Array.isArray(question?.options) ? question.options : [];
  options.forEach((/** @type {any} */ option, /** @type {number} */ optionIndex) => {
    if (!String(option?.option_text ?? "").trim()) {
      errors[`${prefix}.options.${optionIndex}.option_text`] = "Option text is required before publishing.";
    }
  });

  const duplicateOptions = findDuplicateOptionIndexes(
    options.map((/** @type {any} */ option) => String(option?.option_text ?? ""))
  );
  if (duplicateOptions.length > 0) {
    errors[`${prefix}.options.${duplicateOptions[0]}.option_text`] = "This option duplicates another option in this question.";
  }

  const correctCount = options.filter((/** @type {any} */ option) => Boolean(option?.is_correct)).length;
  if (question?.question_type === "single_choice" && (options.length < 2 || correctCount !== 1)) {
    errors[`${prefix}.options`] = "Add at least two options and mark exactly one correct answer.";
  } else if (question?.question_type === "true_false" && (options.length !== 2 || correctCount !== 1)) {
    errors[`${prefix}.options`] = "True / False must have exactly two options and one correct answer.";
  } else if (
    question?.question_type === "multiple_select"
    && (options.length < 3 || correctCount < 2 || correctCount >= options.length)
  ) {
    errors[`${prefix}.options`] = "Multiple select needs at least three options, two correct answers, and one incorrect answer.";
  }

  return errors;
}

/**
 * Centralized validation for an entire exercise draft before publishing.
 * @param {any} draft
 */
export function validateExerciseDraft(draft) {
  /** @type {Record<string, string>} */
  const errors = {};
  /** @type {Record<number, Record<string, string>>} */
  const questionErrors = {};
  /** @type {Array<{ index: number, count: number }>} */
  const incompleteQuestions = [];

  if (!String(draft?.title ?? "").trim()) {
    errors.title = "An exercise title is required.";
  }
  const passingScore = Number(draft?.passing_score);
  if (!Number.isFinite(passingScore) || passingScore < 0 || passingScore > 100) {
    errors.passing_score = "Passing score must be between 0 and 100.";
  }
  if (draft?.max_attempts !== null && draft?.max_attempts !== undefined && draft?.max_attempts !== "") {
    const maxAttempts = Number(draft.max_attempts);
    if (!Number.isInteger(maxAttempts) || maxAttempts < 1) {
      errors.max_attempts = "Maximum attempts must be at least 1 or unlimited.";
    }
  }

  const questions = Array.isArray(draft?.questions) ? draft.questions : [];
  if (questions.length === 0) {
    errors.questions = "Add at least one question before publishing.";
  }

  /** @type {number | null} */
  let firstInvalidQuestionIndex = null;

  questions.forEach((/** @type {any} */ question, /** @type {number} */ index) => {
    const qErrors = validateQuestionDraft(question, index);
    const keys = Object.keys(qErrors);
    if (keys.length > 0) {
      questionErrors[index] = qErrors;
      incompleteQuestions.push({ index, count: keys.length });
      if (firstInvalidQuestionIndex === null) {
        firstInvalidQuestionIndex = index;
      }
      Object.assign(errors, qErrors);
    }
  });

  return {
    valid: Object.keys(errors).length === 0,
    errors,
    questionErrors,
    firstInvalidQuestionIndex,
    incompleteQuestions,
  };
}

/**
 * Find the first question index referenced by a field error map.
 * @param {Record<string, string>} errors
 * @returns {number | null}
 */
export function extractFirstErrorQuestionIndex(errors) {
  let minIndex = null;
  for (const key of Object.keys(errors ?? {})) {
    const match = /^questions\.(\d+)\./.exec(key);
    if (match) {
      const idx = Number(match[1]);
      if (minIndex === null || idx < minIndex) {
        minIndex = idx;
      }
    }
  }
  return minIndex;
}
