"use client";

import React, { useRef, useState } from "react";
import type {
  BlankAnswerConfigPayload,
  BlankDefinitionPayload,
  ShortAnswerConfigPayload,
  ShortAnswerNormalizationPayload,
} from "@/lib/admin-learning";
import {
  convertPromptSelectionToBlank,
  createClientId,
  normalizeShortAnswer,
  removeBlankFromPrompt,
  sumBlankPoints,
  syncBlankIdRenameInPrompt,
} from "@/lib/exercise-authoring.mjs";
import {
  defaultNormalization,
  ensureBlankClientIds,
  NORMALIZATION_RULE_DESCRIPTIONS,
  type DraftOption,
  type DraftQuestion,
} from "./exercise-builder-types";

interface BaseEditorProps {
  question: DraftQuestion;
  questionIndex: number;
  readError: (path: string) => string | undefined;
  onUpdateQuestion: (patch: Partial<DraftQuestion>) => void;
}

function moveOption(
  question: DraftQuestion,
  optionIndex: number,
  direction: -1 | 1,
  onUpdateQuestion: (patch: Partial<DraftQuestion>) => void
) {
  const destination = optionIndex + direction;
  if (destination < 0 || destination >= question.options.length) return;
  const options = [...question.options];
  [options[optionIndex], options[destination]] = [options[destination], options[optionIndex]];
  onUpdateQuestion({
    options: options.map((option, sort_order) => ({ ...option, sort_order })),
  });
}

function updateOption(
  question: DraftQuestion,
  optionIndex: number,
  patch: Partial<DraftOption>,
  onUpdateQuestion: (patch: Partial<DraftQuestion>) => void
) {
  onUpdateQuestion({
    options: question.options.map((option, index) => {
      const updated = index === optionIndex ? { ...option, ...patch } : option;
      if (patch.is_correct && question.question_type !== "multiple_select") {
        return { ...updated, is_correct: index === optionIndex };
      }
      return updated;
    }),
  });
}

export function SingleChoiceEditor({
  question,
  questionIndex,
  readError,
  onUpdateQuestion,
}: BaseEditorProps) {
  const prefix = `questions.${questionIndex}`;
  return (
    <div className="mt-3 rounded-lg bg-slate-50 p-3 space-y-2">
      <div className="flex items-center justify-between">
        <p className="text-xs font-bold text-slate-700">Answer options (select exactly one correct answer)</p>
      </div>
      <div className="space-y-2">
        {question.options.map((option, optionIndex) => {
          const textErr = readError(`${prefix}.options.${optionIndex}.option_text`);
          return (
            <div key={option.clientId} className="space-y-1">
              <div className="flex items-center gap-2">
                <input
                  type="radio"
                  name={`correct-${question.clientId}`}
                  aria-label={`Mark option ${optionIndex + 1} correct`}
                  checked={option.is_correct}
                  onChange={(event) =>
                    updateOption(question, optionIndex, { is_correct: event.target.checked }, onUpdateQuestion)
                  }
                />
                <input
                  value={option.option_text}
                  maxLength={2000}
                  placeholder={`Option ${optionIndex + 1}`}
                  aria-label={`Question ${questionIndex + 1} option ${optionIndex + 1}`}
                  aria-invalid={Boolean(textErr)}
                  onChange={(event) =>
                    updateOption(question, optionIndex, { option_text: event.target.value }, onUpdateQuestion)
                  }
                  className={`min-w-0 flex-1 rounded-lg border bg-white p-2 text-xs ${textErr ? "border-rose-400" : "border-slate-300"}`}
                />
                <button
                  type="button"
                  aria-label={`Move option ${optionIndex + 1} up`}
                  disabled={optionIndex === 0}
                  onClick={() => moveOption(question, optionIndex, -1, onUpdateQuestion)}
                  className="rounded border px-2 py-1 text-xs disabled:opacity-40"
                >
                  ↑
                </button>
                <button
                  type="button"
                  aria-label={`Move option ${optionIndex + 1} down`}
                  disabled={optionIndex === question.options.length - 1}
                  onClick={() => moveOption(question, optionIndex, 1, onUpdateQuestion)}
                  className="rounded border px-2 py-1 text-xs disabled:opacity-40"
                >
                  ↓
                </button>
                <button
                  type="button"
                  aria-label={`Remove option ${optionIndex + 1}`}
                  onClick={() =>
                    onUpdateQuestion({
                      options: question.options.filter((_, idx) => idx !== optionIndex),
                    })
                  }
                  className="rounded border px-2 py-1 text-xs"
                >
                  ×
                </button>
              </div>
              {textErr && (
                <p role="alert" className="text-xs text-rose-700 pl-6">
                  Option {optionIndex + 1}: {textErr}
                </p>
              )}
            </div>
          );
        })}
      </div>
      <button
        type="button"
        onClick={() =>
          onUpdateQuestion({
            options: [
              ...question.options,
              { clientId: createClientId("opt"), option_text: "", is_correct: false },
            ],
          })
        }
        className="mt-1 text-xs font-bold text-teal-800"
      >
        + Add option
      </button>
      {readError(`${prefix}.options`) && (
        <p role="alert" className="text-xs text-rose-700">
          {readError(`${prefix}.options`)}
        </p>
      )}
    </div>
  );
}

