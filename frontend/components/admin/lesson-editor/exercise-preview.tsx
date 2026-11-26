"use client";

import React, { useState } from "react";
import { extractPromptPlaceholders, getBlankCompletionState, isQuestionAnswered } from "@/lib/exercise-authoring.mjs";
import type { ExerciseDraft } from "./exercise-builder-types";

interface ExercisePreviewProps {
  draft: ExerciseDraft;
  onClose: () => void;
}

type PreviewAnswerValue = number | number[] | string | Record<string, string>;

export default function ExercisePreview({ draft, onClose }: ExercisePreviewProps) {
  const [answers, setAnswers] = useState<Record<string, PreviewAnswerValue>>({});

  const answeredCount = draft.questions.filter((q) => {
    const val = answers[q.clientId];
    if (q.question_type === "fill_in_the_blanks") {
      return getBlankCompletionState(extractPromptPlaceholders(q.prompt).ids, val) === "answered";
    }
    return isQuestionAnswered(q.question_type, val);
  }).length;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="exercise-preview-title"
      onKeyDown={(event) => {
        if (event.key === "Escape") onClose();
      }}
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3 sm:p-6"
    >
      <section className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 pb-4">
          <div>
            <p className="text-[10px] font-bold uppercase tracking-wider text-teal-800">
              Student preview · no attempt is created
            </p>
            <h3 id="exercise-preview-title" className="mt-1 text-lg font-black text-[#102b4c]">
              {draft.title || "Untitled Exercise"}
            </h3>
            {draft.instructions && (
              <p className="mt-1 text-xs text-slate-600">{draft.instructions}</p>
            )}
            <p className="mt-2 text-[11px] font-semibold text-slate-500">
              {answeredCount} of {draft.questions.length} answered · Passing score: {draft.passing_score}%
            </p>
          </div>
          <button
            type="button"
            autoFocus
            onClick={onClose}
            className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
          >
            Close
          </button>
        </div>

        <div className="mt-4 space-y-4">
          {draft.questions.map((question, questionIndex) => {
            const val = answers[question.clientId];
            return (
              <fieldset
                key={question.clientId}
                className="rounded-xl border border-slate-200 bg-slate-50/60 p-4"
              >
                <legend className="px-1 text-xs font-bold text-slate-700">
                  Question {questionIndex + 1} · {question.points} {Number(question.points) === 1 ? "point" : "points"}
                </legend>

                {question.question_type === "fill_in_the_blanks" ? (
                  <div className="mt-1 text-sm font-semibold leading-relaxed text-[#102b4c]">
                    {(() => {
                      let blankCounter = 0;
                      return (question.prompt || "Question prompt")
                        .split(/(\[[a-zA-Z0-9_\-]+\])/g)
                        .map((part, partIdx) => {
                          const match = /^\[([a-zA-Z0-9_\-]+)\]$/.exec(part);
                          if (!match) return <span key={partIdx}>{part}</span>;
                          blankCounter += 1;
                          const blankId = match[1];
                          const currentMap =
                            typeof val === "object" && val !== null && !Array.isArray(val)
                              ? (val as Record<string, string>)
                              : {};
                          return (
                            <input
                              key={`preview-${question.clientId}-${blankId}-${partIdx}`}
                              type="text"
                              aria-label={`Question ${questionIndex + 1}, Blank ${blankCounter}`}
                              placeholder="Type answer"
                              value={currentMap[blankId] ?? ""}
                              onChange={(event) =>
                                setAnswers((current) => ({
                                  ...current,
                                  [question.clientId]: {
                                    ...currentMap,
                                    [blankId]: event.target.value,
                                  },
                                }))
                              }
                              className="mx-1.5 inline-block w-28 sm:w-36 max-w-full rounded-lg border border-[#0e8988]/60 bg-white px-2.5 py-1 text-sm font-normal text-slate-800 focus:border-[#0e8988] focus:outline-none focus:ring-2 focus:ring-[#0e8988]/40"
                            />
                          );
                        });
                    })()}
                  </div>
                ) : (
                  <>
                    <p className="mb-3 mt-1 text-sm font-semibold text-[#102b4c]">
                      {question.prompt || "Question prompt"}
                    </p>
                    {question.question_type === "short_answer" ? (
                      <label className="block text-xs font-semibold text-slate-600">
                        Your answer
                        <input
                          type="text"
                          value={typeof val === "string" ? val : ""}
                          onChange={(event) =>
                            setAnswers((current) => ({
                              ...current,
                              [question.clientId]: event.target.value,
                            }))
                          }
                          className="mt-1 w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm font-normal text-slate-900"
                        />
                      </label>
                    ) : (
                      <div
                        role={question.question_type === "multiple_select" ? "group" : "radiogroup"}
                        aria-label={`Question ${questionIndex + 1} answers`}
                        className="space-y-2"
                      >
                        {question.options.map((option, optionIndex) => {
                          const checked =
                            question.question_type === "multiple_select"
                              ? Array.isArray(val) && val.includes(optionIndex)
                              : val === optionIndex;
                          return (
                            <label
                              key={option.clientId}
                              className={`flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 text-xs transition ${
                                checked
                                  ? "border-[#0e8988] bg-teal-50/40 font-bold text-[#102b4c]"
                                  : "border-slate-200 bg-white text-slate-700"
                              }`}
                            >
                              <input
                                type={question.question_type === "multiple_select" ? "checkbox" : "radio"}
                                name={`preview-${question.clientId}`}
                                checked={checked}
                                onChange={() =>
                                  setAnswers((current) => ({
                                    ...current,
                                    [question.clientId]:
                                      question.question_type === "multiple_select"
                                        ? [
                                            ...(Array.isArray(current[question.clientId])
                                              ? (current[question.clientId] as number[])
                                              : []
                                            ).filter((index) => index !== optionIndex),
                                            ...(checked ? [] : [optionIndex]),
                                          ]
                                        : optionIndex,
                                  }))
                                }
                                className="h-4 w-4 accent-[#0e8988]"
                              />
                              <span>{option.option_text || `Option ${optionIndex + 1}`}</span>
                            </label>
                          );
                        })}
                      </div>
                    )}
                  </>
                )}
              </fieldset>
            );
          })}
        </div>
      </section>
    </div>
  );
}
