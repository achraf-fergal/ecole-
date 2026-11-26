"use client";

import React, { useEffect, useRef, useState } from "react";
import {
  extractPromptPlaceholders,
  getBlankCompletionState,
  isQuestionAnswered,
} from "@/lib/exercise-authoring.mjs";
import QuestionResponseRenderer, {
  type QuestionResponseValue,
} from "@/components/learning/question-response-renderer";
import type { ExerciseDraft } from "./exercise-builder-types";

interface ExercisePreviewProps {
  draft: ExerciseDraft;
  onClose: () => void;
}

export default function ExercisePreview({ draft, onClose }: ExercisePreviewProps) {
  const [answers, setAnswers] = useState<Record<string, QuestionResponseValue>>({});
  const [questionIndex, setQuestionIndex] = useState(0);
  const dialogRef = useRef<HTMLElement | null>(null);
  const closeButtonRef = useRef<HTMLButtonElement | null>(null);
  const currentQuestion = draft.questions[questionIndex];

  useEffect(() => {
    closeButtonRef.current?.focus();
  }, []);

  const answeredCount = draft.questions.filter((question) => {
    const value = answers[question.clientId];
    if (question.question_type === "fill_in_the_blanks") {
      return getBlankCompletionState(extractPromptPlaceholders(question.prompt).ids, value) === "answered";
    }
    return isQuestionAnswered(question.question_type, value);
  }).length;

  const handleKeyDown = (event: React.KeyboardEvent<HTMLElement>) => {
    if (event.key === "Escape") {
      event.preventDefault();
      onClose();
      return;
    }
    if (event.key !== "Tab") return;

    const focusable = dialogRef.current?.querySelectorAll<HTMLElement>(
      'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'
    );
    if (!focusable?.length) {
      event.preventDefault();
      dialogRef.current?.focus();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3 sm:p-6">
      <section
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="exercise-preview-title"
        aria-describedby="exercise-preview-description"
        tabIndex={-1}
        onKeyDown={handleKeyDown}
        className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl"
      >
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 pb-4">
          <div>
            <p className="text-[10px] font-bold uppercase tracking-wider text-teal-800">
              Student preview · no attempt is created
            </p>
            <h3 id="exercise-preview-title" className="mt-1 text-lg font-black text-[#102b4c]">
              {draft.title || "Untitled Exercise"}
            </h3>
            {draft.instructions && <p className="mt-1 text-xs text-slate-600">{draft.instructions}</p>}
            <p id="exercise-preview-description" className="mt-2 text-[11px] font-semibold text-slate-500">
              {answeredCount} of {draft.questions.length} answered · Passing score: {draft.passing_score}%
            </p>
          </div>
          <button
            ref={closeButtonRef}
            type="button"
            onClick={onClose}
            className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
          >
            Close
          </button>
        </div>

        <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
            <strong aria-live="polite">Question {questionIndex + 1} of {draft.questions.length}</strong>
            {currentQuestion && (
              <span>{currentQuestion.points} {Number(currentQuestion.points) === 1 ? "point" : "points"}</span>
            )}
          </div>
          {currentQuestion && (
            <div className="mt-4 rounded-xl border border-slate-100 bg-white p-4 sm:p-5">
              <QuestionResponseRenderer
                questionType={currentQuestion.question_type}
                prompt={currentQuestion.prompt}
                options={currentQuestion.options.map((option, index) => ({
                  id: index,
                  option_text: option.option_text,
                }))}
                value={answers[currentQuestion.clientId] ?? null}
                onChange={(value) =>
                  setAnswers((previous) => ({ ...previous, [currentQuestion.clientId]: value }))
                }
                questionNumber={questionIndex + 1}
                idPrefix={`preview-${currentQuestion.clientId}`}
              />
            </div>
          )}

          <div className="mt-4 flex items-center justify-between gap-2">
            <button
              type="button"
              disabled={questionIndex <= 0}
              onClick={() => setQuestionIndex((index) => Math.max(0, index - 1))}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-40"
            >
              Previous question
            </button>
            <button
              type="button"
              disabled={questionIndex >= draft.questions.length - 1}
              onClick={() => setQuestionIndex((index) => Math.min(draft.questions.length - 1, index + 1))}
              className="rounded-lg bg-[#0e8988] px-3 py-2 text-xs font-bold text-white disabled:opacity-40"
            >
              Next question
            </button>
          </div>
        </div>
      </section>
    </div>
  );
}