export function TrueFalseEditor({
  question,
  questionIndex,
  readError,
  onUpdateQuestion,
}: BaseEditorProps) {
  const prefix = `questions.${questionIndex}`;
  return (
    <div className="mt-3 rounded-lg bg-slate-50 p-3 space-y-2">
      <p className="text-xs font-bold text-slate-700">Correct answer (True / False)</p>
      <div className="grid gap-2 sm:grid-cols-2">
        {question.options.slice(0, 2).map((option, optionIndex) => (
          <label
            key={option.clientId}
            className={`flex cursor-pointer items-center gap-2.5 rounded-lg border p-2.5 text-xs font-semibold ${
              option.is_correct
                ? "border-teal-500 bg-teal-50 text-teal-950"
                : "border-slate-200 bg-white text-slate-700"
            }`}
          >
            <input
              type="radio"
              name={`correct-${question.clientId}`}
              aria-label={`Mark option ${optionIndex + 1} correct`}
              checked={option.is_correct}
              onChange={(event) =>
                updateOption(question, optionIndex, { is_correct: event.target.checked }, onUpdateQuestion)
              }
            />
            <span>{option.option_text || (optionIndex === 0 ? "True" : "False")}</span>
          </label>
        ))}
      </div>
      {readError(`${prefix}.options`) && (
        <p role="alert" className="text-xs text-rose-700">
          {readError(`${prefix}.options`)}
        </p>
      )}
    </div>
  );
}

