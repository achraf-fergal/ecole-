import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import {
  areAllBlanksAnswered,
  convertPromptSelectionToBlank,
  createQuestionDraft,
  extractFirstErrorQuestionIndex,
  findDuplicateAcceptedAnswerIndexes,
  findDuplicateOptionIndexes,
  generateUniqueBlankId,
  getBlankCompletionState,
  isQuestionAnswered,
  normalizeOptionLabel,
  normalizeShortAnswer,
  removeBlankFromPrompt,
  sumBlankPoints,
  sumExercisePoints,
  syncBlankIdRenameInPrompt,
  toAttemptAnswerPayload,
  validateExerciseDraft,
  validateFitbPromptPlaceholders,
} from "../lib/exercise-authoring.mjs";

const normalizationFixtures = JSON.parse(
  fs.readFileSync(new URL("../../backend/tests/fixtures/exercise-normalization.json", import.meta.url), "utf8")
);

test("creates an editor shape for each supported question type", () => {
  assert.equal(createQuestionDraft("single_choice").options.length, 2);
  assert.equal(createQuestionDraft("true_false").options.length, 2);
  assert.equal(createQuestionDraft("multiple_select").options.filter((row) => row.is_correct).length, 2);
  assert.deepEqual(createQuestionDraft("short_answer").answer_config.accepted_answers, [""]);
});

test("option duplicate checks normalize Unicode and whitespace without folding case or accents", () => {
  assert.deepEqual(findDuplicateOptionIndexes([" café ", "cafe\u0301", "cafe", "Café"]), [1]);
  assert.deepEqual(findDuplicateOptionIndexes(["New   York", "New York"]), [1]);
});

test("short-answer matching applies configured case, whitespace, punctuation, and diacritic rules", () => {
  const defaults = {
    case_sensitive: false,
    trim_whitespace: true,
    collapse_internal_whitespace: true,
    punctuation_sensitive: true,
    diacritic_sensitive: true,
  };
  assert.equal(normalizeShortAnswer("  I'M\tready ", defaults), "i'm ready");
  assert.notEqual(normalizeShortAnswer("I am.", defaults), normalizeShortAnswer("I am", defaults));
  assert.notEqual(normalizeShortAnswer("cafe", defaults), normalizeShortAnswer("café", defaults));
  assert.equal(normalizeShortAnswer("cafe", { ...defaults, diacritic_sensitive: false }), normalizeShortAnswer("café", { ...defaults, diacritic_sensitive: false }));
  assert.deepEqual(findDuplicateAcceptedAnswerIndexes(["I am", "i   am"], defaults), [1]);
});

test("JavaScript normalization matches shared cross-runtime Unicode fixtures", () => {
  for (const fixture of normalizationFixtures) {
    const actual = fixture.type === "option"
      ? normalizeOptionLabel(fixture.input)
      : normalizeShortAnswer(fixture.input, fixture.settings);
    assert.equal(actual, fixture.expected, fixture.name);
  }
});

test("attempt answer helpers use the type-specific API field and answered state", () => {
  assert.deepEqual(toAttemptAnswerPayload(1, "single_choice", 7), { question_id: 1, selected_option_id: 7 });
  assert.deepEqual(toAttemptAnswerPayload(2, "multiple_select", [5, 3]), { question_id: 2, selected_option_ids: [5, 3] });
  assert.deepEqual(toAttemptAnswerPayload(3, "short_answer", "hello"), { question_id: 3, text_answer: "hello" });
  assert.equal(isQuestionAnswered("multiple_select", []), false);
  assert.equal(isQuestionAnswered("multiple_select", [5]), true);
  assert.equal(isQuestionAnswered("short_answer", "  "), false);
  assert.equal(isQuestionAnswered("true_false", 0), true);
});

