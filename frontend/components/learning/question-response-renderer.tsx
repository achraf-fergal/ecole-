"use client";

import React from "react";
import type { QuestionType } from "@/lib/learning";

export type ResponseOptionId = number;
export type QuestionResponseValue =
  | ResponseOptionId
  | ResponseOptionId[]
  | string
  | Record<string, string>
  | null;

export interface ResponseOption {
  id: ResponseOptionId;
  option_text: string;
}

interface QuestionResponseRendererProps {
  questionType: QuestionType;
  prompt: string;
  options: ResponseOption[];
  value: QuestionResponseValue;
  onChange: (value: QuestionResponseValue) => void;
  questionNumber: number;
  idPrefix: string;
  disabled?: boolean;
  incomplete?: boolean;
}

function isBlankAnswer(value: QuestionResponseValue): value is Record<string, string> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

export default function QuestionResponseRenderer({
  questionType,
  prompt,
  options,
  value,
  onChange,
  questionNumber,
  idPrefix,
  disabled = false,
  incomplete = false,
}: QuestionResponseRendererProps) {
  if (questionType === "fill_in_the_blanks") {
    const answerByBlank = isBlankAnswer(value) ? value : {};
    const promptParts = (prompt || "Question prompt").split(/(\[[a-zA-Z0-9_\-]+\])/g);

    return (
      <div id={`${idPrefix}-prompt`} className="text-sm font-semibold leading-relaxed text-[#102b4c]">
        {promptParts.map((part, partIndex) => {
          const match = /^\[([a-zA-Z0-9_\-]+)\]$/.exec(part);
          if (!match) return <span key={partIndex}>{part}</span>;

          const blankCounter = promptParts
            .slice(0, partIndex + 1)
            .filter((previousPart) => /^\[[a-zA-Z0-9_\-]+\]$/.test(previousPart)).length;
          const blankId = match[1];
          return (
            <input
              key={`${idPrefix}-${blankId}-${partIndex}`}
              id={`${idPrefix}-blank-${blankId}`}
              type="text"
              data-blank-id={blankId}
              aria-label={`Question ${questionNumber}, blank ${blankCounter}`}
              aria-describedby={`${idPrefix}-blank-guide`}
              aria-invalid={incomplete && !(answerByBlank[blankId] ?? "").trim() || undefined}
              value={answerByBlank[blankId] ?? ""}
              maxLength={5000}
              disabled={disabled}
              onChange={(event) => onChange({ ...answerByBlank, [blankId]: event.target.value })}
              className={`mx-1.5 inline-block w-28 max-w-full rounded-lg border bg-white px-2.5 py-1 text-sm font-normal text-slate-800 shadow-inner focus:border-[#0e8988] focus:outline-none focus:ring-2 focus:ring-[#0e8988]/40 sm:w-36 ${incomplete && !(answerByBlank[blankId] ?? "").trim() ? "border-amber-400" : "border-[#0e8988]/60"}`}
            />
          );
        })}
        <p id={`${idPrefix}-blank-guide`} className="mt-2 text-[11px] font-normal text-slate-500">
          {incomplete ? "Some blanks are still empty. Fill in all blanks to complete this question." : "Fill in each blank field."}
        </p>
      </div>
    );
  }

  const promptId = `${idPrefix}-prompt`;
  if (questionType === "short_answer") {
    return (
      <div>
        <p id={promptId} className="text-sm font-semibold leading-6 text-[#102b4c]">
          {prompt || "Question prompt"}
        </p>
        <label className="mt-4 block text-xs font-semibold text-slate-700">
          Your answer
          <input
            type="text"
            maxLength={5000}
            value={typeof value === "string" ? value : ""}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
            className="mt-1 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm font-normal"
          />
          <span className="mt-1 block text-[11px] font-normal text-slate-500">
            Enter a short answer. Matching follows the teacher&apos;s configured rules.
          </span>
        </label>
      </div>
    );
  }

  const isMultiple = questionType === "multiple_select";
  const selectedIds = Array.isArray(value) ? value : [];

  return (
    <div>
      <p id={promptId} className="text-sm font-semibold leading-6 text-[#102b4c]">
        {prompt || "Question prompt"}
      </p>
      <fieldset
        className="mt-4 space-y-2"
        role={isMultiple ? "group" : "radiogroup"}
        aria-labelledby={promptId}
      >
        {isMultiple && <legend className="mb-2 text-xs font-semibold text-slate-600">Select all that apply.</legend>}
        {options.map((option, optionIndex) => {
          const checked = isMultiple
            ? selectedIds.includes(option.id)
            : value === option.id;
          return (
            <label
              key={option.id}
              className={`flex cursor-pointer items-center gap-3 rounded-xl border p-3.5 text-xs transition ${
                checked
                  ? "border-[#0e8988] bg-teal-50/40 font-bold text-[#102b4c]"
                  : "border-slate-200 bg-white text-slate-700 hover:border-slate-300"
              }`}
            >
              <input
                type={isMultiple ? "checkbox" : "radio"}
                name={`${idPrefix}-option`}
                value={String(option.id)}
                data-option-id={option.id}
                aria-label={`Question ${questionNumber}, option ${optionIndex + 1}: ${option.option_text}`}
                checked={checked}
                disabled={disabled}
                onChange={() => {
                  if (!isMultiple) {
                    onChange(option.id);
                    return;
                  }
                  onChange(
                    checked
                      ? selectedIds.filter((id) => id !== option.id)
                      : [...selectedIds, option.id]
                  );
                }}
                className="h-4 w-4 accent-[#0e8988]"
              />
              <span>{option.option_text || `Option ${optionIndex + 1}`}</span>
            </label>
          );
        })}
      </fieldset>
    </div>
  );
}