export function MultipleSelectEditor({
  question,
  questionIndex,
  readError,
  onUpdateQuestion,
}: BaseEditorProps) {
  const prefix = `questions.${questionIndex}`;
  const correctCount = question.options.filter((o) => o.is_correct).length;
  return (
    <div className="mt-3 rounded-lg bg-slate-50 p-3 space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs font-bold text-slate-700">
          Answer options (students may select more than one answer)
        </p>
        <span className="rounded-full bg-white border border-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
          {correctCount} correct selected
        </span>
      </div>
      <p className="text-[11px] text-slate-600">
        Requires at least 3 options, at least 2 correct answers, and at least 1 incorrect answer.
      </p>
      <div className="space-y-2">
        {question.options.map((option, optionIndex) => {
          const textErr = readError(`${prefix}.options.${optionIndex}.option_text`);
          return (
            <div key={option.clientId} className="space-y-1">
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  aria-label={`Mark option ${optionIndex + 1} correct`}
                  checked={option.is_correct}
                  onChange={(event) =>
                    updateOption(question, optionIndex, { is_correct: event.target.checked }, onUpdateQuestion)
                  }
                />
                <input
                  value={option.option_text}
                  maxLength={2000}
                  placeholder={`Option ${optionIndex + 1}`}
                  aria-label={`Question ${questionIndex + 1} option ${optionIndex + 1}`}
                  aria-invalid={Boolean(textErr)}
                  onChange={(event) =>
                    updateOption(question, optionIndex, { option_text: event.target.value }, onUpdateQuestion)
                  }
                  className={`min-w-0 flex-1 rounded-lg border bg-white p-2 text-xs ${textErr ? "border-rose-400" : "border-slate-300"}`}
                />
                <button
                  type="button"
                  aria-label={`Move option ${optionIndex + 1} up`}
                  disabled={optionIndex === 0}
                  onClick={() => moveOption(question, optionIndex, -1, onUpdateQuestion)}
                  className="rounded border px-2 py-1 text-xs disabled:opacity-40"
                >
                  ↑
                </button>
                <button
                  type="button"
                  aria-label={`Move option ${optionIndex + 1} down`}
                  disabled={optionIndex === question.options.length - 1}
                  onClick={() => moveOption(question, optionIndex, 1, onUpdateQuestion)}
                  className="rounded border px-2 py-1 text-xs disabled:opacity-40"
                >
                  ↓
                </button>
                <button
                  type="button"
                  aria-label={`Remove option ${optionIndex + 1}`}
                  onClick={() =>
                    onUpdateQuestion({
                      options: question.options.filter((_, idx) => idx !== optionIndex),
                    })
                  }
                  className="rounded border px-2 py-1 text-xs"
                >
                  ×
                </button>
              </div>
              {textErr && (
                <p role="alert" className="text-xs text-rose-700 pl-6">
                  Option {optionIndex + 1}: {textErr}
                </p>
              )}
            </div>
          );
        })}
      </div>
      <button
        type="button"
        onClick={() =>
          onUpdateQuestion({
            options: [
              ...question.options,
              { clientId: createClientId("opt"), option_text: "", is_correct: false },
            ],
          })
        }
        className="mt-1 text-xs font-bold text-teal-800"
      >
        + Add option
      </button>
      {readError(`${prefix}.options`) && (
        <p role="alert" className="text-xs text-rose-700">
          {readError(`${prefix}.options`)}
        </p>
      )}
    </div>
  );
}