test("fill_in_the_blanks draft creation, completion state, payload, unique IDs, and placeholder validation", () => {
  const draft = createQuestionDraft("fill_in_the_blanks");
  assert.equal(draft.question_type, "fill_in_the_blanks");
  assert.equal(draft.prompt, "The capital of France is [blank_1].");
  assert.equal(draft.points, 1);
  assert.equal(draft.answer_config.blanks.length, 1);
  assert.equal(draft.answer_config.blanks[0].id, "blank_1");
  assert.equal(draft.answer_config.blanks[0].normalization.case_sensitive, false);

  // Completion state
  const blankIds = ["blank_1", "blank_2", "blank_3"];
  assert.equal(areAllBlanksAnswered([], { blank_1: "a" }), false);
  assert.equal(areAllBlanksAnswered(blankIds, {}), false);
  assert.equal(areAllBlanksAnswered(blankIds, { blank_1: "a" }), false);
  assert.equal(areAllBlanksAnswered(blankIds, { blank_1: "a", blank_2: "   ", blank_3: "c" }), false);
  assert.equal(areAllBlanksAnswered(blankIds, { blank_1: "a", blank_2: "b", blank_3: "c" }), true);

  assert.equal(getBlankCompletionState(blankIds, {}), "unanswered");
  assert.equal(getBlankCompletionState(blankIds, { blank_1: "a" }), "partial");
  assert.equal(getBlankCompletionState(blankIds, { blank_1: "a", blank_2: "b", blank_3: "c" }), "answered");
  assert.equal(isQuestionAnswered("fill_in_the_blanks", { blank_1: "a" }, blankIds), false);
  assert.equal(isQuestionAnswered("fill_in_the_blanks", { blank_1: "a", blank_2: "b", blank_3: "c" }, blankIds), true);

  // Payload
  assert.deepEqual(
    toAttemptAnswerPayload(10, "fill_in_the_blanks", { blank_1: "is", blank_2: "going" }),
    { question_id: 10, blank_answers: { blank_1: "is", blank_2: "going" } }
  );

  // Unique blank ID generation after deletion
  const blanksAfterDelete = [{ id: "blank_2" }, { id: "blank_3" }];
  const generated = generateUniqueBlankId(blanksAfterDelete, "I [blank_2] and [blank_3] and [blank_1]");
  assert.equal(generated, "blank_4");

  // Sum blank points
  assert.equal(sumBlankPoints([{ points: 0.5 }, { points: 1 }, { points: 1.5 }]), 3);
  assert.equal(sumExercisePoints([
    { question_type: "single_choice", points: 1.25 },
    { question_type: "fill_in_the_blanks", answer_config: { blanks: [{ points: 0.5 }, { points: 1.25 }] } },
  ]), 3);

  // Prompt placeholder validation
  assert.equal(
    validateFitbPromptPlaceholders("I [blank_1] to [blank_2].", [{ id: "blank_1" }, { id: "blank_2" }]).valid,
    true
  );
  assert.equal(
    validateFitbPromptPlaceholders("I [blank_3].", [{ id: "blank_1" }]).valid,
    false
  );
  assert.equal(
    validateFitbPromptPlaceholders("I [blank_1].", [{ id: "blank_1" }, { id: "blank_2" }]).valid,
    false
  );
  assert.equal(
    validateFitbPromptPlaceholders("[blank_1] and [blank_1].", [{ id: "blank_1" }]).valid,
    false
  );
});

test("exercise question points must match the configured final total", () => {
  const question = createQuestionDraft("true_false");
  question.prompt = "The statement is true.";
  question.points = 3;
  const draft = {
    title: "Points total",
    total_points: 2,
    passing_score: 70,
    max_attempts: null,
    questions: [question],
  };

  assert.match(validateExerciseDraft(draft).errors.total_points, /exceeds the exercise total/);
  draft.total_points = 4;
  assert.match(validateExerciseDraft(draft).errors.total_points, /must equal the exercise total/);
  draft.total_points = 3;
  assert.equal(validateExerciseDraft(draft).errors.total_points, undefined);
});

test("FITB inline blank conversion, rename sync, delete sync, and centralized draft validation", () => {
  const converted = convertPromptSelectionToBlank("I love Paris very much.", 7, 12, []);
  assert.equal(converted.prompt, "I love [blank_1] very much.");
  assert.equal(converted.blank.id, "blank_1");
  assert.deepEqual(converted.blank.accepted_answers, ["Paris"]);

  const renamed = syncBlankIdRenameInPrompt(converted.prompt, "blank_1", "city_1");
  assert.equal(renamed, "I love [city_1] very much.");

  const removed = removeBlankFromPrompt(renamed, "city_1", "Paris");
  assert.equal(removed, "I love Paris very much.");

  const invalidDraft = {
    title: "Test Quiz",
    passing_score: 70,
    max_attempts: null,
    questions: [
      createQuestionDraft("true_false"),
      createQuestionDraft("short_answer"),
    ],
  };
  invalidDraft.questions[0].prompt = "The sky is blue.";
  // questions[1] has empty prompt and empty accepted answer
  const validation = validateExerciseDraft(invalidDraft);
  assert.equal(validation.valid, false);
  assert.equal(validation.firstInvalidQuestionIndex, 1);
  assert.equal(extractFirstErrorQuestionIndex(validation.errors), 1);
});
