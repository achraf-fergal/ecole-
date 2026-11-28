"use client";

import type {
  AttemptQuestion,
  ExerciseAttemptData,
  LearningExercise,
} from "@/lib/learning";
import QuestionResponseRenderer from "./question-response-renderer";
import { getQuestionCompletionStatus, type AnswerValue } from "./exercise-attempt-utils";
import FillBlankRenderer from "./fill-blank-renderer";
import type { QuestionResponseValue } from "./question-response-renderer";

type PreviewProps = {
  mode: "preview";
  exercise: LearningExercise;
  previewSelectedAnswers: Record<number, AnswerValue>;
  handleBlankAnswer: (questionId: number, blankId: string, value: string) => void;
  handleSelectTextAnswer: (questionId: number, value: string) => void;
  handleSelectOption: (questionId: number, optionId: number, questionType: string) => void;
};

type AttemptProps = {
  mode: "attempt";
  isSimulation?: boolean;
  onResetSimulation?: () => void;
  exercise: LearningExercise;
  activeAttempt: ExerciseAttemptData;
  currentQuestionIndex: number;
  answeredCount: number;
  selectedAnswers: Record<number, AnswerValue>;
  isSaving: boolean;
  isSubmitting: boolean;
  actionError: string | null;
  saveSuccessMessage: string | null;
  navigateToQuestion: (index: number) => void;
  handleStudentResponseChange: (question: AttemptQuestion, value: QuestionResponseValue) => void;
  handleSaveAnswers: () => void | Promise<void>;
  handleSubmitAttempt: () => void | Promise<void>;
};

export type ExerciseAttemptRunnerProps = PreviewProps | AttemptProps;

