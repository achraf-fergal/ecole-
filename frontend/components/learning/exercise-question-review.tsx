"use client";

import FillBlankRenderer from "./fill-blank-renderer";
import type {
  AttemptQuestion,
  ExerciseAttemptData,
  ResultVisibilityPolicy,
} from "@/lib/learning";
import { resolveAttemptVisibility } from "./exercise-result-visibility";

function renderFitbReviewPrompt(
  prompt: string,
  blanks: AttemptQuestion["blanks"] | undefined,
  blankAnswers: Record<string, string> | null | undefined,
  showCorrectAnswers: boolean
) {
  return (
    <FillBlankRenderer
      prompt={prompt}
      renderBlank={(blankId, _blankNumber, partIndex) => {
        const blank = (blanks ?? []).find((item) => item.id === blankId);
        const studentAnswer = blank?.student_answer ?? blankAnswers?.[blankId] ?? "";
        const isCorrect = blank?.is_correct;
        return (
          <span
            key={partIndex}
            className={`mx-1 inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs ${
              isCorrect === true
                ? "border-emerald-300 bg-emerald-50 font-bold text-emerald-900"
                : isCorrect === false
                ? "border-rose-300 bg-rose-50 font-bold text-rose-900"
                : "border-slate-300 bg-slate-50 text-slate-700"
            }`}
          >
            <span>{studentAnswer || "\u2014"}</span>
            {showCorrectAnswers && isCorrect !== undefined && (
              <span className={isCorrect ? "text-emerald-700" : "text-rose-700"}>
                {isCorrect ? "\u2713" : "\u2717"}
              </span>
            )}
          </span>
        );
      }}
    />
  );
}

