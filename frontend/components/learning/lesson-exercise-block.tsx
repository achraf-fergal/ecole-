"use client";

import React, { useState } from "react";
import type { LessonBlock } from "@/lib/learning";

interface LessonExerciseBlockProps {
  block: LessonBlock;
  isAdminPreview?: boolean;
}

export default function LessonExerciseBlock({
  block,
  isAdminPreview = false,
}: LessonExerciseBlockProps) {
  const exercise = block.exercise;
  const [selectedAnswers, setSelectedAnswers] = useState<Record<number, number>>({});

  if (!exercise) {
    return null;
  }

  const handleSelectOption = (questionId: number, optionId: number) => {
    setSelectedAnswers((prev) => ({
      ...prev,
      [questionId]: optionId,
    }));
  };

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
            <span>Passing score: {exercise.passing_score}%</span>
            {exercise.max_attempts && (
              <>
                <span>·</span>
                <span>Max attempts: {exercise.max_attempts}</span>
              </>
            )}
          </div>
        </div>

        {isAdminPreview ? (
          <span className="rounded-md bg-amber-50 px-2.5 py-1 text-[10px] font-extrabold uppercase text-amber-700">
            {exercise.status}
          </span>
        ) : (
          <span className="rounded-full bg-[#e5f5f2] px-3 py-1 text-[10px] font-extrabold uppercase text-[#087b83]">
            Practice Assessment
          </span>
        )}
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
                  Question {questionIndex + 1} ({question.points} {Number(question.points) === 1 ? "point" : "points"})
                </span>
                <span className="text-[10px] text-slate-400 capitalize">
                  {question.question_type.replace("_", " ")}
                </span>
              </div>

              <p className="mt-2 text-sm font-semibold leading-6 text-[#102b4c]">
                {question.prompt}
              </p>

              <div className="mt-4 space-y-2">
                {question.options.map((option) => {
                  const isSelected = selectedAnswers[question.id] === option.id;

                  return (
                    <button
                      type="button"
                      key={option.id}
                      onClick={() => handleSelectOption(question.id, option.id)}
                      className={`flex w-full items-center justify-between gap-3 rounded-xl border p-3.5 text-left text-xs transition ${
                        isSelected
                          ? "border-[#0e8988] bg-teal-50/40 text-[#102b4c] font-bold"
                          : "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50/50"
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        <span
                          className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                            isSelected
                              ? "border-[#0e8988] bg-[#0e8988] text-white"
                              : "border-slate-300 bg-white"
                          }`}
                        >
                          {isSelected && (
                            <span className="h-1.5 w-1.5 rounded-full bg-white" />
                          )}
                        </span>
                        <span>{option.option_text}</span>
                      </div>

                      {isAdminPreview && option.is_correct === true && (
                        <span className="rounded-md bg-emerald-50 px-2 py-0.5 text-[9px] font-extrabold text-emerald-700">
                          Correct Answer
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>

              {isAdminPreview && question.explanation && (
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
