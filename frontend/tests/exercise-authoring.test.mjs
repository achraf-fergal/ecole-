import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import {
  createQuestionDraft,
  findDuplicateAcceptedAnswerIndexes,
  findDuplicateOptionIndexes,
  isQuestionAnswered,
  normalizeOptionLabel,
  normalizeShortAnswer,
  toAttemptAnswerPayload,
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