export function AttemptQuestionReviewCard({
  question,
  questionIndex,
  visibility,
  compact = false,
}: {
  question: AttemptQuestion;
  questionIndex: number;
  visibility: ResultVisibilityPolicy;
  compact?: boolean;
}) {
  const showStatus = visibility.results_released && visibility.show_question_results;
  const showPoints = showStatus && visibility.show_points_per_question;
  const showStudentAnswers = visibility.results_released && visibility.show_student_answers;
  const showCorrect = showStatus && visibility.show_correct_answers;
  const showExplanation = showStatus && visibility.show_explanations;

  const resultStatus =
    question.result_status ??
    (question.is_correct === true ? "correct" : question.is_correct === false ? "incorrect" : undefined);

  return (
    <div
      className={
        compact
          ? "rounded-lg border border-slate-100 bg-white p-3.5"
          : "rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:p-5"
      }
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
          Question {questionIndex + 1}
          {showPoints && question.points_possible !== null
            ? ` (${question.points_possible} ${Number(question.points_possible) === 1 ? "point" : "points"})`
            : ""}
        </span>
        <div className="flex items-center gap-2">
          {showStatus && resultStatus && (
            <span
              className={`rounded-md px-2 py-0.5 text-[10px] font-bold ${
                resultStatus === "correct"
                  ? "bg-emerald-50 text-emerald-700"
                  : resultStatus === "unanswered"
                  ? "bg-amber-50 text-amber-800"
                  : "bg-rose-50 text-rose-700"
              }`}
            >
              {resultStatus === "correct"
                ? "✓ Correct"
                : resultStatus === "unanswered"
                ? "— Unanswered"
                : "✗ Incorrect"}
            </span>
          )}
          {showPoints && question.points_awarded !== undefined && (
            <span
              className={`text-[10px] font-bold ${
                Number(question.points_awarded) > 0 ? "text-emerald-700" : "text-slate-500"
              }`}
            >
              {Number(question.points_awarded) > 0 ? `+${question.points_awarded} pts` : "0 pts"}
            </span>
          )}
        </div>
      </div>

      {question.question_type === "fill_in_the_blanks" ? (
        <div className="mt-3 space-y-2 rounded-lg border border-slate-200 bg-white p-3 text-xs">
          <div className="font-semibold leading-relaxed text-slate-800">
            {renderFitbReviewPrompt(
              question.prompt,
              question.blanks,
              showStudentAnswers ? question.blank_answers : undefined,
              showStatus
            )}
          </div>
          {showStatus && question.blanks && question.blanks.length > 0 && (showCorrect || showPoints) && (
            <div className="mt-2 space-y-1 border-t border-slate-100 pt-2 text-[11px] text-slate-600">
              {question.blanks.map((b) => (
                <div key={b.id} className="flex flex-wrap items-center gap-2">
                  <span className="font-bold text-slate-700">[{b.id}]:</span>
                  {showCorrect && b.accepted_answers && (
                    <span>Accepted: {b.accepted_answers.join(" · ")}</span>
                  )}
                  {showPoints && b.points_awarded !== undefined && b.points_possible !== undefined && (
                    <span className="text-[10px] text-slate-400">
                      ({b.points_awarded} / {b.points_possible} pts)
                    </span>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      ) : (
        <>
          <p className={`mt-2 font-semibold text-[#102b4c] ${compact ? "text-xs leading-5" : "text-sm leading-6"}`}>
            {question.prompt}
          </p>
          {question.question_type === "short_answer" ? (
            <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-xs">
              {showStudentAnswers ? (
                <p>
                  <span className="font-bold">Your answer:</span>{" "}
                  {question.text_answer || "No answer"}
                </p>
              ) : (
                <p className="text-slate-500">Student response is hidden.</p>
              )}
              {showCorrect && question.accepted_answers && (
                <p className="mt-2">
                  <span className="font-bold">Accepted answers:</span>{" "}
                  {question.accepted_answers.join(" · ")}
                </p>
              )}
            </div>
          ) : (
            <div className="mt-3 space-y-2">
              {question.options.map((option) => {
                const wasSelected =
                  showStudentAnswers &&
                  (question.selected_option_id === option.id ||
                    (question.selected_option_ids ?? []).includes(option.id));
                const isCorrect = showCorrect
                  ? question.correct_option_ids
                    ? question.correct_option_ids.includes(option.id)
                    : question.correct_option_id === option.id
                  : undefined;

                let optionBorder = "border-slate-200 bg-white text-slate-700";
                if (wasSelected && isCorrect === true) {
                  optionBorder = "border-emerald-300 bg-emerald-50/70 text-emerald-900 font-bold";
                } else if (wasSelected && isCorrect === false) {
                  optionBorder = "border-rose-300 bg-rose-50/70 text-rose-900 font-bold";
                } else if (isCorrect === true) {
                  optionBorder = "border-emerald-200 bg-emerald-50/40 text-emerald-900";
                } else if (wasSelected) {
                  optionBorder = "border-[#0e8988] bg-teal-50/40 text-[#102b4c] font-bold";
                }

                return (
                  <div
                    key={option.id}
                    className={`flex w-full items-center justify-between gap-3 rounded-xl border p-3 text-xs ${optionBorder}`}
                  >
                    <div className="flex items-center gap-3">
                      <span
                        className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                          wasSelected
                            ? "border-[#0e8988] bg-[#0e8988] text-white"
                            : "border-slate-300 bg-white"
                        }`}
                      >
                        {wasSelected && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
                      </span>
                      <span>{option.option_text}</span>
                    </div>

                    <div className="flex items-center gap-2">
                      {wasSelected && (
                        <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[9px] font-semibold text-slate-600">
                          Your Answer
                        </span>
                      )}
                      {isCorrect === true && (
                        <span className="rounded-md bg-emerald-100 px-2 py-0.5 text-[9px] font-extrabold text-emerald-800">
                          ✓ Correct
                        </span>
                      )}
                      {wasSelected && isCorrect === false && (
                        <span className="rounded-md bg-rose-100 px-2 py-0.5 text-[9px] font-extrabold text-rose-800">
                          ✗ Incorrect
                        </span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}

      {showExplanation && question.explanation && (
        <div className="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/80 p-3">
          <p className="text-[10px] font-extrabold uppercase tracking-wide text-indigo-700">
            Explanation
          </p>
          <p className="mt-1 text-xs leading-6 text-indigo-950">{question.explanation}</p>
        </div>
      )}
    </div>
  );
}

export function ExerciseQuestionReview({
  attempt,
}: {
  attempt: ExerciseAttemptData;
}) {
  const visibility = resolveAttemptVisibility(attempt);
  if (!visibility.results_released || attempt.questions.length === 0) return null;

  if (!visibility.show_question_results && !visibility.show_student_answers) {
    return (
      <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
        Detailed question review is disabled for this assessment.
      </div>
    );
  }

  return (
    <div className="mt-5 space-y-5">
      <h5 className="text-xs font-extrabold uppercase tracking-wider text-slate-600">
        Question Review
      </h5>
      {attempt.questions.map((question, questionIndex) => (
        <AttemptQuestionReviewCard
          key={question.id}
          question={question}
          questionIndex={questionIndex}
          visibility={visibility}
        />
      ))}
    </div>
  );
}