export function ExerciseAttemptRunner(props: ExerciseAttemptRunnerProps) {
  if (props.mode === "preview") {
    const { exercise, previewSelectedAnswers, handleBlankAnswer, handleSelectTextAnswer, handleSelectOption } = props;
    return (
      <article className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-xs">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-teal-50 text-xs font-bold text-[#0e8988]">
                ✓
              </span>
              <h4 className="text-lg font-black text-[#102b4c]">
                {exercise.title}
              </h4>
            </div>

            {exercise.instructions && (
              <p className="mt-2 text-xs leading-6 text-slate-600">
                {exercise.instructions}
              </p>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
              <span>{exercise.questions.length} questions</span>
              <span>·</span>
              <span>Total: {exercise.total_points} points</span>
              <span>Passing score: {exercise.passing_score}%</span>
              {exercise.max_attempts && (
                <>
                  <span>·</span>
                  <span>Max attempts: {exercise.max_attempts}</span>
                </>
              )}
            </div>
          </div>

          <span className="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-extrabold uppercase text-amber-800">
            Author Preview (Instructor Only) · {exercise.status}
          </span>
        </div>

        {exercise.questions.length > 0 && (
          <div className="mt-5 space-y-5">
            {exercise.questions.map((question, questionIndex) => (
              <div
                key={question.id}
                className="rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:p-5"
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                    Question {questionIndex + 1} ({question.points}{" "}
                    {Number(question.points) === 1 ? "point" : "points"})
                  </span>
                  <span className="text-[10px] text-slate-400 capitalize">
                    {question.question_type.replace("_", " ")}
                  </span>
                </div>

                {question.question_type === "fill_in_the_blanks" ? (
                  <div className="mt-2 leading-relaxed text-sm font-semibold text-[#102b4c]">
                    <FillBlankRenderer
                      prompt={question.prompt}
                      renderBlank={(blankId, blankCounter, partIndex) => {
                        const blankValues = (typeof previewSelectedAnswers[question.id] === "object" && previewSelectedAnswers[question.id] !== null
                          ? previewSelectedAnswers[question.id]
                          : {}) as Record<string, string>;
                        return (
                          <input
                            key={`preview-blank-${blankId}-${partIndex}`}
                            id={`preview-exercise-${exercise.id}-q-${question.id}-blank-${blankId}`}
                            type="text"
                            data-blank-id={blankId}
                            aria-label={`Question ${questionIndex + 1}, Blank ${blankCounter}`}
                            value={blankValues[blankId] ?? ""}
                            onChange={(event) => handleBlankAnswer(question.id, blankId, event.target.value)}
                            className="mx-1.5 inline-block w-28 sm:w-36 max-w-full rounded-lg border border-[#0e8988]/60 bg-white px-2.5 py-1 text-sm font-normal text-slate-800 shadow-inner focus:border-[#0e8988] focus:outline-none focus:ring-2 focus:ring-[#0e8988]/40"
                          />
                        );
                      }}
                    />
                  </div>
                ) : (
                  <>
                    <p className="mt-2 text-sm font-semibold leading-6 text-[#102b4c]">
                      {question.prompt}
                    </p>
                    {question.question_type === "short_answer" ? (
                  <label className="mt-4 block text-xs font-semibold text-slate-700">
                    Your answer
                    <input type="text" value={typeof previewSelectedAnswers[question.id] === "string" ? previewSelectedAnswers[question.id] as string : ""} onChange={(event) => handleSelectTextAnswer(question.id, event.target.value)} className="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm" />
                  </label>
                ) : (
                  <fieldset className="mt-4 space-y-2" role={question.question_type === "multiple_select" ? "group" : "radiogroup"} aria-label={`Preview question ${questionIndex + 1} answers`}>
                    {question.question_type === "multiple_select" && <legend className="mb-2 text-xs text-slate-600">Select all that apply.</legend>}
                    {question.options.map((option) => {
                      const answer = previewSelectedAnswers[question.id];
                      const isSelected = question.question_type === "multiple_select"
                        ? Array.isArray(answer) && answer.includes(option.id)
                        : answer === option.id;
                      return (
                        <label key={option.id} className={`flex items-center justify-between gap-3 rounded-xl border p-3.5 text-xs ${isSelected ? "border-[#0e8988] bg-teal-50/40 font-bold text-[#102b4c]" : "border-slate-200 bg-white text-slate-700"}`}>
                          <span className="flex items-center gap-3">
                            <input type={question.question_type === "multiple_select" ? "checkbox" : "radio"} name={`preview-answer-${question.id}`} checked={isSelected} onChange={() => handleSelectOption(question.id, option.id, question.question_type)} className="h-4 w-4 accent-[#0e8988]" />
                            {option.option_text}
                          </span>
                          {option.is_correct === true && <span className="rounded-md bg-emerald-50 px-2 py-0.5 text-[9px] font-extrabold text-emerald-700">Correct Answer</span>}
                        </label>
                      );
                    })}
                  </fieldset>
                    )}
                  </>
                )}

                {question.explanation && (
                  <div className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/80 p-3.5">
                    <p className="text-[10px] font-extrabold uppercase tracking-wide text-indigo-700">
                      Instructor Explanation
                    </p>
                    <p className="mt-1 text-xs leading-6 text-indigo-950">
                      {question.explanation}
                    </p>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </article>
    );
  }

  const {
    isSimulation = false,
    onResetSimulation,
    exercise,
    activeAttempt,
    currentQuestionIndex,
    answeredCount,
    selectedAnswers,
    isSaving,
    isSubmitting,
    actionError,
    saveSuccessMessage,
    navigateToQuestion,
    handleStudentResponseChange,
    handleSaveAnswers,
    handleSubmitAttempt,
  } = props;
    return (
      <article className="rounded-2xl border border-[#0e8988]/30 bg-white p-5 sm:p-7 shadow-xs">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-teal-50 text-xs font-bold text-[#0e8988]">
                📝
              </span>
              <h4 className="text-lg font-black text-[#102b4c]">
                {isSimulation
                  ? `${exercise.title} — Student Simulation`
                  : `${exercise.title} — Attempt #${activeAttempt.attempt_number}`}
              </h4>
            </div>

            {exercise.instructions && (
              <p className="mt-2 text-xs leading-6 text-slate-600">
                {exercise.instructions}
              </p>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
              <span>{activeAttempt.questions.length} questions</span>
              <span>·</span>
              <span>Total: {exercise.total_points} points</span>
              <span>Passing score: {exercise.passing_score}%</span>
              <span>·</span>
              <span className="font-semibold text-teal-700">
                {isSimulation ? "Simulated Attempt (No Data Saved)" : "In Progress"}
              </span>
            </div>
          </div>

          <span className="rounded-full bg-teal-50 px-3 py-1 text-[10px] font-extrabold uppercase text-[#0e8988]">
            {isSimulation ? "Student Simulation" : "Assessment in Progress"}
          </span>
        </div>

        {actionError && (
          <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
            {actionError}
          </div>
        )}

        {saveSuccessMessage && (
          <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
            {saveSuccessMessage}
          </div>
        )}

        <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
            <strong aria-live="polite">Question {Math.min(currentQuestionIndex + 1, activeAttempt.questions.length)} of {activeAttempt.questions.length}</strong>
            <span>{answeredCount} answered · {activeAttempt.questions.length - answeredCount} unanswered</span>
          </div>
          <div
            className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"
            role="progressbar"
            aria-label="Assessment progress"
            aria-valuemin={0}
            aria-valuemax={activeAttempt.questions.length}
            aria-valuenow={answeredCount}
          >
            <div className="h-full rounded-full bg-[#0e8988] transition-[width]" style={{ width: `${activeAttempt.questions.length ? (answeredCount / activeAttempt.questions.length) * 100 : 0}%` }} />
          </div>
          <nav aria-label="Question navigation" className="mt-3 flex gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0">
            {activeAttempt.questions.map((question, index) => {
              const status = getQuestionCompletionStatus(question, selectedAnswers[question.id]);
              const statusLabel = status === "answered" ? "answered" : status === "partial" ? "incomplete" : "unanswered";
              return (
                <button
                  key={question.id}
                  type="button"
                  aria-label={`Question ${index + 1}, ${statusLabel}`}
                  aria-current={index === currentQuestionIndex ? "step" : undefined}
                  onClick={() => navigateToQuestion(index)}
                  className={`inline-flex min-h-10 min-w-10 shrink-0 items-center justify-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-bold transition-colors ${index === currentQuestionIndex ? "border-[#0e8988] bg-teal-50 text-teal-900 ring-2 ring-[#0e8988]/15" : status === "partial" ? "border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100" : "border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50"}`}
                >
                  {index + 1}
                  <span
                    aria-hidden="true"
                    className={`h-1.5 w-1.5 rounded-full ${status === "answered" ? "bg-emerald-500" : status === "partial" ? "bg-amber-500" : "bg-slate-300"}`}
                  />
                </button>
              );
            })}
          </nav>
        </div>

        <div className="mt-5 space-y-5">
          {activeAttempt.questions.slice(currentQuestionIndex, currentQuestionIndex + 1).map((question) => {
            const questionIndex = currentQuestionIndex;
            const completionStatus = getQuestionCompletionStatus(question, selectedAnswers[question.id]);
            return (
            <div
              key={question.id}
              id={`attempt-question-${question.id}`}
              aria-labelledby={`exercise-${exercise.id}-q-${question.id}-prompt`}
              className="rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:p-5"
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                  Question {questionIndex + 1} ({question.points_possible}{" "}
                  {Number(question.points_possible) === 1 ? "point" : "points"})
                </span>
                {completionStatus === "answered" && (
                  <span className="text-[10px] font-bold text-teal-700">
                    Answered
                  </span>
                )}
                {completionStatus === "partial" && (
                  <span className="text-[10px] font-bold text-amber-700">
                    Incomplete
                  </span>
                )}
              </div>

              <QuestionResponseRenderer
                questionType={question.question_type}
                prompt={question.prompt}
                options={question.options.map((option) => ({
                  id: option.id,
                  option_text: option.option_text,
                }))}
                value={selectedAnswers[question.id] ?? null}
                onChange={(value) => handleStudentResponseChange(question, value)}
                questionNumber={questionIndex + 1}
                idPrefix={`exercise-${exercise.id}-q-${question.id}`}
                disabled={isSaving || isSubmitting}
                incomplete={completionStatus === "partial"}
              />
            </div>
            );
          })}
        </div>

        <div className="mt-4 flex items-center justify-between gap-3">
          <button
            type="button"
            disabled={currentQuestionIndex <= 0}
            onClick={() => navigateToQuestion(currentQuestionIndex - 1)}
            className="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 sm:flex-none"
          >
            Previous question
          </button>
          <button
            type="button"
            disabled={currentQuestionIndex >= activeAttempt.questions.length - 1}
            onClick={() => navigateToQuestion(currentQuestionIndex + 1)}
            className="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-[#102b4c] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#183b64] disabled:cursor-not-allowed disabled:opacity-40 sm:flex-none"
          >
            Next question
          </button>
        </div>

        <div className="mt-6 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
          {isSimulation ? (
            <button
              type="button"
              disabled={isSubmitting}
              onClick={onResetSimulation}
              className="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
            >
              Reset Simulation
            </button>
          ) : (
            <button
              type="button"
              disabled={isSaving || isSubmitting}
              onClick={() => void handleSaveAnswers()}
              className="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
            >
              {isSaving ? "Saving..." : "Save Progress"}
            </button>
          )}

          <button
            type="button"
            disabled={isSaving || isSubmitting}
            onClick={handleSubmitAttempt}
            className="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-[#0e8988] px-6 py-3 text-sm font-extrabold text-white shadow-sm transition hover:bg-[#0c7776] disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
          >
            {isSubmitting ? "Submitting..." : isSimulation ? "Submit Simulation" : "Submit Assessment"}
          </button>
        </div>
      </article>
    );
}
