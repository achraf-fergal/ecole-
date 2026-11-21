"use client";

import Link from "next/link";
import React, { useCallback, useEffect, useState } from "react";
import {
  getAdminLessonContent,
  createAdminDraft,
  updateAdminDraftMetadata,
  addAdminTextBlock,
  addAdminMediaBlock,
  addAdminExerciseBlock,
  createAndInsertAdminExerciseBlock,
  updateAdminBlock,
  deleteAdminBlock,
  reorderAdminBlocks,
  publishAdminLesson,
  getAdminLessonAssets,
  uploadAdminLessonAsset,
  type AdminLessonContent,
  type AdminLessonRevision,
} from "@/lib/admin-learning";
import type {
  TipTapDocument,
  LearningLessonAsset,
} from "@/lib/learning";
import LessonTextBlock from "@/components/learning/lesson-text-block";
import LessonMediaBlock from "@/components/learning/lesson-media-block";
import LessonExerciseBlock from "@/components/learning/lesson-exercise-block";

interface UnifiedLessonEditorProps {
  centerSlug: string;
  courseSlug: string;
  lessonId: number;
  returnHref?: string;
  onSwitchToPreview?: () => void;
}

export default function UnifiedLessonEditor({
  centerSlug,
  courseSlug,
  lessonId,
  returnHref = `/admin/learning/centers/${centerSlug}/courses`,
  onSwitchToPreview,
}: UnifiedLessonEditorProps) {
  const [content, setContent] = useState<AdminLessonContent | null>(null);
  const [assets, setAssets] = useState<LearningLessonAsset[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Metadata editing state
  const [isEditingMetadata, setIsEditingMetadata] = useState(false);
  const [metaTitle, setMetaTitle] = useState("");
  const [metaDescription, setMetaDescription] = useState("");
  const [metaLocale, setMetaLocale] = useState("en");
  const [metaDuration, setMetaDuration] = useState<number | "">("");

  // Block creation states
  const [composerMode, setComposerMode] = useState<"none" | "text" | "media" | "exercise">("none");

  // Rich text composer
  const [textInput, setTextInput] = useState("");
  const [editingBlockId, setEditingBlockId] = useState<number | null>(null);
  const [editingText, setEditingText] = useState("");

  // Media selection/upload
  const [selectedAssetId, setSelectedAssetId] = useState<number | "">("");
  const [isUploadingAsset, setIsUploadingAsset] = useState(false);
  const [uploadTitle, setUploadTitle] = useState("");
  const [uploadAltText, setUploadAltText] = useState("");
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploadType, setUploadType] = useState<"image" | "file">("image");

  // Exercise selection & creation
  const [selectedExerciseId, setSelectedExerciseId] = useState<number | "">("");
  const [isCreatingExercise, setIsCreatingExercise] = useState(false);
  const [newExerciseTitle, setNewExerciseTitle] = useState("");
  const [newExerciseInstructions, setNewExerciseInstructions] = useState("");
  const [newExercisePassingScore, setNewExercisePassingScore] = useState<number>(70);
  const [newExerciseMaxAttempts, setNewExerciseMaxAttempts] = useState<number | "">("");
  const [newExerciseShowAnswers, setNewExerciseShowAnswers] = useState<boolean>(true);
  const [newExerciseQuestions, setNewExerciseQuestions] = useState<
    Array<{
      prompt: string;
      points: number;
      question_type: "single_choice" | "true_false";
      explanation: string;
      options: Array<{
        option_text: string;
        is_correct: boolean;
      }>;
    }>
  >([
    {
      prompt: "",
      points: 1,
      question_type: "single_choice",
      explanation: "",
      options: [
        { option_text: "", is_correct: true },
        { option_text: "", is_correct: false },
      ],
    },
  ]);

  const routeParams = { centerSlug, courseSlug, lessonId };

  const loadData = useCallback(async () => {
    setIsLoading(true);
    setError(null);

    try {
      const [contentData, assetsData] = await Promise.all([
        getAdminLessonContent(centerSlug, courseSlug, lessonId),
        getAdminLessonAssets(centerSlug, courseSlug, lessonId).catch(() => []),
      ]);

      setContent(contentData);
      setAssets(assetsData);

      const activeRev = contentData.draft_revision ?? contentData.published_revision;
      if (activeRev) {
        setMetaTitle(activeRev.title);
        setMetaDescription(activeRev.description ?? "");
        setMetaLocale(activeRev.content_locale);
        setMetaDuration(activeRev.duration_minutes ?? "");
      } else {
        setMetaTitle(contentData.lesson.title);
        setMetaDescription(contentData.lesson.description ?? "");
        setMetaDuration("");
      }
      return true;
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to load lesson editor.");
      return false;
    } finally {
      setIsLoading(false);
    }
  }, [centerSlug, courseSlug, lessonId]);

  useEffect(() => {
    queueMicrotask(() => {
      void loadData();
    });
  }, [loadData]);

  // Flash messages helper
  const showSuccess = (msg: string) => {
    setSuccessMessage(msg);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  // Ensure draft revision exists
  const getOrCreateDraft = async (): Promise<AdminLessonRevision> => {
    if (content?.draft_revision) {
      return content.draft_revision;
    }

    const draft = await createAdminDraft(centerSlug, courseSlug, lessonId);
    await loadData();
    return draft;
  };

  // Convert plain multiline text to a valid TipTap JSON doc
  const textToTipTap = (text: string): TipTapDocument => {
    const paragraphs = text
      .split(/\n\n+/)
      .map((p) => p.trim())
      .filter(Boolean);

    if (paragraphs.length === 0) {
      return {
        type: "doc",
        content: [
          {
            type: "paragraph",
            content: [{ type: "text", text }],
          },
        ],
      };
    }

    return {
      type: "doc",
      content: paragraphs.map((para) => {
        if (para.startsWith("# ")) {
          return {
            type: "heading",
            attrs: { level: 1 },
            content: [{ type: "text", text: para.replace(/^#\s+/, "") }],
          };
        }
        if (para.startsWith("## ")) {
          return {
            type: "heading",
            attrs: { level: 2 },
            content: [{ type: "text", text: para.replace(/^##\s+/, "") }],
          };
        }
        if (para.startsWith("### ")) {
          return {
            type: "heading",
            attrs: { level: 3 },
            content: [{ type: "text", text: para.replace(/^###\s+/, "") }],
          };
        }
        return {
          type: "paragraph",
          content: [{ type: "text", text: para }],
        };
      }),
    };
  };

  // TipTap doc to plain text
  const tipTapToText = (doc: TipTapDocument | null): string => {
    if (!doc || !doc.content) return "";
    return doc.content
      .map((node) => {
        const text = node.content?.map((c) => c.text ?? "").join("") ?? "";
        if (node.type === "heading") {
          const level = Number(node.attrs?.level) || 2;
          return `${"#".repeat(level)} ${text}`;
        }
        return text;
      })
      .join("\n\n");
  };

  // Handle Draft Creation
  const handleCreateDraft = async () => {
    setIsSubmitting(true);
    setError(null);
    try {
      await createAdminDraft(centerSlug, courseSlug, lessonId);
      await loadData();
      showSuccess("New draft revision initialized.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to create draft.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Metadata Save
  const handleSaveMetadata = async () => {
    setIsSubmitting(true);
    setError(null);
    try {
      const draft = await getOrCreateDraft();
      await updateAdminDraftMetadata(centerSlug, courseSlug, lessonId, draft.id, {
        title: metaTitle.trim(),
        description: metaDescription.trim() || null,
        content_locale: metaLocale.trim() || "en",
        duration_minutes: metaDuration === "" ? null : Number(metaDuration),
      });
      setIsEditingMetadata(false);
      await loadData();
      showSuccess("Lesson metadata updated.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to update metadata.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Add Text Block
  const handleAddText = async () => {
    if (!textInput.trim()) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const draft = await getOrCreateDraft();
      const doc = textToTipTap(textInput);
      await addAdminTextBlock(centerSlug, courseSlug, lessonId, draft.id, doc);
      setTextInput("");
      setComposerMode("none");
      await loadData();
      showSuccess("Text block added.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to add text block.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Edit Text Block
  const handleUpdateTextBlock = async (blockId: number) => {
    if (!content?.draft_revision) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const doc = textToTipTap(editingText);
      await updateAdminBlock(centerSlug, courseSlug, lessonId, content.draft_revision.id, blockId, {
        content_json: doc,
      });
      setEditingBlockId(null);
      await loadData();
      showSuccess("Text block updated.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to update text block.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Asset Upload
  const handleUploadAsset = async () => {
    if (!uploadFile) return;

    setIsUploadingAsset(true);
    setError(null);
    try {
      const fd = new FormData();
      fd.append("file", uploadFile);
      fd.append("asset_type", uploadType);
      if (uploadTitle.trim()) fd.append("title", uploadTitle.trim());
      if (uploadType === "image" && uploadAltText.trim()) {
        fd.append("alt_text", uploadAltText.trim());
      } else if (uploadType === "image") {
        fd.append("alt_text", uploadFile.name);
      }

      const newAsset = await uploadAdminLessonAsset(centerSlug, courseSlug, lessonId, fd);
      const updatedAssets = await getAdminLessonAssets(centerSlug, courseSlug, lessonId);
      setAssets(updatedAssets);
      setSelectedAssetId(newAsset.id);
      setUploadFile(null);
      setUploadTitle("");
      setUploadAltText("");
      showSuccess("Asset uploaded successfully. You can now insert it into the lesson.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to upload asset.");
    } finally {
      setIsUploadingAsset(false);
    }
  };

  // Handle Add Media Block
  const handleAddMedia = async () => {
    if (!selectedAssetId) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const draft = await getOrCreateDraft();
      await addAdminMediaBlock(centerSlug, courseSlug, lessonId, draft.id, Number(selectedAssetId));
      setSelectedAssetId("");
      setComposerMode("none");
      await loadData();
      showSuccess("Media block added.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to add media block.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Add Exercise Block
  const handleAddExercise = async () => {
    if (!selectedExerciseId) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const draft = await getOrCreateDraft();
      await addAdminExerciseBlock(centerSlug, courseSlug, lessonId, draft.id, Number(selectedExerciseId));
      setSelectedExerciseId("");
      setComposerMode("none");
      await loadData();
      showSuccess("Exercise block added.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to add exercise block.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Question manipulation helpers for Exercise Creator
  const handleAddQuestion = () => {
    setNewExerciseQuestions((prev) => [
      ...prev,
      {
        prompt: "",
        points: 1,
        question_type: "single_choice",
        explanation: "",
        options: [
          { option_text: "", is_correct: true },
          { option_text: "", is_correct: false },
        ],
      },
    ]);
  };

  const handleRemoveQuestion = (index: number) => {
    if (newExerciseQuestions.length <= 1) return;
    setNewExerciseQuestions((prev) => prev.filter((_, i) => i !== index));
  };

  const handleUpdateQuestion = (index: number, field: string, value: unknown) => {
    setNewExerciseQuestions((prev) =>
      prev.map((q, i) => (i === index ? { ...q, [field]: value } : q))
    );
  };

  const handleAddOption = (questionIndex: number) => {
    setNewExerciseQuestions((prev) =>
      prev.map((q, i) => {
        if (i !== questionIndex) return q;
        return {
          ...q,
          options: [...q.options, { option_text: "", is_correct: false }],
        };
      })
    );
  };

  const handleRemoveOption = (questionIndex: number, optionIndex: number) => {
    setNewExerciseQuestions((prev) =>
      prev.map((q, i) => {
        if (i !== questionIndex) return q;
        if (q.options.length <= 2) return q;
        return {
          ...q,
          options: q.options.filter((_, optIdx) => optIdx !== optionIndex),
        };
      })
    );
  };

  const handleUpdateOption = (
    questionIndex: number,
    optionIndex: number,
    field: "option_text" | "is_correct",
    value: string | boolean
  ) => {
    setNewExerciseQuestions((prev) =>
      prev.map((q, i) => {
        if (i !== questionIndex) return q;
        return {
          ...q,
          options: q.options.map((opt, optIdx) => {
            if (field === "is_correct") {
              return {
                ...opt,
                is_correct: optIdx === optionIndex ? Boolean(value) : false,
              };
            }
            if (optIdx === optionIndex) {
              return { ...opt, option_text: String(value) };
            }
            return opt;
          }),
        };
      })
    );
  };

  const handleCreateAndInsertExercise = async () => {
    if (!newExerciseTitle.trim()) {
      setError("Please provide an exercise title.");
      return;
    }

    for (let i = 0; i < newExerciseQuestions.length; i++) {
      const q = newExerciseQuestions[i];
      if (!q.prompt.trim()) {
        setError(`Question ${i + 1} prompt cannot be empty.`);
        return;
      }
      if (q.options.length < 2) {
        setError(`Question ${i + 1} must have at least 2 options.`);
        return;
      }
      const hasCorrect = q.options.some((opt) => opt.is_correct && opt.option_text.trim());
      if (!hasCorrect) {
        setError(`Question ${i + 1} must have at least one marked correct option with text.`);
        return;
      }
      for (let j = 0; j < q.options.length; j++) {
        if (!q.options[j].option_text.trim()) {
          setError(`Option ${j + 1} of Question ${i + 1} cannot be empty.`);
          return;
        }
      }
    }

    setIsSubmitting(true);
    setError(null);

    try {
      const draft = await getOrCreateDraft();

      await createAndInsertAdminExerciseBlock(centerSlug, courseSlug, lessonId, draft.id, {
        title: newExerciseTitle.trim(),
        instructions: newExerciseInstructions.trim() || null,
        passing_score: newExercisePassingScore,
        max_attempts: newExerciseMaxAttempts === "" ? null : Number(newExerciseMaxAttempts),
        show_correct_answers_after_submission: newExerciseShowAnswers,
        questions: newExerciseQuestions.map((q, qIdx) => ({
          prompt: q.prompt.trim(),
          points: Number(q.points) || 1,
          question_type: q.question_type,
          explanation: q.explanation.trim() || null,
          sort_order: qIdx + 1,
          options: q.options.map((opt, optIdx) => ({
            option_text: opt.option_text.trim(),
            is_correct: opt.is_correct,
            sort_order: optIdx + 1,
          })),
        })),
      });

      setIsCreatingExercise(false);
      setNewExerciseTitle("");
      setNewExerciseInstructions("");
      setNewExerciseQuestions([
        {
          prompt: "",
          points: 1,
          question_type: "single_choice",
          explanation: "",
          options: [
            { option_text: "", is_correct: true },
            { option_text: "", is_correct: false },
          ],
        },
      ]);
      setComposerMode("none");
      const refreshed = await loadData();
      if (refreshed) {
        showSuccess("New exercise created, published, and added to the revision timeline!");
      } else {
        setError("Exercise was created and added, but the lesson could not refresh. Reload the lesson to view it.");
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to create exercise.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Delete Block
  const handleDeleteBlock = async (blockId: number) => {
    if (!content?.draft_revision) return;
    if (!confirm("Are you sure you want to remove this block from the draft?")) return;

    setIsSubmitting(true);
    setError(null);
    try {
      await deleteAdminBlock(centerSlug, courseSlug, lessonId, content.draft_revision.id, blockId);
      await loadData();
      showSuccess("Block removed.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to delete block.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Move Block Up / Down
  const handleMoveBlock = async (blockIndex: number, direction: "up" | "down") => {
    if (!content?.draft_revision) return;
    const blocks = [...content.draft_revision.blocks];
    const targetIndex = direction === "up" ? blockIndex - 1 : blockIndex + 1;

    if (targetIndex < 0 || targetIndex >= blocks.length) return;

    // Swap elements
    const [moved] = blocks.splice(blockIndex, 1);
    blocks.splice(targetIndex, 0, moved);

    const reorderedIds = blocks.map((b) => b.id);

    setIsSubmitting(true);
    setError(null);
    try {
      await reorderAdminBlocks(centerSlug, courseSlug, lessonId, content.draft_revision.id, reorderedIds);
      await loadData();
      showSuccess("Block order updated.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to reorder blocks.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Publication
  const handlePublish = async () => {
    if (!content?.draft_revision) {
      setError("No draft revision to publish.");
      return;
    }

    if (content.draft_revision.blocks.length === 0) {
      setError("A lesson revision must contain at least one content block before publication.");
      return;
    }

    if (
      !confirm(
        `Publish Version ${content.draft_revision.version_number}? This will make it active for all enrolled students.`
      )
    ) {
      return;
    }

    setIsSubmitting(true);
    setError(null);
    try {
      await publishAdminLesson(centerSlug, courseSlug, lessonId, content.draft_revision.id);
      await loadData();
      showSuccess("Lesson published successfully! Students now see this version.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Publication failed.");
    } finally {
      setIsSubmitting(false);
    }
  };

  if (isLoading) {
    return (
      <div className="flex min-h-[400px] items-center justify-center p-8">
        <div className="text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-slate-200 border-t-[#0e8988]" />
          <p className="mt-3 text-xs font-bold text-slate-500">Loading lesson editor...</p>
        </div>
      </div>
    );
  }

  if (!content) {
    return (
      <div className="p-8 text-center">
        <p className="text-sm font-bold text-red-600">Failed to load lesson.</p>
        <button
          type="button"
          onClick={() => void loadData()}
          className="mt-4 rounded-xl bg-[#092443] px-4 py-2 text-xs font-bold text-white"
        >
          Retry
        </button>
      </div>
    );
  }

  const draft = content.draft_revision;
  const published = content.published_revision;
  const activeBlocks = draft?.blocks ?? published?.blocks ?? [];
  const hasDraft = Boolean(draft);

  return (
    <div className="mx-auto max-w-5xl space-y-6 pb-20">
      {/* Top Action Bar */}
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-5">
        <div className="flex items-center gap-3">
          <Link
            href={returnHref}
            className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-[#102b4c] transition hover:bg-slate-50"
          >
            ←
          </Link>
          <div>
            <div className="flex items-center gap-2">
              <span className="text-[10px] font-extrabold uppercase tracking-wider text-[#0e8988]">
                {content.course.title}
              </span>
              <span className="text-slate-300">/</span>
              <span className="text-xs font-bold text-slate-500">Lesson #{content.lesson.id}</span>
            </div>
            <h1 className="text-xl font-black text-[#102b4c]">{content.lesson.title}</h1>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {onSwitchToPreview && (
            <button
              type="button"
              onClick={onSwitchToPreview}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-[#102b4c] shadow-xs transition hover:bg-slate-50"
            >
              👁 View Preview
            </button>
          )}

          {!draft ? (
            <button
              type="button"
              disabled={isSubmitting}
              onClick={() => void handleCreateDraft()}
              className="rounded-xl bg-[#0e8988] px-5 py-2 text-xs font-extrabold text-white shadow-xs transition hover:bg-[#087b83] disabled:opacity-50"
            >
              + Create New Draft
            </button>
          ) : (
            <button
              type="button"
              disabled={isSubmitting || draft.blocks.length === 0}
              onClick={() => void handlePublish()}
              className="rounded-xl bg-emerald-600 px-5 py-2 text-xs font-extrabold text-white shadow-xs transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              ✓ Publish Version {draft.version_number}
            </button>
          )}
        </div>
      </div>

      {/* Alerts */}
      {error && (
        <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-700">
          {error}
        </div>
      )}
      {successMessage && (
        <div role="status" className="rounded-xl border border-teal-200 bg-teal-50 p-4 text-xs font-bold text-[#087b83]">
          {successMessage}
        </div>
      )}

      {/* Status & Version Header Card */}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-xs font-bold uppercase tracking-wider text-slate-500">
                Lesson Status:
              </span>
              <span
                className={`rounded-full px-2.5 py-0.5 text-[10px] font-extrabold uppercase ${
                  content.lesson.status === "published"
                    ? "bg-emerald-50 text-emerald-700"
                    : "bg-amber-50 text-amber-700"
                }`}
              >
                {content.lesson.status}
              </span>

              {published && (
                <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-extrabold text-slate-700">
                  Published: v{published.version_number}
                </span>
              )}

              {draft ? (
                <span className="rounded-full bg-indigo-50 px-2.5 py-0.5 text-[10px] font-extrabold text-indigo-700">
                  Drafting: v{draft.version_number} (Draft)
                </span>
              ) : (
                <span className="text-xs text-slate-400">No active draft</span>
              )}
            </div>

            <p className="mt-2 text-sm text-slate-600">
              {draft?.description || content.lesson.description || "No description set."}
            </p>

            <div className="flex flex-wrap gap-4 pt-2 text-xs text-slate-500">
              <span>Locale: <strong>{draft?.content_locale ?? "en"}</strong></span>
              <span>
                Duration: <strong>{draft?.duration_minutes ?? 0} mins</strong>
              </span>
              <span>
                Blocks count: <strong>{activeBlocks.length}</strong>
              </span>
            </div>
          </div>

          <div>
            <button
              type="button"
              onClick={() => setIsEditingMetadata(!isEditingMetadata)}
              className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-[#102b4c] transition hover:bg-slate-100"
            >
              {isEditingMetadata ? "Cancel" : "Edit Metadata"}
            </button>
          </div>
        </div>

        {/* Edit Metadata Form */}
        {isEditingMetadata && (
          <div className="mt-5 border-t border-slate-100 pt-5 space-y-4">
            <h4 className="text-xs font-bold uppercase tracking-wider text-[#102b4c]">
              Edit Draft Metadata
            </h4>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-xs font-bold text-slate-700">Title</label>
                <input
                  type="text"
                  value={metaTitle}
                  onChange={(e) => setMetaTitle(e.target.value)}
                  className="mt-1 w-full rounded-xl border border-slate-200 p-2 text-xs text-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700">Content Locale</label>
                <input
                  type="text"
                  value={metaLocale}
                  onChange={(e) => setMetaLocale(e.target.value)}
                  className="mt-1 w-full rounded-xl border border-slate-200 p-2 text-xs text-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700">Duration (Minutes)</label>
                <input
                  type="number"
                  value={metaDuration}
                  onChange={(e) => setMetaDuration(e.target.value === "" ? "" : Number(e.target.value))}
                  className="mt-1 w-full rounded-xl border border-slate-200 p-2 text-xs text-slate-800"
                />
              </div>

              <div className="sm:col-span-2">
                <label className="block text-xs font-bold text-slate-700">Description</label>
                <textarea
                  value={metaDescription}
                  onChange={(e) => setMetaDescription(e.target.value)}
                  rows={2}
                  className="mt-1 w-full rounded-xl border border-slate-200 p-2 text-xs text-slate-800"
                />
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <button
                type="button"
                disabled={isSubmitting}
                onClick={() => void handleSaveMetadata()}
                className="rounded-xl bg-[#0e8988] px-4 py-2 text-xs font-extrabold text-white hover:bg-[#087b83] disabled:opacity-50"
              >
                Save Metadata
              </button>
            </div>
          </div>
        )}
      </section>

      {/* Content Composer Controls */}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-sm font-black text-[#102b4c]">Content Composer</h3>
            <p className="mt-0.5 text-xs text-slate-500">
              Add rich text, media assets, or assessment exercises to this revision.
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => setComposerMode(composerMode === "text" ? "none" : "text")}
              className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                composerMode === "text"
                  ? "bg-[#0e8988] text-white"
                  : "border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100"
              }`}
            >
              + Add Text
            </button>

            <button
              type="button"
              onClick={() => setComposerMode(composerMode === "media" ? "none" : "media")}
              className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                composerMode === "media"
                  ? "bg-[#0e8988] text-white"
                  : "border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100"
              }`}
            >
              + Add Media
            </button>

            <button
              type="button"
              onClick={() => setComposerMode(composerMode === "exercise" ? "none" : "exercise")}
              className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                composerMode === "exercise"
                  ? "bg-[#0e8988] text-white"
                  : "border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100"
              }`}
            >
              + Add Exercise
            </button>
          </div>
        </div>

        {/* Composer: Text Form */}
        {composerMode === "text" && (
          <div className="mt-5 rounded-xl border border-teal-100 bg-teal-50/30 p-4">
            <h4 className="text-xs font-black text-[#102b4c]">Add Rich Text Block</h4>
            <p className="mt-1 text-[11px] text-slate-500">
              Separate paragraphs with empty lines. Use # Heading 1, ## Heading 2, ### Heading 3.
            </p>
            <textarea
              rows={4}
              value={textInput}
              onChange={(e) => setTextInput(e.target.value)}
              placeholder="Enter lesson text or notes here..."
              className="mt-3 w-full rounded-xl border border-slate-200 bg-white p-3 text-xs leading-6 text-slate-800"
            />
            <div className="mt-3 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setComposerMode("none")}
                className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={isSubmitting || !textInput.trim()}
                onClick={() => void handleAddText()}
                className="rounded-xl bg-[#0e8988] px-4 py-1.5 text-xs font-extrabold text-white hover:bg-[#087b83] disabled:opacity-50"
              >
                Insert Text Block
              </button>
            </div>
          </div>
        )}

        {/* Composer: Media Form */}
        {composerMode === "media" && (
          <div className="mt-5 rounded-xl border border-teal-100 bg-teal-50/30 p-4 space-y-4">
            <h4 className="text-xs font-black text-[#102b4c]">Add Media Block</h4>

            {/* Select existing */}
            <div>
              <label className="block text-xs font-bold text-slate-700">Select Existing Asset</label>
              <select
                value={selectedAssetId}
                onChange={(e) => setSelectedAssetId(e.target.value === "" ? "" : Number(e.target.value))}
                className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2.5 text-xs text-slate-800"
              >
                <option value="">-- Choose an uploaded asset --</option>
                {assets.map((a) => (
                  <option key={a.id} value={a.id}>
                    #{a.id} - {a.title || a.original_filename} ({a.asset_type})
                  </option>
                ))}
              </select>
            </div>

            {selectedAssetId !== "" && (
              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  disabled={isSubmitting}
                  onClick={() => void handleAddMedia()}
                  className="rounded-xl bg-[#0e8988] px-4 py-1.5 text-xs font-extrabold text-white hover:bg-[#087b83]"
                >
                  Insert Selected Media
                </button>
              </div>
            )}

            {/* Upload New Asset */}
            <div className="border-t border-slate-200/60 pt-4">
              <h5 className="text-[11px] font-bold uppercase tracking-wider text-slate-600">
                Or Upload a New Resource
              </h5>
              <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-[11px] font-bold text-slate-600">Type</label>
                  <select
                    value={uploadType}
                    onChange={(e) => setUploadType(e.target.value as "image" | "file")}
                    className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                  >
                    <option value="image">Image (JPG, PNG, WebP)</option>
                    <option value="file">Document (PDF, Word, etc.)</option>
                  </select>
                </div>

                <div>
                  <label className="block text-[11px] font-bold text-slate-600">Display Title</label>
                  <input
                    type="text"
                    value={uploadTitle}
                    onChange={(e) => setUploadTitle(e.target.value)}
                    placeholder="e.g. Vocabulary Worksheet"
                    className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                  >
                  </input>
                </div>

                {uploadType === "image" && (
                  <div className="sm:col-span-2">
                    <label className="block text-[11px] font-bold text-slate-600">Alt Text (Accessibility)</label>
                    <input
                      type="text"
                      value={uploadAltText}
                      onChange={(e) => setUploadAltText(e.target.value)}
                      placeholder="Describe what is visible in the image"
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                    />
                  </div>
                )}

                <div className="sm:col-span-2">
                  <label className="block text-[11px] font-bold text-slate-600">Choose File</label>
                  <input
                    type="file"
                    onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                    className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-1 text-xs"
                  />
                </div>
              </div>

              <div className="mt-3 flex justify-end gap-2">
                <button
                  type="button"
                  disabled={isUploadingAsset || !uploadFile}
                  onClick={() => void handleUploadAsset()}
                  className="rounded-xl bg-[#092443] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#123b64] disabled:opacity-50"
                >
                  {isUploadingAsset ? "Uploading..." : "Upload Resource"}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Composer: Exercise Form */}
        {composerMode === "exercise" && (
          <div className="mt-5 rounded-xl border border-teal-100 bg-teal-50/30 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-teal-100/60 pb-3">
              <div>
                <h4 className="text-xs font-black text-[#102b4c]">Add Assessment Exercise</h4>
                <p className="mt-0.5 text-[11px] text-slate-500">
                  Select an existing exercise or create and author a new one directly in this lesson.
                </p>
              </div>
              <div className="flex rounded-lg border border-teal-200 bg-white p-0.5 text-[11px]">
                <button
                  type="button"
                  onClick={() => setIsCreatingExercise(false)}
                  className={`rounded-md px-2.5 py-1 font-bold transition ${
                    !isCreatingExercise
                      ? "bg-[#0e8988] text-white"
                      : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  Choose Existing
                </button>
                <button
                  type="button"
                  onClick={() => setIsCreatingExercise(true)}
                  className={`rounded-md px-2.5 py-1 font-bold transition ${
                    isCreatingExercise
                      ? "bg-[#0e8988] text-white"
                      : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  + Author New Exercise
                </button>
              </div>
            </div>

            {!isCreatingExercise ? (
              <div className="mt-3">
                <select
                  value={selectedExerciseId}
                  onChange={(e) => setSelectedExerciseId(e.target.value === "" ? "" : Number(e.target.value))}
                  className="w-full rounded-xl border border-slate-200 bg-white p-2.5 text-xs text-slate-800"
                >
                  <option value="">-- Choose an exercise --</option>
                  {content.available_exercises.map((ex) => (
                    <option key={ex.id} value={ex.id}>
                      #{ex.id} - {ex.title} ({ex.status})
                    </option>
                  ))}
                </select>

                {content.available_exercises.length === 0 && (
                  <p className="mt-2 text-xs italic text-slate-500">
                    No exercises are currently assigned to this lesson. Use &quot;+ Author New Exercise&quot; above to create one.
                  </p>
                )}

                <div className="mt-3 flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setComposerMode("none")}
                    className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100"
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    disabled={isSubmitting || !selectedExerciseId}
                    onClick={() => void handleAddExercise()}
                    className="rounded-xl bg-[#0e8988] px-4 py-1.5 text-xs font-extrabold text-white hover:bg-[#087b83] disabled:opacity-50"
                  >
                    Insert Exercise Block
                  </button>
                </div>
              </div>
            ) : (
              <div className="mt-3 space-y-4">
                {/* Exercise Details */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="sm:col-span-2">
                    <label className="block text-[11px] font-bold text-slate-600">Exercise Title *</label>
                    <input
                      type="text"
                      value={newExerciseTitle}
                      onChange={(e) => setNewExerciseTitle(e.target.value)}
                      placeholder="e.g. Vocabulary Quiz: Unit 1"
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                    />
                  </div>

                  <div className="sm:col-span-2">
                    <label className="block text-[11px] font-bold text-slate-600">Instructions (Optional)</label>
                    <textarea
                      rows={2}
                      value={newExerciseInstructions}
                      onChange={(e) => setNewExerciseInstructions(e.target.value)}
                      placeholder="e.g. Choose the single best answer for each question."
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                    />
                  </div>

                  <div>
                    <label className="block text-[11px] font-bold text-slate-600">Passing Score (%)</label>
                    <input
                      type="number"
                      min={0}
                      max={100}
                      value={newExercisePassingScore}
                      onChange={(e) => setNewExercisePassingScore(Number(e.target.value))}
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                    />
                  </div>

                  <div>
                    <label className="block text-[11px] font-bold text-slate-600">Max Attempts (Blank = Unlimited)</label>
                    <input
                      type="number"
                      min={1}
                      value={newExerciseMaxAttempts}
                      onChange={(e) => setNewExerciseMaxAttempts(e.target.value === "" ? "" : Number(e.target.value))}
                      placeholder="Unlimited"
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                    />
                  </div>

                  <div className="sm:col-span-2">
                    <label className="flex items-center gap-2 text-xs font-semibold text-slate-700">
                      <input
                        type="checkbox"
                        checked={newExerciseShowAnswers}
                        onChange={(e) => setNewExerciseShowAnswers(e.target.checked)}
                        className="rounded border-slate-300 text-[#0e8988]"
                      />
                      Release correct answers and explanations to students after submission
                    </label>
                  </div>
                </div>

                {/* Questions Builder */}
                <div className="space-y-4 pt-2">
                  <div className="flex items-center justify-between">
                    <h5 className="text-xs font-black uppercase tracking-wider text-slate-700">
                      Questions ({newExerciseQuestions.length})
                    </h5>
                    <button
                      type="button"
                      onClick={handleAddQuestion}
                      className="rounded-lg bg-teal-100/70 px-2.5 py-1 text-[11px] font-extrabold text-teal-800 hover:bg-teal-200"
                    >
                      + Add Question
                    </button>
                  </div>

                  {newExerciseQuestions.map((q, qIdx) => (
                    <div
                      key={qIdx}
                      className="rounded-xl border border-slate-200 bg-white p-3.5 shadow-2xs"
                    >
                      <div className="flex items-center justify-between gap-2 border-b border-slate-100 pb-2">
                        <span className="text-[11px] font-black text-slate-700">
                          Question {qIdx + 1}
                        </span>
                        {newExerciseQuestions.length > 1 && (
                          <button
                            type="button"
                            onClick={() => handleRemoveQuestion(qIdx)}
                            className="text-[11px] font-bold text-rose-600 hover:text-rose-800"
                          >
                            Remove Question
                          </button>
                        )}
                      </div>

                      <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <div className="sm:col-span-2">
                          <label className="block text-[10px] font-bold uppercase text-slate-500">
                            Prompt *
                          </label>
                          <input
                            type="text"
                            value={q.prompt}
                            onChange={(e) => handleUpdateQuestion(qIdx, "prompt", e.target.value)}
                            placeholder="Enter question text"
                            className="mt-1 w-full rounded-lg border border-slate-200 p-2 text-xs"
                          />
                        </div>

                        <div>
                          <label className="block text-[10px] font-bold uppercase text-slate-500">
                            Points
                          </label>
                          <input
                            type="number"
                            min={0.5}
                            step={0.5}
                            value={q.points}
                            onChange={(e) => handleUpdateQuestion(qIdx, "points", Number(e.target.value))}
                            className="mt-1 w-full rounded-lg border border-slate-200 p-2 text-xs"
                          />
                        </div>
                      </div>

                      {/* Options */}
                      <div className="mt-3">
                        <label className="block text-[10px] font-bold uppercase text-slate-500">
                          Answer Options (Select correct answer)
                        </label>
                        <div className="mt-1.5 space-y-2">
                          {q.options.map((opt, optIdx) => (
                            <div key={optIdx} className="flex items-center gap-2">
                              <input
                                type="radio"
                                name={`correct_option_${qIdx}`}
                                checked={opt.is_correct}
                                onChange={(e) =>
                                  handleUpdateOption(qIdx, optIdx, "is_correct", e.target.checked)
                                }
                                className="h-4 w-4 text-[#0e8988]"
                                title="Mark as correct answer"
                              />
                              <input
                                type="text"
                                value={opt.option_text}
                                onChange={(e) =>
                                  handleUpdateOption(qIdx, optIdx, "option_text", e.target.value)
                                }
                                placeholder={`Option ${optIdx + 1}`}
                                className="flex-1 rounded-lg border border-slate-200 p-1.5 text-xs"
                              />
                              {q.options.length > 2 && (
                                <button
                                  type="button"
                                  onClick={() => handleRemoveOption(qIdx, optIdx)}
                                  className="text-xs text-slate-400 hover:text-rose-600"
                                  title="Delete option"
                                >
                                  ✕
                                </button>
                              )}
                            </div>
                          ))}
                        </div>

                        <button
                          type="button"
                          onClick={() => handleAddOption(qIdx)}
                          className="mt-2 text-[11px] font-bold text-teal-700 hover:underline"
                        >
                          + Add Option
                        </button>
                      </div>

                      {/* Explanation */}
                      <div className="mt-3">
                        <label className="block text-[10px] font-bold uppercase text-slate-500">
                          Explanation (Optional feedback shown after grading)
                        </label>
                        <input
                          type="text"
                          value={q.explanation}
                          onChange={(e) => handleUpdateQuestion(qIdx, "explanation", e.target.value)}
                          placeholder="Why this answer is correct"
                          className="mt-1 w-full rounded-lg border border-slate-200 p-2 text-xs"
                        />
                      </div>
                    </div>
                  ))}
                </div>

                <div className="mt-4 flex justify-end gap-2 border-t border-teal-100 pt-3">
                  <button
                    type="button"
                    onClick={() => {
                      setIsCreatingExercise(false);
                      setComposerMode("none");
                    }}
                    className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100"
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    disabled={isSubmitting}
                    onClick={() => void handleCreateAndInsertExercise()}
                    className="rounded-xl bg-[#0e8988] px-4 py-1.5 text-xs font-extrabold text-white hover:bg-[#087b83] disabled:opacity-50"
                  >
                    {isSubmitting ? "Creating & Publishing..." : "Save, Publish & Insert Exercise"}
                  </button>
                </div>
              </div>
            )}
          </div>
        )}
      </section>

      {/* Ordered Timeline */}
      <section className="space-y-4">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-black uppercase tracking-wider text-[#102b4c]">
            Revision Timeline ({activeBlocks.length} {activeBlocks.length === 1 ? "Block" : "Blocks"})
          </h3>
          <span className="text-xs text-slate-500">
            {hasDraft ? "Editing draft revision" : "Displaying published revision"}
          </span>
        </div>

        {activeBlocks.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p className="text-sm font-bold text-slate-600">No content blocks in this revision yet.</p>
            <p className="mt-1 text-xs text-slate-400">
              Use the Content Composer above to add text, media, or exercises.
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            {activeBlocks.map((block, index) => {
              const isFirst = index === 0;
              const isLast = index === activeBlocks.length - 1;

              return (
                <div
                  key={block.id}
                  className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs transition hover:border-slate-300"
                >
                  {/* Block Header */}
                  <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                    <div className="flex items-center gap-2">
                      <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-mono font-bold text-slate-700">
                        #{index + 1}
                      </span>
                      <span
                        className={`rounded-md px-2 py-0.5 text-[10px] font-extrabold uppercase ${
                          block.block_type === "text"
                            ? "bg-sky-50 text-sky-700"
                            : block.block_type === "media"
                              ? "bg-purple-50 text-purple-700"
                              : "bg-teal-50 text-teal-700"
                        }`}
                      >
                        {block.block_type}
                      </span>
                      <span className="text-xs font-semibold text-slate-400">
                        Block ID: {block.id}
                      </span>
                    </div>

                    {hasDraft && (
                      <div className="flex items-center gap-1">
                        <button
                          type="button"
                          disabled={isFirst || isSubmitting}
                          onClick={() => void handleMoveBlock(index, "up")}
                          aria-label="Move block up"
                          className="flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-30"
                        >
                          ↑
                        </button>
                        <button
                          type="button"
                          disabled={isLast || isSubmitting}
                          onClick={() => void handleMoveBlock(index, "down")}
                          aria-label="Move block down"
                          className="flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-30"
                        >
                          ↓
                        </button>
                        {block.block_type === "text" && (
                          <button
                            type="button"
                            onClick={() => {
                              if (editingBlockId === block.id) {
                                setEditingBlockId(null);
                              } else {
                                setEditingBlockId(block.id);
                                setEditingText(tipTapToText(block.content_json));
                              }
                            }}
                            className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50"
                          >
                            {editingBlockId === block.id ? "Close" : "Edit"}
                          </button>
                        )}
                        <button
                          type="button"
                          disabled={isSubmitting}
                          onClick={() => void handleDeleteBlock(block.id)}
                          className="rounded-lg border border-red-200 px-2.5 py-1 text-xs font-bold text-red-600 hover:bg-red-50"
                        >
                          Delete
                        </button>
                      </div>
                    )}
                  </div>

                  {/* Block Content Render / Edit */}
                  <div className="mt-4">
                    {editingBlockId === block.id ? (
                      <div className="space-y-3">
                        <textarea
                          rows={5}
                          value={editingText}
                          onChange={(e) => setEditingText(e.target.value)}
                          className="w-full rounded-xl border border-slate-200 p-3 text-xs leading-6 text-slate-800"
                        />
                        <div className="flex justify-end gap-2">
                          <button
                            type="button"
                            onClick={() => setEditingBlockId(null)}
                            className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600"
                          >
                            Cancel
                          </button>
                          <button
                            type="button"
                            disabled={isSubmitting}
                            onClick={() => void handleUpdateTextBlock(block.id)}
                            className="rounded-xl bg-[#0e8988] px-4 py-1.5 text-xs font-extrabold text-white"
                          >
                            Save Changes
                          </button>
                        </div>
                      </div>
                    ) : (
                      <>
                        {block.block_type === "text" && <LessonTextBlock block={block} />}
                        {block.block_type === "media" && (
                          <LessonMediaBlock block={block} params={routeParams} />
                        )}
                        {block.block_type === "exercise" && (
                          <LessonExerciseBlock block={block} isAdminPreview={true} />
                        )}
                      </>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </section>
    </div>
  );
}
