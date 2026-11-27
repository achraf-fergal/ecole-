"use client";

import type { ExerciseCurrentAttemptState, LearningExercise } from "@/lib/learning";
import { ExerciseAttemptHistory, type ExerciseAttemptHistoryProps } from "./exercise-attempt-history";
import { ExerciseQuestionReview } from "./exercise-question-review";
import { ExerciseResultSummary } from "./exercise-result-summary";
import { resolveAttemptVisibility } from "./exercise-result-visibility";

interface ExerciseStartCardProps {
  exercise: LearningExercise;
  attemptState: ExerciseCurrentAttemptState | null;
  canStartNew: boolean;
  isStarting: boolean;
  actionError: string | null;
  handleStartAttempt: () => void | Promise<void>;
  history: ExerciseAttemptHistoryProps;
}

export default function ExerciseStartCard({
  exercise,
  attemptState,
  canStartNew,
  isStarting,
  actionError,
  handleStartAttempt,
  history,
}: ExerciseStartCardProps) {
  const completedAttempt = attemptState?.latest_completed_attempt;
  const attemptsRemaining = attemptState?.exercise.attempts_remaining;
  const attemptsCount = attemptState?.exercise.attempts_count ?? 0;
  const maxAttempts = attemptState?.exercise.max_attempts;

  return (
    <article className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-xs">
      {/* Header */}
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
            <span>Passing score: {exercise.passing_score}%</span>
            {maxAttempts && (
              <>
                <span>·</span>
                <span>Max attempts: {maxAttempts}</span>
              </>
            )}
            <span>·</span>
            <span>
              {attemptsCount} {attemptsCount === 1 ? "attempt" : "attempts"} taken
            </span>
          </div>
        </div>

        {completedAttempt ? (() => {
          const vis = resolveAttemptVisibility(completedAttempt);
          if (!vis.results_released) {
            return (
              <span className="rounded-full bg-amber-50 px-3 py-1 text-[10px] font-extrabold uppercase text-amber-800">
                Submitted
              </span>
            );
          }
          if (!vis.show_pass_fail || completedAttempt.passed === null) {
            return (
              <span className="rounded-full bg-slate-100 px-3 py-1 text-[10px] font-extrabold uppercase text-slate-700">
                Completed
              </span>
            );
          }
          return (
            <span
              className={`rounded-full px-3 py-1 text-[10px] font-extrabold uppercase ${
                completedAttempt.passed
                  ? "bg-emerald-50 text-emerald-700"
                  : "bg-rose-50 text-rose-700"
              }`}
            >
              {completedAttempt.passed ? "Passed" : "Failed"}
            </span>
          );
        })() : (
          <span className="rounded-full bg-[#e5f5f2] px-3 py-1 text-[10px] font-extrabold uppercase text-[#087b83]">
            Practice Assessment
          </span>
        )}
      </div>

      {actionError && (
        <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
          {actionError}
        </div>
      )}


      {completedAttempt && <ExerciseResultSummary attempt={completedAttempt} />}
      {completedAttempt && <ExerciseQuestionReview attempt={completedAttempt} />}
      <ExerciseAttemptHistory {...history} />
      {/* Start or Retake Actions */}
      <div className="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 pt-5">
        <div className="text-xs text-slate-500">
          {attemptsRemaining !== null && attemptsRemaining !== undefined ? (
            <span>
              {attemptsRemaining}{" "}
              {attemptsRemaining === 1 ? "attempt" : "attempts"} remaining
            </span>
          ) : (
            <span>Unlimited attempts allowed</span>
          )}
        </div>

        {canStartNew ? (
          <button
            type="button"
            disabled={isStarting}
            onClick={handleStartAttempt}
            className="rounded-xl bg-[#0e8988] px-5 py-2.5 text-xs font-bold text-white shadow-xs transition hover:bg-[#0c7776] disabled:opacity-50"
          >
            {isStarting
              ? "Starting..."
              : completedAttempt
              ? "Retake Assessment"
              : "Start Assessment"}
          </button>
        ) : (
          <span className="text-xs font-bold text-slate-400">
            Maximum attempts reached
          </span>
        )}
      </div>
    </article>
  );
}
