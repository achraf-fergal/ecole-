import {
  extractPromptPlaceholders,
  getBlankCompletionState,
  isQuestionAnswered,
  toAttemptAnswerPayload,
} from "@/lib/exercise-authoring.mjs";
import type {
  AttemptQuestion,
  SaveAttemptAnswerPayload,
} from "@/lib/learning";

export type AnswerValue = number | number[] | string | Record<string, string> | null;

function getQuestionBlankIds(prompt: string): string[] {
  return extractPromptPlaceholders(prompt).ids;
}

export function getQuestionCompletionStatus(
  question: { id: number; question_type: AttemptQuestion["question_type"]; prompt: string },
  answer: AnswerValue
): "answered" | "partial" | "unanswered" {
  if (question.question_type === "fill_in_the_blanks") {
    const state = getBlankCompletionState(getQuestionBlankIds(question.prompt), answer);
    return state === "invalid" ? "unanswered" : state;
  }
  return isQuestionAnswered(question.question_type, answer) ? "answered" : "unanswered";
}

export function answersFromAttempt(questions: AttemptQuestion[]): Record<number, AnswerValue> {
  return Object.fromEntries(questions.map((question) => {
    if (question.question_type === "multiple_select") {
      return [question.id, question.selected_option_ids ?? []];
    }
    if (question.question_type === "short_answer") {
      return [question.id, question.text_answer ?? ""];
    }
    if (question.question_type === "fill_in_the_blanks") {
      if (question.blank_answers && typeof question.blank_answers === "object") {
        return [question.id, question.blank_answers];
      }
      if (question.text_answer) {
        try {
          const parsed = JSON.parse(question.text_answer);
          if (typeof parsed === "object" && parsed !== null) {
            return [question.id, parsed];
          }
        } catch {}
      }
      return [question.id, {}];
    }
    return [question.id, question.selected_option_id];
  }));
}

export function attemptAnswerPayload(
  questions: AttemptQuestion[],
  answers: Record<number, AnswerValue>
): SaveAttemptAnswerPayload[] {
  return questions.map((question) =>
    toAttemptAnswerPayload(question.id, question.question_type, answers[question.id])
  );
}

export function answerSignature(payload: SaveAttemptAnswerPayload[]): string {
  return JSON.stringify(payload.map((answer) => ({
    ...answer,
    selected_option_ids: answer.selected_option_ids
      ? [...answer.selected_option_ids].sort((left, right) => left - right)
      : answer.selected_option_ids,
    blank_answers: answer.blank_answers
      ? Object.keys(answer.blank_answers).sort().reduce((acc, key) => {
          acc[key] = (answer.blank_answers as Record<string, string>)[key];
          return acc;
        }, {} as Record<string, string>)
      : answer.blank_answers,
  })));
}
