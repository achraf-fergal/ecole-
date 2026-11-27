"use client";

import type { ExerciseAttemptData } from "@/lib/learning";
import { resolveAttemptVisibility } from "./exercise-result-visibility";

export function ExerciseResultSummary({ attempt }: { attempt: ExerciseAttemptData | null }) {
  const completedAttempt = attempt;
  return (
    <>
      {/* Score Summary Card if completed attempt exists */}
      {completedAttempt && (() => {
        const vis = resolveAttemptVisibility(completedAttempt);
        if (!vis.results_released) {
          return (
            <div className="mt-5 rounded-2xl border border-amber-200 bg-amber-50/70 p-5 text-xs text-amber-950">
              <p className="font-bold">Attempt #{completedAttempt.attempt_number} submitted</p>
              <p className="mt-1 text-amber-900">
                Results and detailed feedback will be released after your final permitted attempt.
              </p>
            </div>
          );
        }

        return (
          <div className="mt-5 rounded-2xl border border-slate-100 bg-slate-50/80 p-5 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <p className="text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                  Latest Result (Attempt #{completedAttempt.attempt_number})
                </p>
                {(vis.show_score || vis.show_percentage) ? (
                  <div className="mt-1 flex items-baseline gap-2">
                    {vis.show_score && completedAttempt.score !== null && completedAttempt.max_score !== null && (
                      <span className="text-2xl font-black text-[#102b4c]">
                        {completedAttempt.score} / {completedAttempt.max_score}
                      </span>
                    )}
                    {vis.show_percentage && completedAttempt.percentage !== null && (
                      <span className="text-sm font-bold text-slate-500">
                        {vis.show_score ? `(${completedAttempt.percentage}%)` : `${completedAttempt.percentage}%`}
                      </span>
                    )}
                  </div>
                ) : (
                  <p className="mt-1 text-xs font-semibold text-slate-600">
                    Your responses have been recorded.
                  </p>
                )}
              </div>

              {vis.show_pass_fail && completedAttempt.passed !== null && (
                <div className="text-right">
                  <span
                    className={`inline-block rounded-xl px-3 py-1.5 text-xs font-black ${
                      completedAttempt.passed
                        ? "bg-emerald-100 text-emerald-800"
                        : "bg-rose-100 text-rose-800"
                    }`}
                  >
                    {completedAttempt.passed
                      ? "✓ Assessment Passed"
                      : "✗ Passing Score Not Reached"}
                  </span>
                  {completedAttempt.passing_score !== null && (
                    <p className="mt-1 text-[10px] text-slate-400">
                      Passing requirement: {completedAttempt.passing_score}%
                    </p>
                  )}
                </div>
              )}
            </div>

            {vis.show_question_results &&
              completedAttempt.correct_count !== null &&
              completedAttempt.correct_count !== undefined && (
                <div className="flex flex-wrap gap-2 border-t border-slate-200/70 pt-3 text-[11px] font-bold">
                  <span className="rounded-lg bg-emerald-50 px-2.5 py-1 text-emerald-800">
                    ✓ {completedAttempt.correct_count} Correct
                  </span>
                  <span className="rounded-lg bg-rose-50 px-2.5 py-1 text-rose-800">
                    ✗ {completedAttempt.incorrect_count ?? 0} Incorrect
                  </span>
                  {(completedAttempt.unanswered_count ?? 0) > 0 && (
                    <span className="rounded-lg bg-amber-50 px-2.5 py-1 text-amber-800">
                      — {completedAttempt.unanswered_count} Unanswered
                    </span>
                  )}
                </div>
              )}
          </div>
        );
      })()}


    </>
  );
}