export function ShortAnswerEditor({
  question,
  questionIndex,
  readError,
  onUpdateQuestion,
}: BaseEditorProps) {
  const prefix = `questions.${questionIndex}`;
  const shortConfig = (question.answer_config as ShortAnswerConfigPayload | null) ?? {
    accepted_answers: [""],
    normalization: defaultNormalization(),
  };
  const answerClientIds = (shortConfig.accepted_answers ?? []).map(
    (_, idx) => question.acceptedAnswerClientIds?.[idx] ?? `ans-${idx}`
  );

  const previewSamples = ["New York", "new york", "  New   York  "];

  return (
    <div className="mt-3 space-y-4 rounded-lg bg-slate-50 p-3">
      <div>
        <h5 className="text-xs font-bold text-slate-700">Accepted answers</h5>
        <p className="mt-1 text-[11px] text-slate-500">
          Exact matching after the normalization rules below. Up to 20 accepted answers.
        </p>
        <div className="mt-2 space-y-2">
          {shortConfig.accepted_answers.map((answer, answerIndex) => {
            const ansErr = readError(`${prefix}.answer_config.accepted_answers.${answerIndex}`);
            return (
              <div key={answerClientIds[answerIndex]} className="space-y-1">
                <div className="flex gap-2">
                  <input
                    value={answer}
                    maxLength={500}
                    aria-label={`Accepted answer ${answerIndex + 1}`}
                    aria-invalid={Boolean(ansErr)}
                    onChange={(event) => {
                      const next = shortConfig.accepted_answers.map((a, idx) =>
                        idx === answerIndex ? event.target.value : a
                      );
                      onUpdateQuestion({
                        answer_config: { ...shortConfig, accepted_answers: next },
                        acceptedAnswerClientIds: answerClientIds,
                      });
                    }}
                    className={`min-w-0 flex-1 rounded-lg border bg-white p-2 text-xs ${ansErr ? "border-rose-400" : "border-slate-300"}`}
                  />
                  <button
                    type="button"
                    aria-label={`Remove accepted answer ${answerIndex + 1}`}
                    disabled={shortConfig.accepted_answers.length <= 1}
                    onClick={() => {
                      onUpdateQuestion({
                        answer_config: {
                          ...shortConfig,
                          accepted_answers: shortConfig.accepted_answers.filter((_, idx) => idx !== answerIndex),
                        },
                        acceptedAnswerClientIds: answerClientIds.filter((_, idx) => idx !== answerIndex),
                      });
                    }}
                    className="rounded-lg border px-2 text-xs disabled:opacity-40"
                  >
                    Remove
                  </button>
                </div>
                {ansErr && (
                  <p role="alert" className="text-xs text-rose-700">
                    {ansErr}
                  </p>
                )}
              </div>
            );
          })}
        </div>
        <button
          type="button"
          disabled={shortConfig.accepted_answers.length >= 20}
          onClick={() =>
            onUpdateQuestion({
              answer_config: {
                ...shortConfig,
                accepted_answers: [...shortConfig.accepted_answers, ""],
              },
              acceptedAnswerClientIds: [...answerClientIds, createClientId("ans")],
            })
          }
          className="mt-2 text-xs font-bold text-teal-800 disabled:opacity-40"
        >
          + Add accepted answer
        </button>
        {readError(`${prefix}.answer_config.accepted_answers`) && (
          <p role="alert" className="mt-1 text-xs text-rose-700">
            {readError(`${prefix}.answer_config.accepted_answers`)}
          </p>
        )}
      </div>

      <fieldset className="space-y-2 border-t border-slate-200 pt-3">
        <legend className="text-xs font-bold text-slate-700">Matching & normalization rules</legend>
        <div className="grid gap-2 sm:grid-cols-2">
          {NORMALIZATION_RULE_DESCRIPTIONS.map(({ key, label, description }) => (
            <label
              key={key}
              className="flex items-start gap-2 rounded-lg border border-slate-200 bg-white p-2 text-[11px] text-slate-700"
            >
              <input
                type="checkbox"
                checked={shortConfig.normalization[key] ?? true}
                onChange={(event) =>
                  onUpdateQuestion({
                    answer_config: {
                      ...shortConfig,
                      normalization: { ...shortConfig.normalization, [key]: event.target.checked },
                    },
                  })
                }
                className="mt-0.5"
              />
              <span>
                <span className="block font-semibold text-slate-800">{label}</span>
                <span className="block text-[10px] text-slate-500">{description}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>

      <div className="rounded-lg border border-slate-200 bg-white p-2.5 text-[11px] text-slate-600" aria-live="polite">
        <p className="font-bold text-slate-700">Live normalization preview</p>
        <div className="mt-1.5 grid gap-1 sm:grid-cols-3">
          {previewSamples.map((sample) => (
            <div key={sample} className="rounded bg-slate-50 px-2 py-1 font-mono text-[10px]">
              <span className="text-slate-500">{JSON.stringify(sample)}</span>
              {" → "}
              <span className="font-bold text-teal-900">
                {JSON.stringify(normalizeShortAnswer(sample, shortConfig.normalization))}
              </span>
            </div>
          ))}
        </div>
        {shortConfig.accepted_answers[0]?.trim() && (
          <p className="mt-1.5 text-[10px] text-slate-500">
            First accepted answer {JSON.stringify(shortConfig.accepted_answers[0])} normalizes to{" "}
            <span className="font-mono font-bold text-teal-900">
              {JSON.stringify(normalizeShortAnswer(shortConfig.accepted_answers[0], shortConfig.normalization))}
            </span>
            .
          </p>
        )}
      </div>
    </div>
  );
}

export function FillInTheBlanksEditor({
  question,
  questionIndex,
  readError,
  onUpdateQuestion,
}: BaseEditorProps) {
  const prefix = `questions.${questionIndex}`;
  const promptRef = useRef<HTMLTextAreaElement | null>(null);
  const [openNormByBlank, setOpenNormByBlank] = useState<Record<string, boolean>>({});

  const blanksConfig = (question.answer_config as BlankAnswerConfigPayload | null) ?? { blanks: [] };
  const blanks = blanksConfig.blanks ?? [];
  const { blankClientIds, blankAnswerClientIds } = ensureBlankClientIds(question);

  const commitBlanks = (
    nextBlanks: BlankDefinitionPayload[],
    nextBlankClientIds: string[],
    nextBlankAnswerClientIds: Record<number, string[]>,
    nextPrompt?: string
  ) => {
    onUpdateQuestion({
      ...(nextPrompt !== undefined ? { prompt: nextPrompt } : {}),
      points: sumBlankPoints(nextBlanks),
      answer_config: { blanks: nextBlanks },
      blankClientIds: nextBlankClientIds,
      blankAnswerClientIds: nextBlankAnswerClientIds,
    });
  };

  const handleInsertOrConvertSelection = () => {
    const el = promptRef.current;
    const start = el ? el.selectionStart : question.prompt.length;
    const end = el ? el.selectionEnd : question.prompt.length;
    const { prompt: nextPrompt, blank, cursorPosition } = convertPromptSelectionToBlank(
      question.prompt,
      start,
      end,
      blanks
    );
    const nextBlanks = [...blanks, blank];
    const nextBlankClientIds = [...blankClientIds, createClientId("blk")];
    const nextBlankAnswerClientIds = {
      ...blankAnswerClientIds,
      [nextBlanks.length - 1]: [createClientId("ans")],
    };
    commitBlanks(nextBlanks, nextBlankClientIds, nextBlankAnswerClientIds, nextPrompt);
    window.requestAnimationFrame(() => {
      if (promptRef.current) {
        promptRef.current.focus();
        promptRef.current.setSelectionRange(cursorPosition, cursorPosition);
      }
    });
  };

  const handleRenameBlankId = (blankIndex: number, rawNewId: string) => {
    const oldId = blanks[blankIndex]?.id ?? "";
    const nextBlanks = blanks.map((b, idx) => (idx === blankIndex ? { ...b, id: rawNewId } : b));
    const trimmedOld = oldId.trim();
    const trimmedNew = rawNewId.trim();
    const nextPrompt =
      trimmedOld && trimmedNew && /^[a-zA-Z0-9_\-]+$/.test(trimmedNew)
        ? syncBlankIdRenameInPrompt(question.prompt, trimmedOld, trimmedNew)
        : question.prompt;
    commitBlanks(nextBlanks, blankClientIds, blankAnswerClientIds, nextPrompt);
  };

  const handleDeleteBlank = (blankIndex: number) => {
    const target = blanks[blankIndex];
    const fallback = target?.accepted_answers?.[0]?.trim() ?? "";
    const nextPrompt = removeBlankFromPrompt(question.prompt, target?.id ?? "", fallback);
    const nextBlanks = blanks.filter((_, idx) => idx !== blankIndex);
    const nextBlankClientIds = blankClientIds.filter((_, idx) => idx !== blankIndex);
    const nextBlankAnswerClientIds: Record<number, string[]> = {};
    nextBlanks.forEach((_, newIdx) => {
      const oldIdx = newIdx >= blankIndex ? newIdx + 1 : newIdx;
      nextBlankAnswerClientIds[newIdx] = blankAnswerClientIds[oldIdx] ?? [createClientId("ans")];
    });
    commitBlanks(nextBlanks, nextBlankClientIds, nextBlankAnswerClientIds, nextPrompt);
  };

  const updateBlank = (blankIndex: number, patch: Partial<BlankDefinitionPayload>) => {
    const nextBlanks = blanks.map((b, idx) => (idx === blankIndex ? { ...b, ...patch } : b));
    commitBlanks(nextBlanks, blankClientIds, blankAnswerClientIds);
  };

  const promptErr = readError(`${prefix}.prompt`);

  return (
    <div className="mt-3 space-y-4 rounded-lg bg-slate-50 p-3">
      <div className="space-y-2">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <label htmlFor={`fitb-prompt-${question.clientId}`} className="text-xs font-bold text-slate-700">
            Prompt with inline blanks
          </label>
          <button
            type="button"
            onClick={handleInsertOrConvertSelection}
            className="rounded-lg border border-teal-300 bg-white px-2.5 py-1 text-xs font-bold text-teal-800 hover:bg-teal-50"
          >
            + Convert selection / Insert blank
          </button>
        </div>
        <p className="text-[11px] text-slate-500">
          Select a word in the prompt and click &quot;Convert selection / Insert blank&quot; to create a blank automatically, or use <code className="font-mono">[blank_id]</code> placeholders.
        </p>
        <textarea
          ref={promptRef}
          id={`fitb-prompt-${question.clientId}`}
          aria-label="Prompt"
          value={question.prompt}
          onChange={(event) => onUpdateQuestion({ prompt: event.target.value })}
          rows={3}
          aria-invalid={Boolean(promptErr)}
          className={`w-full rounded-lg border bg-white p-2.5 text-xs ${promptErr ? "border-rose-400" : "border-slate-300"}`}
        />
        {promptErr && (
          <p role="alert" className="text-xs text-rose-700">
            {promptErr}
          </p>
        )}
      </div>

      {/* Inline Question Preview */}
      <div className="rounded-lg border border-slate-200 bg-white p-3">
        <p className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Inline prompt preview</p>
        <div className="mt-1.5 text-sm font-semibold leading-relaxed text-[#102b4c]">
          {(question.prompt || "Enter prompt text above").split(/(\[[a-zA-Z0-9_\-]+\])/g).map((part, idx) => {
            const match = /^\[([a-zA-Z0-9_\-]+)\]$/.exec(part);
            if (!match) return <span key={idx}>{part}</span>;
            const id = match[1];
            const defined = blanks.some((b) => b.id === id);
            return (
              <span
                key={idx}
                className={`mx-1 inline-flex items-center rounded border px-2 py-0.5 font-mono text-xs ${
                  defined
                    ? "border-teal-300 bg-teal-50 text-teal-900"
                    : "border-rose-300 bg-rose-50 text-rose-800"
                }`}
              >
                {id}
              </span>
            );
          })}
        </div>
      </div>

      {/* Blanks Configuration */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h5 className="text-xs font-bold text-slate-700">
            Blanks ({blanks.length}) · Total {sumBlankPoints(blanks)} pts
          </h5>
          <button
            type="button"
            onClick={handleInsertOrConvertSelection}
            className="text-xs font-bold text-teal-800"
          >
            + Add blank
          </button>
        </div>

        {blanks.map((blank, blankIndex) => {
          const bKey = blankClientIds[blankIndex] ?? `blk-${blankIndex}`;
          const bPrefix = `${prefix}.answer_config.blanks.${blankIndex}`;
          const idErr = readError(`${bPrefix}.id`);
          const ptsErr = readError(`${bPrefix}.points`);
          const ansGroupErr = readError(`${bPrefix}.accepted_answers`);
          const ansClientIds = blankAnswerClientIds[blankIndex] ?? [];
          const norm: ShortAnswerNormalizationPayload = {
            ...defaultNormalization(),
            ...(blank.normalization ?? {}),
          };
          const normOpen = Boolean(openNormByBlank[bKey]);

          return (
            <div key={bKey} className="space-y-2.5 rounded-lg border border-slate-200 bg-white p-3">
              <div className="flex flex-wrap items-center gap-2">
                <label className="text-xs font-semibold text-slate-700">Blank ID</label>
                <input
                  value={blank.id ?? ""}
                  maxLength={50}
                  aria-label={`Blank ${blankIndex + 1} ID`}
                  aria-invalid={Boolean(idErr)}
                  onChange={(event) => handleRenameBlankId(blankIndex, event.target.value)}
                  className={`min-w-0 flex-1 rounded-lg border bg-white p-2 font-mono text-xs ${idErr ? "border-rose-400" : "border-slate-300"}`}
                />
                <label className="text-xs font-semibold text-slate-700">Points</label>
                <input
                  type="number"
                  min={0.01}
                  step={0.25}
                  value={blank.points ?? 1}
                  aria-label={`Blank ${blankIndex + 1} points`}
                  aria-invalid={Boolean(ptsErr)}
                  onChange={(event) => updateBlank(blankIndex, { points: Number(event.target.value) })}
                  className={`w-20 rounded-lg border bg-white p-2 text-xs ${ptsErr ? "border-rose-400" : "border-slate-300"}`}
                />
                <button
                  type="button"
                  onClick={() => handleDeleteBlank(blankIndex)}
                  className="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700"
                >
                  Remove
                </button>
              </div>
              {idErr && <p role="alert" className="text-xs text-rose-700">{idErr}</p>}
              {ptsErr && <p role="alert" className="text-xs text-rose-700">{ptsErr}</p>}

              <div>
                <p className="text-[11px] font-semibold text-slate-700">Accepted answers</p>
                <div className="mt-1 space-y-1.5">
                  {(blank.accepted_answers ?? []).map((ans, ansIdx) => {
                    const ansErr = readError(`${bPrefix}.accepted_answers.${ansIdx}`);
                    return (
                      <div key={ansClientIds[ansIdx] ?? `ba-${ansIdx}`} className="flex gap-2">
                        <input
                          value={ans}
                          maxLength={500}
                          aria-label={`Blank ${blankIndex + 1} accepted answer ${ansIdx + 1}`}
                          aria-invalid={Boolean(ansErr)}
                          onChange={(event) => {
                            const nextAccepted = [...(blank.accepted_answers ?? [])];
                            nextAccepted[ansIdx] = event.target.value;
                            updateBlank(blankIndex, { accepted_answers: nextAccepted });
                          }}
                          className={`min-w-0 flex-1 rounded-lg border bg-white p-2 text-xs ${ansErr ? "border-rose-400" : "border-slate-300"}`}
                        />
                        <button
                          type="button"
                          disabled={(blank.accepted_answers ?? []).length <= 1}
                          onClick={() => {
                            const nextAccepted = (blank.accepted_answers ?? []).filter((_, idx) => idx !== ansIdx);
                            const nextAnsIds = ansClientIds.filter((_, idx) => idx !== ansIdx);
                            commitBlanks(
                              blanks.map((b, idx) =>
                                idx === blankIndex ? { ...b, accepted_answers: nextAccepted } : b
                              ),
                              blankClientIds,
                              { ...blankAnswerClientIds, [blankIndex]: nextAnsIds }
                            );
                          }}
                          className="rounded border px-2 text-xs disabled:opacity-40"
                        >
                          Remove
                        </button>
                      </div>
                    );
                  })}
                </div>
                <button
                  type="button"
                  disabled={(blank.accepted_answers ?? []).length >= 20}
                  onClick={() => {
                    const nextAccepted = [...(blank.accepted_answers ?? []), ""];
                    const nextAnsIds = [...ansClientIds, createClientId("ans")];
                    commitBlanks(
                      blanks.map((b, idx) =>
                        idx === blankIndex ? { ...b, accepted_answers: nextAccepted } : b
                      ),
                      blankClientIds,
                      { ...blankAnswerClientIds, [blankIndex]: nextAnsIds }
                    );
                  }}
                  className="mt-1.5 text-xs font-bold text-teal-800 disabled:opacity-40"
                >
                  + Add answer
                </button>
                {ansGroupErr && (
                  <p role="alert" className="mt-1 text-xs text-rose-700">
                    {ansGroupErr}
                  </p>
                )}
              </div>

              <div className="border-t border-slate-100 pt-2">
                <button
                  type="button"
                  onClick={() => setOpenNormByBlank((prev) => ({ ...prev, [bKey]: !prev[bKey] }))}
                  className="text-[11px] font-semibold text-slate-600 hover:text-slate-900"
                >
                  {normOpen ? "▼ Hide matching rules" : "▶ Matching rules"}
                </button>
                {normOpen && (
                  <div className="mt-2 grid gap-1.5 sm:grid-cols-2">
                    {NORMALIZATION_RULE_DESCRIPTIONS.map(({ key, label }) => (
                      <label key={key} className="flex items-center gap-2 text-[11px] text-slate-700">
                        <input
                          type="checkbox"
                          checked={norm[key]}
                          onChange={(event) =>
                            updateBlank(blankIndex, {
                              normalization: { ...norm, [key]: event.target.checked },
                            })
                          }
                        />
                        {label}
                      </label>
                    ))}
                  </div>
                )}
              </div>
            </div>
          );
        })}

        {readError(`${prefix}.answer_config.blanks`) && (
          <p role="alert" className="text-xs text-rose-700">
            {readError(`${prefix}.answer_config.blanks`)}
          </p>
        )}
      </div>
    </div>
  );
}
