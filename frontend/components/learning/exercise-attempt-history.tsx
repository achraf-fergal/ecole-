"use client";

import type { PaginatedExerciseAttempts } from "@/lib/learning";
import { AttemptQuestionReviewCard } from "./exercise-question-review";
import { resolveAttemptVisibility } from "./exercise-result-visibility";

export interface ExerciseAttemptHistoryProps {
  attemptsCount: number;
  isHistoryOpen: boolean;
  handleToggleHistory: () => void | Promise<void>;
  historyData: PaginatedExerciseAttempts | null;
  isLoadingHistory: boolean;
  historyError: string | null;
  historyPage: number;
  fetchHistoryPage: (page: number) => void | Promise<void>;
  inspectingAttemptId: number | null;
  setInspectingAttemptId: (attemptId: number | null) => void;
}

export function ExerciseAttemptHistory(props: ExerciseAttemptHistoryProps) {
  const {
    attemptsCount,
    isHistoryOpen,
    handleToggleHistory,
    historyData,
    isLoadingHistory,
    historyError,
    historyPage,
    fetchHistoryPage,
    inspectingAttemptId,
    setInspectingAttemptId,
  } = props;
  return (
    <>
      {/* Attempt History Section */}
      {attemptsCount > 0 && (
        <div className="mt-6 border-t border-slate-100 pt-5">
          <button
            type="button"
            onClick={() => void handleToggleHistory()}
            className="flex items-center gap-2 text-xs font-bold text-[#0e8988] hover:text-[#0c7776]"
          >
            <span>{isHistoryOpen ? "▼" : "▶"}</span>
            <span>
              View Attempt History ({attemptsCount}{" "}
              {attemptsCount === 1 ? "attempt" : "attempts"})
            </span>
          </button>

          {isHistoryOpen && (
            <div className="mt-4 space-y-3">
              {isLoadingHistory && (
                <div className="flex items-center justify-center gap-2 rounded-xl border border-slate-100 bg-slate-50 p-4 text-xs text-slate-500">
                  <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-[#0e8988] border-t-transparent" />
                  <span>Loading attempt history...</span>
                </div>
              )}

              {historyError && (
                <div className="flex items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                  <span>{historyError}</span>
                  <button
                    type="button"
                    onClick={() => void fetchHistoryPage(historyPage)}
                    className="rounded-lg border border-rose-300 bg-white px-2.5 py-1 font-bold text-rose-800 hover:bg-rose-50"
                  >
                    Retry
                  </button>
                </div>
              )}

              {historyData &&
                historyData.data.length === 0 &&
                !isLoadingHistory && (
                  <p className="text-xs italic text-slate-500">
                    No attempts recorded yet.
                  </p>
                )}

              {historyData &&
                historyData.data.map((hist) => {
                  const isInspecting = inspectingAttemptId === hist.id;
                  const histVis = resolveAttemptVisibility(hist);

                  return (
                    <div
                      key={hist.id}
                      className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 transition"
                    >
                      <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                          <div className="flex items-center gap-2">
                            <span className="text-xs font-black text-[#102b4c]">
                              Attempt #{hist.attempt_number}
                            </span>
                            {!histVis.results_released ? (
                              <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[9px] font-extrabold uppercase text-amber-800">
                                Results Withheld
                              </span>
                            ) : histVis.show_pass_fail && hist.passed !== null ? (
                              <span
                                className={`rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase ${
                                  hist.passed === true
                                    ? "bg-emerald-50 text-emerald-700"
                                    : "bg-rose-50 text-rose-700"
                                }`}
                              >
                                {hist.passed === true ? "Passed" : "Failed"}
                              </span>
                            ) : (
                              <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-extrabold uppercase text-slate-600">
                                Submitted
                              </span>
                            )}
                          </div>

                          <p className="mt-1 text-[11px] text-slate-500">
                            {hist.submitted_at
                              ? `Submitted on ${new Date(
                                  hist.submitted_at
                                ).toLocaleDateString()} at ${new Date(
                                  hist.submitted_at
                                ).toLocaleTimeString([], {
                                  hour: "2-digit",
                                  minute: "2-digit",
                                })}`
                              : `Started on ${new Date(
                                  hist.started_at
                                ).toLocaleDateString()}`}
                          </p>
                        </div>

                        <div className="flex items-center gap-4">
                          {histVis.results_released && (histVis.show_score || histVis.show_percentage) && (
                            <div className="text-right">
                              {histVis.show_score && hist.score !== null && hist.max_score !== null && (
                                <span className="text-sm font-black text-[#102b4c]">
                                  {hist.score} / {hist.max_score}
                                </span>
                              )}
                              {histVis.show_percentage && hist.percentage !== null && (
                                <span className="ml-1 text-xs text-slate-500">
                                  ({hist.percentage}%)
                                </span>
                              )}
                            </div>
                          )}

                          {histVis.results_released && (histVis.show_question_results || histVis.show_student_answers) && (
                            <button
                              type="button"
                              onClick={() =>
                                setInspectingAttemptId(
                                  isInspecting ? null : hist.id
                                )
                              }
                              className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-bold text-slate-700 hover:bg-slate-100"
                            >
                              {isInspecting ? "Close Review" : "Review Answers"}
                            </button>
                          )}
                        </div>
                      </div>

                      {/* Detailed Review for this historical attempt */}
                      {isInspecting && histVis.results_released && hist.questions.length > 0 && (
                        <div className="mt-4 space-y-4 border-t border-slate-200/60 pt-4">
                          {hist.questions.map((q, qIdx) => (
                            <AttemptQuestionReviewCard
                              key={q.id}
                              question={q}
                              questionIndex={qIdx}
                              visibility={histVis}
                              compact
                            />
                          ))}
                        </div>
                      )}
                    </div>
                  );
                })}

              {/* Pagination Controls */}
              {historyData && historyData.meta.last_page > 1 && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-200/60 pt-3 text-xs">
                  <button
                    type="button"
                    disabled={historyData.meta.current_page <= 1 || isLoadingHistory}
                    onClick={() => void fetchHistoryPage(historyData.meta.current_page - 1)}
                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-40"
                  >
                    Previous
                  </button>
                  <span className="text-slate-500">
                    Page {historyData.meta.current_page} of {historyData.meta.last_page} ({historyData.meta.total} total)
                  </span>
                  <button
                    type="button"
                    disabled={historyData.meta.current_page >= historyData.meta.last_page || isLoadingHistory}
                    onClick={() => void fetchHistoryPage(historyData.meta.current_page + 1)}
                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-40"
                  >
                    Next
                  </button>
                </div>
              )}
            </div>
          )}
        </div>
      )}


    </>
  );
}
