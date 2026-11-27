"use client";

import Link from "next/link";
import React, { useCallback, useEffect, useRef, useState } from "react";
import {
  DragDropProvider,
  DragOverlay,
  type DragEndEvent,
  type DragStartEvent,
} from "@dnd-kit/react";
import { isSortable } from "@dnd-kit/react/sortable";
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
  getAdminLessonRevisionHistory,
  getAdminHistoricalRevision,
  restoreAdminHistoricalRevision,
  getAdminLessonAssets,
  uploadAdminLessonAsset,
  createAdminExternalVideoAsset,
  requestAdminVideoUpload,
  uploadVideoBasic,
  getAdminVideoStatus,
  cancelAdminVideoUpload,
  type AdminLessonContent,
  type AdminLessonRevision,
  type AdminLessonRevisionHistoryItem,
} from "@/lib/admin-learning";
import {
  hasUnsavedLessonChanges,
  moveBlockInOrder,
  persistBlockOrderOptimistically,
} from "@/lib/admin-block-ordering.mjs";
import type {
  LessonBlock,
  TipTapDocument,
  LearningLessonAsset,
} from "@/lib/learning";
import LessonTextBlock from "@/components/learning/lesson-text-block";
import LessonMediaBlock from "@/components/learning/lesson-media-block";
import LessonAssetViewer from "@/components/learning/lesson-asset-viewer";
import LessonExerciseBlock from "@/components/learning/lesson-exercise-block";
import RichTextEditor from "./rich-text-editor";
import SortableBlockCard from "./sortable-block-card";
import ExerciseDraftBuilder from "./exercise-draft-builder";

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
  const [isReorderSaving, setIsReorderSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [revisionHistory, setRevisionHistory] = useState<AdminLessonRevisionHistoryItem[]>([]);
  const [selectedHistoricalRevision, setSelectedHistoricalRevision] = useState<AdminLessonRevision | null>(null);
  const [isLoadingHistoricalRevision, setIsLoadingHistoricalRevision] = useState(false);
  const [sortAnnouncement, setSortAnnouncement] = useState("");

  // Metadata editing state
  const [isEditingMetadata, setIsEditingMetadata] = useState(false);
  const [metaTitle, setMetaTitle] = useState("");
  const [metaDescription, setMetaDescription] = useState("");
  const [metaLocale, setMetaLocale] = useState("en");
  const [metaDuration, setMetaDuration] = useState<number | "">("");

  // Block creation states
  const [composerMode, setComposerMode] = useState<"none" | "text" | "media" | "exercise" | "exercise_builder">("none");

  // Rich text composer
  const [editingBlockId, setEditingBlockId] = useState<number | null>(null);
  const [isTextDirty, setIsTextDirty] = useState(false);

  // Media selection/upload
  const [selectedAssetId, setSelectedAssetId] = useState<number | "">("");
  const [isUploadingAsset, setIsUploadingAsset] = useState(false);
  const [uploadTitle, setUploadTitle] = useState("");
  const [uploadAltText, setUploadAltText] = useState("");
  const [externalVideoUrl, setExternalVideoUrl] = useState("");
  const [externalVideoCaption, setExternalVideoCaption] = useState("");
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploadType, setUploadType] = useState<
    "image" | "file" | "video" | "youtube" | "google_drive"
  >("image");
  const [useResumableVideoUpload, setUseResumableVideoUpload] = useState(false);
  const [videoUploadState, setVideoUploadState] = useState<{
    assetId: number;
    protocol: "basic" | "tus";
    phase: "waiting_url" | "uploading" | "interrupted" | "processing" | "ready" | "failed" | "cancelled";
    status: LearningLessonAsset["status"];
    progress: number | null;
    errorCode: string | null;
  } | null>(null);
  const videoAbortController = useRef<AbortController | null>(null);
  const tusUploadRef = useRef<import("tus-js-client").Upload | null>(null);
  const videoSessionKeyRef = useRef<string | null>(null);

  // Exercise selection & creation
  const [selectedExerciseId, setSelectedExerciseId] = useState<number | "">("");
  const [isCreatingExercise, setIsCreatingExercise] = useState(false);
  const [newExerciseTitle, setNewExerciseTitle] = useState("");
  const [newExerciseInstructions, setNewExerciseInstructions] = useState("");
  const [newExercisePassingScore, setNewExercisePassingScore] = useState<number>(70);
  const [newExerciseMaxAttempts, setNewExerciseMaxAttempts] = useState<number | "">("");
  const [newExerciseShowAnswers, setNewExerciseShowAnswers] = useState<boolean>(true);
  const [isExerciseDirty, setIsExerciseDirty] = useState(false);
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

  const metadataSource = content?.draft_revision ?? content?.published_revision;
  const metadataIsDirty = isEditingMetadata && Boolean(content) && (
    metaTitle !== (metadataSource?.title ?? content?.lesson.title ?? "") ||
    metaDescription !== (metadataSource?.description ?? content?.lesson.description ?? "") ||
    metaLocale !== (metadataSource?.content_locale ?? "en") ||
    metaDuration !== (metadataSource?.duration_minutes ?? "")
  );
  const mediaComposerIsDirty = composerMode === "media" && (
    uploadFile !== null
    || selectedAssetId !== ""
    || uploadTitle.trim() !== ""
    || uploadAltText.trim() !== ""
    || externalVideoUrl.trim() !== ""
    || externalVideoCaption.trim() !== ""
  );
  const hasUnsavedChanges = hasUnsavedLessonChanges({
    metadata: metadataIsDirty,
    text: isTextDirty,
    exercise: isExerciseDirty,
    media: mediaComposerIsDirty,
    reordering: isReorderSaving,
  });

  useEffect(() => {
    if (!hasUnsavedChanges) return;

    const warnBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };

    window.addEventListener("beforeunload", warnBeforeUnload);
    return () => window.removeEventListener("beforeunload", warnBeforeUnload);
  }, [hasUnsavedChanges]);

  const loadData = useCallback(async () => {
    setIsLoading(true);
    setError(null);

    try {
      const [contentData, assetsData, historyData] = await Promise.all([
        getAdminLessonContent(centerSlug, courseSlug, lessonId),
        getAdminLessonAssets(centerSlug, courseSlug, lessonId).catch(() => []),
        getAdminLessonRevisionHistory(centerSlug, courseSlug, lessonId).catch(() => []),
      ]);

      setContent(contentData);
      setAssets(assetsData);
      setRevisionHistory(historyData);
      setSelectedHistoricalRevision(null);

      const activeRev = contentData.draft_revision ?? contentData.published_revision;
      if (activeRev) {
        setMetaTitle(activeRev.title);
        setMetaDescription(activeRev.description ?? "");
        setMetaLocale(activeRev.content_locale);
        setMetaDuration(activeRev.duration_minutes ?? "");
      } else {
        setMetaTitle(contentData.lesson.title);
        setMetaDescription(contentData.lesson.description ?? "");
        setMetaLocale("en");
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

  useEffect(() => {
    const current = videoUploadState;
    if (!current || current.assetId < 1 || current.status !== "processing" || current.phase === "uploading") {
      return;
    }

    const interval = window.setInterval(() => {
      void getAdminVideoStatus(centerSlug, courseSlug, lessonId, current.assetId)
        .then((status) => {
          setAssets((previous) => previous.map((asset) =>
            asset.id === current.assetId ? status.asset : asset
          ));
          if (status.status === "active") {
            setSelectedAssetId(current.assetId);
          }
          setVideoUploadState((previous) => previous?.assetId === current.assetId
            ? {
                ...previous,
                status: status.status,
                phase: status.status === "active"
                  ? "ready"
                  : status.status === "failed"
                    ? "failed"
                    : previous.phase === "waiting_url" ? "processing" : previous.phase,
                progress: status.progress,
                errorCode: status.error_code,
              }
            : previous);
        })
        .catch(() => undefined);
    }, 5000);

    return () => window.clearInterval(interval);
  }, [centerSlug, courseSlug, lessonId, videoUploadState]);

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

  // Handle Draft Creation
  const handleCreateDraft = async () => {
    if (hasUnsavedChanges) {
      setError("Save or discard the current changes before creating a draft.");
      return;
    }

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
    if (isTextDirty || isExerciseDirty || mediaComposerIsDirty) {
      setError("Save or discard the other composer changes before saving metadata.");
      return;
    }

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
  const handleAddText = async (document: TipTapDocument) => {
    if (metadataIsDirty || isExerciseDirty || mediaComposerIsDirty) {
      setError("Save or discard the other unsaved changes before inserting this text block.");
      return;
    }

    setIsSubmitting(true);
    setError(null);
    try {
      const draft = await getOrCreateDraft();
      await addAdminTextBlock(centerSlug, courseSlug, lessonId, draft.id, document);
      setIsTextDirty(false);
      setComposerMode("none");
      const refreshed = await loadData();
      if (refreshed) {
        showSuccess("Text block added.");
      } else {
        setError("Text block was saved, but the lesson could not refresh. Reload to confirm it.");
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to add text block.");
    } finally {
      setIsSubmitting(false);
    }
  };

  // Handle Edit Text Block
  const handleUpdateTextBlock = async (blockId: number, document: TipTapDocument) => {
    if (!content?.draft_revision) return;
    if (metadataIsDirty || isExerciseDirty || mediaComposerIsDirty) {
      setError("Save or discard the other unsaved changes before updating this text block.");
      return;
    }

    setIsSubmitting(true);
    setError(null);
    try {
      await updateAdminBlock(centerSlug, courseSlug, lessonId, content.draft_revision.id, blockId, {
        content_json: document,
      });
      setIsTextDirty(false);
      setEditingBlockId(null);
      const refreshed = await loadData();
      if (refreshed) {
        showSuccess("Text block updated.");
      } else {
        setError("Text block was saved, but the lesson could not refresh. Reload to confirm it.");
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to update text block.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleCancelTextEditor = (): boolean => {
    if (isTextDirty && !window.confirm("Discard the unsaved text changes?")) {
      return false;
    }

    setIsTextDirty(false);
    setEditingBlockId(null);
    setComposerMode("none");
    return true;
  };

  const resetExerciseComposer = () => {
    setIsExerciseDirty(false);
    setSelectedExerciseId("");
    setIsCreatingExercise(false);
    setNewExerciseTitle("");
    setNewExerciseInstructions("");
    setNewExercisePassingScore(70);
    setNewExerciseMaxAttempts("");
    setNewExerciseShowAnswers(true);
    setNewExerciseQuestions([{
      prompt: "",
      points: 1,
      question_type: "single_choice",
      explanation: "",
      options: [
        { option_text: "", is_correct: true },
        { option_text: "", is_correct: false },
      ],
    }]);
  };

  const switchExerciseComposerMode = (createNew: boolean) => {
    if (createNew === isCreatingExercise) return;

    if (
      isExerciseDirty
      && !window.confirm("Discard the current exercise selection or authoring changes?")
    ) {
      return;
    }

    resetExerciseComposer();
    setIsCreatingExercise(createNew);
  };

  const handleCloseComposer = () => {
    const composerDirty = isExerciseDirty
      || isTextDirty
      || mediaComposerIsDirty;

    if (composerDirty && !window.confirm("Discard the unsaved composer changes?")) {
      return;
    }

    setIsTextDirty(false);
    setEditingBlockId(null);
    setUploadFile(null);
    setUploadTitle("");
    setUploadAltText("");
    setSelectedAssetId("");
    resetExerciseComposer();
    setComposerMode("none");
  };

  const handleSwitchComposer = (
    nextMode: "text" | "media" | "exercise" | "exercise_builder"
  ) => {
    if (composerMode === nextMode) {
      handleCloseComposer();
      return;
    }

    const composerDirty = isExerciseDirty
      || isTextDirty
      || mediaComposerIsDirty;
    if (composerDirty && !window.confirm("Discard the current composer changes?")) {
      return;
    }

    setIsTextDirty(false);
    setEditingBlockId(null);
    setUploadFile(null);
    setUploadTitle("");
    setUploadAltText("");
    setSelectedAssetId("");
    if (composerMode === "exercise" || nextMode === "exercise") {
      resetExerciseComposer();
    }
    setComposerMode(nextMode);
  };

  const handleToggleMetadata = () => {
    if (isEditingMetadata) {
      if (metadataIsDirty && !window.confirm("Discard unsaved lesson metadata?")) {
        return;
      }

      const current = content?.draft_revision ?? content?.published_revision;
      setMetaTitle(current?.title ?? content?.lesson.title ?? "");
      setMetaDescription(current?.description ?? content?.lesson.description ?? "");
      setMetaLocale(current?.content_locale ?? "en");
      setMetaDuration(current?.duration_minutes ?? "");
      setIsEditingMetadata(false);
      return;
    }

    setIsEditingMetadata(true);
  };

  const handleInspectHistoricalRevision = async (revisionId: number) => {
    if (selectedHistoricalRevision?.id === revisionId) {
      setSelectedHistoricalRevision(null);
      return;
    }

    setIsLoadingHistoricalRevision(true);
    setError(null);
    try {
      const revision = await getAdminHistoricalRevision(
        centerSlug,
        courseSlug,
        lessonId,
        revisionId
      );
      setSelectedHistoricalRevision(revision);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to inspect this revision.");
    } finally {
      setIsLoadingHistoricalRevision(false);
    }
  };

  const handleRestoreHistoricalRevision = async (
    revision: AdminLessonRevisionHistoryItem
  ) => {
    if (revision.status === "draft") {
      setError("Only published or superseded revisions can be restored.");
      return;
    }

    if (content?.draft_revision) {
      setError("Publish or finish the current draft before restoring a historical version.");
      return;
    }

    if (
      hasUnsavedChanges &&
      !window.confirm("Discard unsaved editor changes and continue with the restore?")
    ) {
      return;
    }

    if (!window.confirm(`Restore version ${revision.version_number} as a new draft? The original revision will remain unchanged.`)) {
      return;
    }

    setIsSubmitting(true);
    setError(null);
    try {
      await restoreAdminHistoricalRevision(
        centerSlug,
        courseSlug,
        lessonId,
        revision.id
      );
      setIsTextDirty(false);
      setIsExerciseDirty(false);
      setIsEditingMetadata(false);
      setUploadFile(null);
      setUploadTitle("");
      setUploadAltText("");
      setSelectedAssetId("");
      resetExerciseComposer();
      setComposerMode("none");
      const refreshed = await loadData();
      if (refreshed) {
        showSuccess(`Version ${revision.version_number} was copied into a new draft.`);
      } else {
        setError("The new draft was created, but the editor could not refresh. Reload to view it.");
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to restore the selected revision.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const checkVideoStatus = async (assetId: number) => {
    const status = await getAdminVideoStatus(centerSlug, courseSlug, lessonId, assetId);
    setAssets((previous) => previous.map((asset) =>
      asset.id === assetId ? status.asset : asset
    ));
    if (status.status === "active") {
      setSelectedAssetId(assetId);
    }
    setVideoUploadState((previous) => previous?.assetId === assetId
      ? {
          ...previous,
          status: status.status,
          phase: status.status === "active"
            ? "ready"
            : status.status === "failed"
              ? "failed"
              : "processing",
          progress: status.progress,
          errorCode: status.error_code,
        }
      : previous);
  };

  const handleCheckVideoStatus = async (assetId: number) => {
    setError(null);
    try {
      await checkVideoStatus(assetId);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to retrieve video status.");
    }
  };

  const handleVideoUpload = async () => {
    if (!uploadFile) return;

    setIsUploadingAsset(true);
    setError(null);
    setVideoUploadState({
      assetId: 0,
      protocol: uploadFile.size > 200 * 1024 * 1024 || useResumableVideoUpload ? "tus" : "basic",
      phase: "waiting_url",
      status: "processing",
      progress: 0,
      errorCode: null,
    });

    let tusStarted = false;

    try {
      const useTus = uploadFile.size > 200 * 1024 * 1024 || useResumableVideoUpload;
      let provision: Awaited<ReturnType<typeof requestAdminVideoUpload>> | null = null;
      let resumableAssetId: number | null = null;

      if (useTus) {
        const sessionKey = [
          "cloudflare-tus",
          centerSlug,
          courseSlug,
          lessonId,
          uploadFile.name,
          uploadFile.size,
          uploadFile.lastModified,
        ].join(":");
        videoSessionKeyRef.current = sessionKey;

        const saved = window.sessionStorage.getItem(sessionKey);
        if (saved) {
          try {
            const previous = JSON.parse(saved) as {
              asset_id?: number;
              upload_url?: string;
              expires_at?: string;
            };
            const savedAsset = assets.find((asset) =>
              asset.id === previous.asset_id
                && asset.asset_type === "video"
                && asset.status === "processing"
            );

            if (
              savedAsset
              && typeof previous.upload_url === "string"
              && typeof previous.expires_at === "string"
              && Date.parse(previous.expires_at) > Date.now()
            ) {
              resumableAssetId = savedAsset.id;
              provision = {
                asset: savedAsset,
                upload_url: previous.upload_url,
                protocol: "tus",
                expires_at: previous.expires_at,
                chunk_size_bytes: 50 * 1024 * 1024,
              };
            } else {
              window.sessionStorage.removeItem(sessionKey);
            }
          } catch {
            window.sessionStorage.removeItem(sessionKey);
          }
        }
      }

      if (!provision) {
        const createdProvision = await requestAdminVideoUpload(centerSlug, courseSlug, lessonId, {
          title: uploadTitle.trim(),
          original_filename: uploadFile.name,
          mime_type: uploadFile.type || "video/mp4",
          size_bytes: uploadFile.size,
          resumable: useTus,
        });
        provision = createdProvision;

        setAssets((previous) => [
          ...previous.filter((asset) => asset.id !== createdProvision.asset.id),
          createdProvision.asset,
        ]);
      }

      const uploadProvision = provision;
      if (!uploadProvision) {
        throw new Error("Failed to prepare the video upload.");
      }

      const assetId = resumableAssetId ?? uploadProvision.asset.id;
      setVideoUploadState({
        assetId,
        protocol: uploadProvision.protocol,
        phase: "waiting_url",
        status: uploadProvision.asset.status,
        progress: 0,
        errorCode: null,
      });

      if (uploadProvision.protocol === "basic") {
        const abortController = new AbortController();
        videoAbortController.current = abortController;
        setVideoUploadState((previous) => previous
          ? { ...previous, phase: "uploading" }
          : previous);

        await uploadVideoBasic(
          uploadProvision.upload_url,
          uploadFile,
          (progress) => setVideoUploadState((previous) => previous
            ? { ...previous, progress }
            : previous),
          abortController.signal
        );

        videoAbortController.current = null;
        setVideoUploadState((previous) => previous
          ? { ...previous, phase: "processing", status: "processing", progress: 100 }
          : previous);
        setUploadFile(null);
        showSuccess("Upload complete. Cloudflare is processing the video.");
        void getAdminLessonAssets(centerSlug, courseSlug, lessonId).then(setAssets).catch(() => undefined);
        return;
      }

      const tus = await import("tus-js-client");
      const upload = new tus.Upload(uploadFile, {
        uploadUrl: uploadProvision.upload_url,
        chunkSize: uploadProvision.chunk_size_bytes ?? 50 * 1024 * 1024,
        retryDelays: [0, 1000, 3000, 5000, 10000],
        metadata: {
          name: uploadFile.name,
          filetype: uploadFile.type || "video/mp4",
        },
        onProgress: (uploaded, total) => {
          setVideoUploadState((previous) => previous
            ? { ...previous, progress: total > 0 ? Math.min(100, (uploaded / total) * 100) : 0 }
            : previous);
        },
        onError: () => {
          setIsUploadingAsset(false);
          setVideoUploadState((previous) => previous
            ? { ...previous, phase: "interrupted" }
            : previous);
          setError("Video upload was interrupted. Resume it or cancel the pending upload.");
        },
        onSuccess: () => {
          setIsUploadingAsset(false);
          setVideoUploadState((previous) => previous
            ? { ...previous, phase: "processing", status: "processing", progress: 100 }
            : previous);
          if (videoSessionKeyRef.current) {
            window.sessionStorage.removeItem(videoSessionKeyRef.current);
          }
          setUploadFile(null);
          showSuccess("Upload complete. Cloudflare is processing the video.");
          void getAdminLessonAssets(centerSlug, courseSlug, lessonId).then(setAssets).catch(() => undefined);
        },
      });

      tusUploadRef.current = upload;
      if (useTus && videoSessionKeyRef.current) {
        window.sessionStorage.setItem(videoSessionKeyRef.current, JSON.stringify({
          asset_id: assetId,
          upload_url: uploadProvision.upload_url,
          expires_at: uploadProvision.expires_at,
        }));
      }
      setVideoUploadState((previous) => previous
        ? { ...previous, phase: "uploading" }
        : previous);
      tusStarted = true;
      upload.start();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to upload video.");
      setVideoUploadState((previous) => previous
        ? { ...previous, phase: "interrupted" }
        : previous);
    } finally {
      if (!tusStarted) {
        setIsUploadingAsset(false);
      }
    }
  };

  const handleResumeVideoUpload = () => {
    if (!tusUploadRef.current) return;
    setError(null);
    setIsUploadingAsset(true);
    setVideoUploadState((previous) => previous
      ? { ...previous, phase: "uploading" }
      : previous);
    tusUploadRef.current.start();
  };

  const handleCancelVideoUpload = async (assetId: number) => {
    setError(null);
    try {
      videoAbortController.current?.abort();
      await tusUploadRef.current?.abort(false);
      const archived = await cancelAdminVideoUpload(centerSlug, courseSlug, lessonId, assetId);
      setAssets((previous) => previous.map((asset) => asset.id === assetId ? archived : asset));
      if (videoSessionKeyRef.current) {
        window.sessionStorage.removeItem(videoSessionKeyRef.current);
      }
      tusUploadRef.current = null;
      videoAbortController.current = null;
      setVideoUploadState((previous) => previous?.assetId === assetId
        ? { ...previous, phase: "cancelled", status: "archived" }
        : previous);
      setIsUploadingAsset(false);
      setUploadFile(null);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to cancel video upload.");
      void getAdminLessonAssets(centerSlug, courseSlug, lessonId).then(setAssets).catch(() => undefined);
    }
  };

  // Handle Asset Upload
  const handleUploadAsset = async () => {
    if (metadataIsDirty || isTextDirty || isExerciseDirty) {
      setError("Save or discard the other unsaved changes before uploading media.");
      return;
    }
    if (uploadType === "video") {
      if (!uploadFile) return;
      await handleVideoUpload();
      return;
    }

    if (uploadType === "youtube" || uploadType === "google_drive") {
      const url = externalVideoUrl.trim();

      if (!url) {
        setError("Enter a video link before adding it.");
        return;
      }

      setIsUploadingAsset(true);
      setError(null);

      try {
        const newAsset = await createAdminExternalVideoAsset(
          centerSlug,
          courseSlug,
          lessonId,
          {
            provider: uploadType,
            url,
            title: uploadTitle.trim() || undefined,
            caption: externalVideoCaption.trim() || undefined,
          }
        );
        const updatedAssets = await getAdminLessonAssets(
          centerSlug,
          courseSlug,
          lessonId
        );

        setAssets(updatedAssets);
        setSelectedAssetId(newAsset.id);
        setExternalVideoUrl("");
        setExternalVideoCaption("");
        setUploadTitle("");
        showSuccess("Video added to the media library. Preview it, then insert it into the lesson.");
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : "Failed to add external video.");
      } finally {
        setIsUploadingAsset(false);
      }

      return;
    }

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
    if (metadataIsDirty || isTextDirty || isExerciseDirty) {
      setError("Save or discard the other unsaved changes before inserting media.");
      return;
    }

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

  const handleInsertExercise = async (exerciseId: number) => {
    if (metadataIsDirty || isTextDirty || mediaComposerIsDirty) {
      setError("Save or discard the other unsaved changes before inserting this exercise.");
      return;
    }

    setIsSubmitting(true);
    setError(null);
    try {
      const draft = await getOrCreateDraft();
      await addAdminExerciseBlock(centerSlug, courseSlug, lessonId, draft.id, exerciseId);
      setIsExerciseDirty(false);
      setComposerMode("none");
      await loadData();
      showSuccess("Exercise block added.");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to add exercise block.");
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

  // Persist one complete, duplicate-free draft order and roll back on failure.
  const handleReorderBlock = async (
    blockId: number,
    oldIndex: number,
    newIndex: number
  ) => {
    const draft = content?.draft_revision;
    if (!draft || isSubmitting || isReorderSaving) return;

    const previousBlocks = draft.blocks;
    let reorderedBlocks: LessonBlock[];
    try {
      reorderedBlocks = moveBlockInOrder(
        previousBlocks,
        blockId,
        oldIndex,
        newIndex
      );
    } catch {
      setSortAnnouncement("Block order was not changed because the timeline is stale.");
      setError("The timeline changed before the reorder could be saved. Reload the lesson and try again.");
      return;
    }

    if (oldIndex === newIndex) return;
    setIsReorderSaving(true);
    setIsSubmitting(true);
    setError(null);

    try {
      await persistBlockOrderOptimistically(
        previousBlocks,
        reorderedBlocks,
        (orderedIds) => reorderAdminBlocks(
          centerSlug,
          courseSlug,
          lessonId,
          draft.id,
          orderedIds
        ),
        (blocks) => {
          setContent((current) => current?.draft_revision?.id === draft.id
            ? {
                ...current,
                draft_revision: { ...current.draft_revision, blocks },
              }
            : current);
        }
      );
      setSortAnnouncement(`Moved block ${blockId} to position ${newIndex + 1}.`);
      showSuccess("Block order saved.");
    } catch (err: unknown) {
      setSortAnnouncement("The reorder failed. The previous order was restored.");
      setError(err instanceof Error ? err.message : "Failed to reorder blocks.");
    } finally {
      setIsReorderSaving(false);
      setIsSubmitting(false);
    }
  };

  const handleDragStart = (event: DragStartEvent) => {
    const source = event.operation.source;
    if (source) {
      setSortAnnouncement(`Picked up block ${source.id}.`);
    }
  };

  const handleDragEnd = (event: DragEndEvent) => {
    if (event.canceled) {
      setSortAnnouncement("Block reorder canceled.");
      return;
    }

    const { source, target } = event.operation;
    if (!target || !isSortable(source) || !isSortable(target)) {
      setSortAnnouncement("Block reorder canceled.");
      return;
    }

    void handleReorderBlock(Number(source.id), source.initialIndex, source.index);
  };

  // Handle Publication
  const handlePublish = async () => {
    if (hasUnsavedChanges) {
      setError("Save or discard all unsaved changes before publishing the draft.");
      return;
    }

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
  const selectedAsset = assets.find((asset) => asset.id === selectedAssetId);
  const blockTypeName = (block: LessonBlock): string => {
    if (block.block_type === "text") return "Text";
    if (block.block_type === "exercise") return "Exercise";
    if (block.asset?.asset_type === "image") return "Image";
    if (block.asset?.asset_type === "video") return "Video";
    if (block.asset?.asset_type === "file") return "Document";
    return "Media";
  };

  return (
    <div className="mx-auto max-w-5xl space-y-6 pb-20">
      {/* Top Action Bar */}
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-5">
        <div className="flex items-center gap-3">
          <Link
            href={returnHref}
            onClick={(event) => {
              if (hasUnsavedChanges && !window.confirm("Leave this lesson editor and discard unsaved changes?")) {
                event.preventDefault();
              }
            }}
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
            <h1 className="text-xl font-black text-[#102b4c]">
              {draft?.title ?? published?.title ?? content.lesson.title}
            </h1>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <span
            role="status"
            className={`rounded-full px-3 py-2 text-xs font-semibold ${
              hasUnsavedChanges
                ? "bg-amber-50 text-amber-800"
                : "bg-emerald-50 text-emerald-800"
            }`}
          >
            {hasUnsavedChanges
              ? "Unsaved changes"
              : draft ? "Draft saved" : "No unsaved changes"}
          </span>
          {onSwitchToPreview && (
            <button
              type="button"
              onClick={() => {
                if (hasUnsavedChanges && !window.confirm("Open the preview and leave unsaved changes behind?")) {
                  return;
                }
                onSwitchToPreview();
              }}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-[#102b4c] shadow-xs transition hover:bg-slate-50"
            >
              👁 View Preview
            </button>
          )}

          {!draft ? (
            <button
              type="button"
              disabled={isSubmitting || hasUnsavedChanges}
              onClick={() => void handleCreateDraft()}
              className="rounded-xl bg-[#0e8988] px-5 py-2 text-xs font-extrabold text-white shadow-xs transition hover:bg-[#087b83] disabled:opacity-50"
            >
              + Create New Draft
            </button>
          ) : (
            <button
              type="button"
              disabled={isSubmitting || hasUnsavedChanges || draft.blocks.length === 0}
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
              onClick={handleToggleMetadata}
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
                disabled={isSubmitting || !metadataIsDirty}
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
            <h2 className="text-sm font-black text-[#102b4c]">Content Composer</h2>
            <p className="mt-0.5 text-xs text-slate-500">
              Add rich text, media assets, or assessment exercises to this revision.
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => handleSwitchComposer("text")}
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
              onClick={() => handleSwitchComposer("media")}
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
              onClick={() => handleSwitchComposer("exercise")}
              className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                composerMode === "exercise"
                  ? "bg-[#0e8988] text-white"
                  : "border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100"
              }`}
            >
              + Add Exercise
            </button>

            <button
              type="button"
              onClick={() => handleSwitchComposer("exercise_builder")}
              className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                composerMode === "exercise_builder"
                  ? "bg-[#0e8988] text-white"
                  : "border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100"
              }`}
            >
              Exercise Drafts
            </button>
          </div>
        </div>

        {/* Composer: Text Form */}
        {composerMode === "text" && (
          <div className="mt-5 rounded-xl border border-teal-100 bg-teal-50/30 p-4">
            <div className="mb-3">
              <h3 className="text-xs font-black text-[#102b4c]">Add Text Block</h3>
              <p className="mt-1 text-[11px] text-slate-500">
                Format the content here. It is saved as structured Tiptap JSON.
              </p>
            </div>
            <RichTextEditor
              key="new-text-block"
              initialDocument={{ type: "doc", content: [{ type: "paragraph" }] }}
              isSubmitting={isSubmitting}
              saveLabel="Insert Text Block"
              onCancel={handleCancelTextEditor}
              onDirtyChange={setIsTextDirty}
              onSave={(document) => handleAddText(document)}
              routeParams={routeParams}
              imageAssets={assets}
              onImageAssetUploaded={(uploaded) =>
                setAssets((prev) =>
                  prev.some((item) => item.id === uploaded.id) ? prev : [...prev, uploaded]
                )
              }
            />
          </div>
        )}

        {/* Composer: Media Form */}
        {composerMode === "media" && (
          <div className="mt-5 rounded-xl border border-teal-100 bg-teal-50/30 p-4 space-y-4">
            <h3 className="text-xs font-black text-[#102b4c]">Add Media Block</h3>

            {assets.some((asset) => asset.asset_type === "video") && (
              <div className="space-y-2 rounded-xl border border-slate-200 bg-white p-3">
                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-600">Video Uploads</h4>
                {assets.filter((asset) => asset.asset_type === "video").map((asset) => (
                  <div key={asset.id} className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2">
                    <div className="min-w-0">
                      <p className="truncate text-xs font-bold text-[#102b4c]">
                        {asset.title || asset.original_filename || `Video #${asset.id}`}
                      </p>
                      <p className="text-[11px] text-slate-500">
                        {asset.status === "processing" ? "Processing" : asset.status}
                        {asset.id === videoUploadState?.assetId && videoUploadState.progress !== null
                          ? ` · ${Math.round(videoUploadState.progress)}%`
                          : ""}
                        {asset.id === videoUploadState?.assetId && videoUploadState.errorCode
                          ? ` · ${videoUploadState.errorCode}`
                          : ""}
                      </p>
                    </div>
                    <div className="flex gap-2">
                      {asset.status === "processing" && (
                        <>
                          <button
                            type="button"
                            onClick={() => void handleCheckVideoStatus(asset.id)}
                            className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-50"
                          >
                            Check status
                          </button>
                          <button
                            type="button"
                            disabled={isUploadingAsset && asset.id !== videoUploadState?.assetId}
                            onClick={() => void handleCancelVideoUpload(asset.id)}
                            className="rounded-lg border border-red-200 px-2.5 py-1 text-[11px] font-bold text-red-600 hover:bg-red-50 disabled:opacity-50"
                          >
                            Cancel
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}

            {videoUploadState && (
              <div className="rounded-xl border border-sky-200 bg-sky-50 p-3">
                <p className="text-xs font-bold text-sky-900">
                  {videoUploadState.phase === "waiting_url" && "Waiting for upload URL"}
                  {videoUploadState.phase === "uploading" && `Uploading with ${videoUploadState.protocol.toUpperCase()}`}
                  {videoUploadState.phase === "interrupted" && "Upload interrupted"}
                  {videoUploadState.phase === "processing" && "Upload complete; processing"}
                  {videoUploadState.phase === "ready" && "Ready to insert"}
                  {videoUploadState.phase === "failed" && "Video processing failed"}
                  {videoUploadState.phase === "cancelled" && "Upload cancelled"}
                </p>
                {(videoUploadState.phase === "uploading" || videoUploadState.phase === "processing") && (
                  <progress
                    className="mt-2 h-2 w-full accent-[#0e8988]"
                    max={100}
                    value={videoUploadState.progress ?? 0}
                  />
                )}
                {videoUploadState.errorCode && (
                  <p className="mt-1 text-[11px] text-red-700">Provider code: {videoUploadState.errorCode}</p>
                )}
                <div className="mt-2 flex flex-wrap gap-2">
                  {videoUploadState.phase === "interrupted" && videoUploadState.protocol === "tus" && (
                    <button
                      type="button"
                      disabled={isUploadingAsset}
                      onClick={handleResumeVideoUpload}
                      className="rounded-lg bg-[#0e8988] px-3 py-1 text-[11px] font-bold text-white disabled:opacity-50"
                    >
                      Resume upload
                    </button>
                  )}
                  {videoUploadState.status === "processing" && videoUploadState.assetId > 0 && (
                    <button
                      type="button"
                      onClick={() => void handleCheckVideoStatus(videoUploadState.assetId)}
                      className="rounded-lg border border-sky-300 px-3 py-1 text-[11px] font-bold text-sky-900"
                    >
                      Retry status check
                    </button>
                  )}
                  {videoUploadState.status === "processing" && videoUploadState.assetId > 0 && (
                    <button
                      type="button"
                      onClick={() => void handleCancelVideoUpload(videoUploadState.assetId)}
                      className="rounded-lg border border-red-200 px-3 py-1 text-[11px] font-bold text-red-600"
                    >
                      Cancel upload
                    </button>
                  )}
                </div>
              </div>
            )}

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
                    #{a.id} - {a.title || a.original_filename} ({a.asset_type}, {a.status})
                  </option>
                ))}
              </select>
            </div>

            {selectedAssetId !== "" && (
              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  disabled={isSubmitting || selectedAsset?.status !== "active"}
                  onClick={() => void handleAddMedia()}
                  className="rounded-xl bg-[#0e8988] px-4 py-1.5 text-xs font-extrabold text-white hover:bg-[#087b83] disabled:opacity-50"
                >
                  {selectedAsset?.status === "active" ? "Insert Selected Media" : "Available after processing"}
                </button>
              </div>
            )}

            {selectedAsset?.asset_type === "video"
              && selectedAsset.status === "active"
              && (
                <div className="rounded-xl border border-slate-200 bg-white p-3">
                  <p className="mb-2 text-xs font-bold text-slate-700">Preview selected video</p>
                  <LessonAssetViewer asset={selectedAsset} params={routeParams} />
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
                    onChange={(e) => setUploadType(e.target.value as "image" | "file" | "video")}
                    className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                  >
                    <option value="image">Image (JPG, PNG, WebP)</option>
                    <option value="file">Document (PDF, Word, etc.)</option>
                    <option value="video">Video (Cloudflare Stream)</option>
                    <option value="youtube">YouTube video link</option>
                    <option value="google_drive">Google Drive video link</option>
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

                {(uploadType === "youtube" || uploadType === "google_drive") && (
                  <div className="sm:col-span-2">
                    <label className="block text-[11px] font-bold text-slate-600">
                      {uploadType === "youtube" ? "YouTube video URL" : "Google Drive video URL"}
                    </label>
                    <input
                      type="url"
                      required
                      autoComplete="off"
                      value={externalVideoUrl}
                      onChange={(event) => setExternalVideoUrl(event.target.value)}
                      placeholder={uploadType === "youtube"
                        ? "https://www.youtube.com/watch?v=..."
                        : "https://drive.google.com/file/d/.../view"}
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                    />
                    <p className="mt-1 text-[10px] leading-5 text-slate-500">
                      Use an HTTPS video link. Drive videos must be shared with the students who need to watch them.
                    </p>
                    <label className="mt-3 block text-[11px] font-bold text-slate-600">
                      Optional caption
                    </label>
                    <textarea
                      value={externalVideoCaption}
                      onChange={(event) => setExternalVideoCaption(event.target.value)}
                      maxLength={3000}
                      rows={2}
                      className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 text-xs"
                    />
                  </div>
                )}

                {uploadType !== "youtube" && uploadType !== "google_drive" && (
                <div className="sm:col-span-2">
                  <label className="block text-[11px] font-bold text-slate-600">Choose File</label>
                  <input
                    type="file"
                    accept={uploadType === "video" ? "video/*" : uploadType === "image" ? "image/jpeg,image/png,image/webp" : ".pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx"}
                    onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                    className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-1 text-xs"
                  />
                </div>
                )}

                {uploadType === "video" && (
                  <div className="sm:col-span-2 rounded-lg bg-white p-3">
                    <label className="flex items-center gap-2 text-[11px] font-semibold text-slate-700">
                      <input
                        type="checkbox"
                        checked={useResumableVideoUpload}
                        onChange={(e) => setUseResumableVideoUpload(e.target.checked)}
                      />
                      Use resumable TUS upload for this video
                    </label>
                    <p className="mt-1 text-[10px] text-slate-500">
                      Files over 200 MB use TUS automatically. Video bytes upload directly to Cloudflare.
                    </p>
                  </div>
                )}
              </div>

              <div className="mt-3 flex justify-end gap-2">
                <button
                  type="button"
                  disabled={isUploadingAsset || (
                    uploadType === "youtube" || uploadType === "google_drive"
                      ? !externalVideoUrl.trim()
                      : !uploadFile
                  )}
                  onClick={() => void handleUploadAsset()}
                  className="rounded-xl bg-[#092443] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#123b64] disabled:opacity-50"
                >
                  {isUploadingAsset
                    ? "Uploading..."
                    : uploadType === "video"
                      ? "Start Video Upload"
                      : uploadType === "youtube" || uploadType === "google_drive"
                        ? "Add External Video"
                        : "Upload Resource"}
                </button>
              </div>
            </div>
          </div>
        )}

        {composerMode === "exercise_builder" && (
          <ExerciseDraftBuilder
            centerSlug={centerSlug}
            courseSlug={courseSlug}
            lessonId={lessonId}
            onDirtyChange={setIsExerciseDirty}
            onInsert={handleInsertExercise}
          />
        )}
      </section>

      {/* Ordered curriculum timeline */}
      <section className="space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-black uppercase tracking-wider text-[#102b4c]">
            Curriculum Timeline ({activeBlocks.length} {activeBlocks.length === 1 ? "Block" : "Blocks"})
          </h2>
          <span className="text-xs text-slate-500">
            {hasDraft ? "Draft · drag blocks by the handle to reorder" : "Published revision · read only"}
          </span>
        </div>

        <p role="status" aria-live="polite" className="sr-only">
          {sortAnnouncement}
        </p>

        {activeBlocks.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p className="text-sm font-bold text-slate-600">No content blocks in this revision yet.</p>
            <p className="mt-1 text-xs text-slate-400">
              Use the Content Composer above to add text, media, or exercises.
            </p>
          </div>
        ) : (
          <DragDropProvider
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
          >
            <DragOverlay>
              {(source) => {
                const draggedBlock = activeBlocks.find(
                  (block) => block.id === Number(source.id)
                );
                return draggedBlock ? (
                  <div className="rounded-xl border border-teal-200 bg-white px-4 py-3 text-xs font-bold text-[#102b4c] shadow-xl">
                    {blockTypeName(draggedBlock)} block · {draggedBlock.asset?.title ?? draggedBlock.exercise?.title ?? `Block #${draggedBlock.id}`}
                  </div>
                ) : null;
              }}
            </DragOverlay>
            <div className="max-h-[70vh] overflow-y-auto overscroll-contain rounded-2xl pr-2">
            <div className="space-y-4">
            {activeBlocks.map((block, index) => {
              const blockLabel = blockTypeName(block);

              return (
                <SortableBlockCard
                  key={block.id}
                  blockId={block.id}
                  index={index}
                  blockLabel={`${blockLabel} block`}
                  disabled={!hasDraft || isSubmitting || hasUnsavedChanges}
                >
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs transition hover:border-slate-300">
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
                            : block.block_type === "exercise"
                              ? "bg-teal-50 text-teal-700"
                              : block.asset?.asset_type === "image"
                                ? "bg-purple-50 text-purple-700"
                                : block.asset?.asset_type === "video"
                                  ? "bg-rose-50 text-rose-700"
                                  : "bg-amber-50 text-amber-800"
                        }`}
                      >
                        {blockLabel}
                      </span>
                      <span className="text-xs font-semibold text-slate-600">
                        {block.asset?.title ?? block.exercise?.title ?? "Formatted lesson content"}
                      </span>
                      {(block.asset || block.exercise) && (
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600">
                          {block.asset?.status ?? block.exercise?.status}
                        </span>
                      )}
                    </div>

                    {hasDraft && (
                      <div className="flex items-center gap-1">
                        {block.block_type === "text" && (
                          <button
                            type="button"
                            disabled={isSubmitting || editingBlockId !== null && editingBlockId !== block.id}
                            onClick={() => {
                              if (editingBlockId === block.id) {
                                handleCancelTextEditor();
                              } else {
                                setIsTextDirty(false);
                                setEditingBlockId(block.id);
                              }
                            }}
                            className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50"
                          >
                            {editingBlockId === block.id ? "Close" : "Edit"}
                          </button>
                        )}
                        <button
                          type="button"
                          disabled={isSubmitting || hasUnsavedChanges}
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
                      <RichTextEditor
                        key={`edit-text-${block.id}`}
                        initialDocument={block.content_json ?? { type: "doc", content: [{ type: "paragraph" }] }}
                        isSubmitting={isSubmitting}
                        saveLabel="Save Text Block"
                        onCancel={handleCancelTextEditor}
                        onDirtyChange={setIsTextDirty}
                        onSave={(document) => handleUpdateTextBlock(block.id, document)}
                        routeParams={routeParams}
                        imageAssets={assets}
                        onImageAssetUploaded={(uploaded) =>
                          setAssets((prev) =>
                            prev.some((item) => item.id === uploaded.id) ? prev : [...prev, uploaded]
                          )
                        }
                      />
                    ) : (
                      <>
                        {block.block_type === "text" && <LessonTextBlock block={block} params={routeParams} />}
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
                </SortableBlockCard>
              );
            })}
            </div>
            </div>
          </DragDropProvider>
        )}
      </section>

      <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-sm font-black text-[#102b4c]">Revision History</h2>
            <p className="mt-1 text-xs text-slate-500">
              Inspect prior versions read-only. Restoring copies content into a new draft.
            </p>
          </div>
          <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
            {revisionHistory.length} {revisionHistory.length === 1 ? "version" : "versions"}
          </span>
        </div>

        {revisionHistory.length === 0 ? (
          <p className="rounded-xl border border-dashed border-slate-200 p-5 text-center text-xs text-slate-500">
            No revision history is available yet.
          </p>
        ) : (
          <ol className="space-y-2">
            {revisionHistory.map((revision) => (
              <li
                key={revision.id}
                className="flex flex-col gap-3 rounded-xl border border-slate-100 p-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-bold text-[#102b4c]">
                      v{revision.version_number} · {revision.title}
                    </span>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600">
                      {revision.status}
                    </span>
                  </div>
                  <p className="mt-1 text-[11px] text-slate-500">
                    {revision.author?.name ? `By ${revision.author.name} · ` : ""}
                    {revision.published_at
                      ? `Published ${new Date(revision.published_at).toLocaleString()}`
                      : revision.created_at
                        ? `Created ${new Date(revision.created_at).toLocaleString()}`
                        : "Date unavailable"}
                  </p>
                </div>

                <div className="flex shrink-0 flex-wrap gap-2">
                  <button
                    type="button"
                    disabled={isLoadingHistoricalRevision}
                    onClick={() => void handleInspectHistoricalRevision(revision.id)}
                    className="min-h-10 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  >
                    {selectedHistoricalRevision?.id === revision.id ? "Close preview" : "Inspect"}
                  </button>
                  <button
                    type="button"
                    disabled={isSubmitting || Boolean(draft) || revision.status === "draft"}
                    title={draft ? "Publish or finish the current draft before restoring." : undefined}
                    onClick={() => void handleRestoreHistoricalRevision(revision)}
                    className="min-h-10 rounded-lg border border-teal-200 px-3 text-xs font-bold text-teal-800 hover:bg-teal-50 disabled:cursor-not-allowed disabled:opacity-45"
                  >
                    Restore as new draft
                  </button>
                </div>
              </li>
            ))}
          </ol>
        )}

        {isLoadingHistoricalRevision && (
          <p role="status" className="text-xs text-slate-500">Loading revision preview…</p>
        )}

        {selectedHistoricalRevision && (
          <div className="space-y-4 rounded-xl border border-indigo-100 bg-indigo-50/30 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-indigo-100 pb-3">
              <div>
                <p className="text-[10px] font-extrabold uppercase tracking-wider text-indigo-700">
                  {selectedHistoricalRevision.status === "draft"
                    ? "Read-only draft preview"
                    : "Read-only historical preview"} · v{selectedHistoricalRevision.version_number}
                </p>
                <h3 className="mt-1 text-base font-black text-[#102b4c]">
                  {selectedHistoricalRevision.title}
                </h3>
                {selectedHistoricalRevision.description && (
                  <p className="mt-1 text-xs text-slate-600">
                    {selectedHistoricalRevision.description}
                  </p>
                )}
              </div>
              <span className="rounded-full bg-white px-3 py-1 text-xs font-bold uppercase text-slate-600">
                {selectedHistoricalRevision.status}
              </span>
            </div>

            {selectedHistoricalRevision.blocks.length === 0 ? (
              <p className="text-xs text-slate-500">This revision has no content blocks.</p>
            ) : (
              <div className="space-y-3">
                {selectedHistoricalRevision.blocks.map((block, index) => (
                  <article key={block.id} className="rounded-xl border border-slate-200 bg-white p-4">
                    <p className="mb-3 text-[10px] font-extrabold uppercase tracking-wide text-slate-500">
                      {index + 1}. {blockTypeName(block)}
                    </p>
                    {block.block_type === "text" && <LessonTextBlock block={block} params={routeParams} />}
                    {block.block_type === "media" && (
                      <LessonMediaBlock block={block} params={routeParams} />
                    )}
                    {block.block_type === "exercise" && (
                      <LessonExerciseBlock block={block} isAdminPreview={true} />
                    )}
                  </article>
                ))}
              </div>
            )}
          </div>
        )}
      </section>
    </div>
  );
}
