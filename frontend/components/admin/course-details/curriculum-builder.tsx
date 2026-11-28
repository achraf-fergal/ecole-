"use client";

import React, { useMemo, useState } from "react";
import Link from "next/link";
import {
  archiveAdminLesson,
  archiveAdminModule,
  createAdminLesson,
  createAdminModule,
  duplicateAdminLesson,
  moveAdminLesson,
  reorderAdminLessons,
  reorderAdminModules,
  updateAdminLesson,
  updateAdminModule,
  type CanonicalLessonKind,
  type CurriculumLesson,
  type CurriculumModule,
  type EffectiveLessonKind,
} from "@/lib/admin-learning";

interface CurriculumBuilderProps {
  centerSlug: string;
  courseSlug: string;
  modules: CurriculumModule[];
  onRefresh: () => Promise<void>;
  renderLessonPreview: (lessonId: number) => React.ReactNode;
  openPreviews: Record<number, boolean>;
  onTogglePreview: (lessonId: number) => void;
}

const KIND_META: Record<
  EffectiveLessonKind,
  { label: string; badgeClass: string; shortHint: string }
> = {
  video: {
    label: "Video",
    badgeClass: "bg-indigo-50 text-indigo-800 border-indigo-200",
    shortHint: "Primary video lesson",
  },
  reading: {
    label: "Reading",
    badgeClass: "bg-teal-50 text-teal-800 border-teal-200",
    shortHint: "Rich text & inline images",
  },
  resources: {
    label: "Resources",
    badgeClass: "bg-amber-50 text-amber-800 border-amber-200",
    shortHint: "Downloadable files & media",
  },
  exercise: {
    label: "Exercise",
    badgeClass: "bg-emerald-50 text-emerald-800 border-emerald-200",
    shortHint: "Graded assessment",
  },
  legacy_mixed: {
    label: "Legacy Mixed",
    badgeClass: "bg-slate-100 text-slate-700 border-slate-300",
    shortHint: "Backward-compatible mixed blocks",
  },
};

const STATUS_BADGES: Record<string, string> = {
  draft: "border-amber-200 bg-amber-50 text-amber-800",
  published: "border-emerald-200 bg-emerald-50 text-emerald-800",
  archived: "border-slate-200 bg-slate-100 text-slate-600",
};

function formatVideoDuration(seconds: number | null): string | null {
  if (!seconds || seconds <= 0) return null;
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${String(secs).padStart(2, "0")}`;
}

export default function CurriculumBuilder({
  centerSlug,
  courseSlug,
  modules,
  onRefresh,
  renderLessonPreview,
  openPreviews,
  onTogglePreview,
}: CurriculumBuilderProps) {
  const [searchQuery, setSearchQuery] = useState("");
  const [kindFilter, setKindFilter] = useState<"all" | EffectiveLessonKind>("all");
  const [statusFilter, setStatusFilter] = useState<"all" | "draft" | "published" | "archived">("all");
  const [moduleFilter, setModuleFilter] = useState<string>("all");

  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Add Module form
  const [showAddModule, setShowAddModule] = useState(false);
  const [newModuleTitle, setNewModuleTitle] = useState("");
  const [newModuleDescription, setNewModuleDescription] = useState("");

  // Edit Module inline state
  const [editingModuleId, setEditingModuleId] = useState<number | null>(null);
  const [editModuleTitle, setEditModuleTitle] = useState("");
  const [editModuleDescription, setEditModuleDescription] = useState("");

  // Add Lesson inline form per module
  const [addingLessonModuleId, setAddingLessonModuleId] = useState<number | null>(null);
  const [newLessonTitle, setNewLessonTitle] = useState("");
  const [newLessonKind, setNewLessonKind] = useState<CanonicalLessonKind>("reading");
  const [newLessonDescription, setNewLessonDescription] = useState("");
  const [newLessonDuration, setNewLessonDuration] = useState("");

  // Edit Lesson metadata inline
  const [editingLessonId, setEditingLessonId] = useState<number | null>(null);
  const [editLessonTitle, setEditLessonTitle] = useState("");
  const [editLessonDescription, setEditLessonDescription] = useState("");
  const [editLessonKind, setEditLessonKind] = useState<EffectiveLessonKind>("reading");
  const [editLessonDuration, setEditLessonDuration] = useState("");

  // Move Lesson modal/inline selector
  const [movingLessonId, setMovingLessonId] = useState<number | null>(null);
  const [moveTargetModuleId, setMoveTargetModuleId] = useState<string>("");

  const hasActiveFilters =
    searchQuery.trim() !== "" ||
    kindFilter !== "all" ||
    statusFilter !== "all" ||
    moduleFilter !== "all";

  const filteredModules = useMemo(() => {
    const query = searchQuery.trim().toLowerCase();
    return modules
      .filter((mod) => moduleFilter === "all" || String(mod.id) === moduleFilter)
      .map((mod) => {
        const lessons = mod.lessons.filter((lesson) => {
          if (kindFilter !== "all" && lesson.effective_kind !== kindFilter) return false;
          if (statusFilter !== "all" && lesson.status !== statusFilter) return false;
          if (query) {
            const hay = `${lesson.title} ${lesson.description ?? ""}`.toLowerCase();
            if (!hay.includes(query)) return false;
          }
          return true;
        });
        return { ...mod, lessons };
      })
      .filter((mod) => {
        if (!hasActiveFilters) return true;
        return mod.lessons.length > 0 || (moduleFilter !== "all" && String(mod.id) === moduleFilter);
      });
  }, [modules, moduleFilter, kindFilter, statusFilter, searchQuery, hasActiveFilters]);

  const runAction = async (key: string, fn: () => Promise<void>) => {
    if (busyKey) return;
    setBusyKey(key);
    setErrorMessage(null);
    try {
      await fn();
      await onRefresh();
    } catch (err: unknown) {
      setErrorMessage(err instanceof Error ? err.message : "Operation failed. Please try again.");
    } finally {
      setBusyKey(null);
    }
  };

  const handleCreateModule = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newModuleTitle.trim()) return;
    await runAction("create-module", async () => {
      await createAdminModule(centerSlug, courseSlug, {
        title: newModuleTitle.trim(),
        description: newModuleDescription.trim() || null,
      });
      setNewModuleTitle("");
      setNewModuleDescription("");
      setShowAddModule(false);
    });
  };

  const handleSaveModule = async (moduleId: number) => {
    if (!editModuleTitle.trim()) return;
    await runAction(`save-module-${moduleId}`, async () => {
      await updateAdminModule(centerSlug, courseSlug, moduleId, {
        title: editModuleTitle.trim(),
        description: editModuleDescription.trim() || null,
      });
      setEditingModuleId(null);
    });
  };

  const handleReorderModule = async (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= modules.length) return;
    const ids = modules.map((m) => m.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];
    await runAction(`reorder-mod-${index}`, async () => {
      await reorderAdminModules(centerSlug, courseSlug, ids);
    });
  };

  const handleArchiveModule = async (mod: CurriculumModule) => {
    if (!window.confirm(`Archive module "${mod.title}" and its lessons? Historical revisions and student attempts will be preserved.`)) {
      return;
    }
    await runAction(`archive-mod-${mod.id}`, async () => {
      await archiveAdminModule(centerSlug, courseSlug, mod.id);
    });
  };

  const handleCreateLesson = async (e: React.FormEvent, moduleId: number) => {
    e.preventDefault();
    if (!newLessonTitle.trim()) return;
    await runAction(`create-lesson-${moduleId}`, async () => {
      await createAdminLesson(centerSlug, courseSlug, moduleId, {
        title: newLessonTitle.trim(),
        description: newLessonDescription.trim() || null,
        content_type: newLessonKind,
        duration_minutes: newLessonDuration.trim() ? Number(newLessonDuration) : null,
      });
      setNewLessonTitle("");
      setNewLessonDescription("");
      setNewLessonDuration("");
      setAddingLessonModuleId(null);
    });
  };

  const handleSaveLesson = async (lessonId: number) => {
    if (!editLessonTitle.trim()) return;
    await runAction(`save-lesson-${lessonId}`, async () => {
      await updateAdminLesson(centerSlug, courseSlug, lessonId, {
        title: editLessonTitle.trim(),
        description: editLessonDescription.trim() || null,
        content_type: editLessonKind,
        duration_minutes: editLessonDuration.trim() ? Number(editLessonDuration) : null,
      });
      setEditingLessonId(null);
    });
  };

  const handleReorderLesson = async (mod: CurriculumModule, index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= mod.lessons.length) return;
    const ids = mod.lessons.map((l) => l.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];
    await runAction(`reorder-lesson-${mod.id}-${index}`, async () => {
      await reorderAdminLessons(centerSlug, courseSlug, mod.id, ids);
    });
  };

  const handleMoveLesson = async (lessonId: number) => {
    const targetId = Number(moveTargetModuleId);
    if (!targetId) return;
    await runAction(`move-lesson-${lessonId}`, async () => {
      await moveAdminLesson(centerSlug, courseSlug, lessonId, targetId);
      setMovingLessonId(null);
      setMoveTargetModuleId("");
    });
  };

  const handleDuplicateLesson = async (lessonId: number) => {
    await runAction(`dup-lesson-${lessonId}`, async () => {
      await duplicateAdminLesson(centerSlug, courseSlug, lessonId);
    });
  };

  const handleArchiveLesson = async (lesson: CurriculumLesson) => {
    if (!window.confirm(`Archive lesson "${lesson.title}"? Published history and student attempts will remain intact.`)) {
      return;
    }
    await runAction(`archive-lesson-${lesson.id}`, async () => {
      await archiveAdminLesson(centerSlug, courseSlug, lesson.id);
    });
  };

  return (
    <section id="course-content" className="scroll-mt-6 space-y-4">
      {/* Header + Add Module CTA */}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-xl font-black text-[#092443]">Curriculum Builder</h2>
          <p className="mt-1 text-xs text-slate-500">
            Organize modules and lessons. Open any lesson to edit its specialized content, revisions, or exercises.
          </p>
        </div>
        <button
          type="button"
          disabled={Boolean(busyKey)}
          onClick={() => setShowAddModule((v) => !v)}
          className="inline-flex min-h-10 items-center rounded-xl bg-[#0e8988] px-4 text-xs font-extrabold text-white shadow-xs transition hover:bg-[#0b7170] disabled:opacity-50"
        >
          {showAddModule ? "Cancel" : "+ Add Module"}
        </button>
      </div>

      {errorMessage && (
        <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-800">
          {errorMessage}
        </div>
      )}

      {/* Add Module Inline Form */}
      {showAddModule && (
        <form
          onSubmit={(e) => void handleCreateModule(e)}
          className="rounded-2xl border border-teal-200 bg-white p-4 shadow-xs space-y-3"
        >
          <h3 className="text-sm font-black text-[#102b4c]">New Module</h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-xs font-semibold text-slate-700">
              Module title
              <input
                type="text"
                required
                maxLength={255}
                value={newModuleTitle}
                onChange={(e) => setNewModuleTitle(e.target.value)}
                placeholder="e.g. Module 1: Everyday Greetings"
                className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-teal-500"
              />
            </label>
            <label className="text-xs font-semibold text-slate-700">
              Description (optional)
              <input
                type="text"
                maxLength={2000}
                value={newModuleDescription}
                onChange={(e) => setNewModuleDescription(e.target.value)}
                placeholder="Brief summary of this module"
                className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-teal-500"
              />
            </label>
          </div>
          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => setShowAddModule(false)}
              className="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={Boolean(busyKey) || !newModuleTitle.trim()}
              className="rounded-xl bg-[#0e8988] px-4 py-2 text-xs font-extrabold text-white disabled:opacity-50"
            >
              {busyKey === "create-module" ? "Creating…" : "Create Module"}
            </button>
          </div>
        </form>
      )}

      {/* Search & Filters */}
      {modules.length > 0 && (
        <div className="grid gap-2.5 rounded-2xl border border-slate-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-4">
          <input
            type="search"
            aria-label="Search lessons"
            placeholder="Search lessons..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="min-h-10 rounded-xl border border-slate-200 px-3 text-xs outline-none focus:border-teal-500"
          />
          <select
            aria-label="Filter by lesson type"
            value={kindFilter}
            onChange={(e) => setKindFilter(e.target.value as "all" | EffectiveLessonKind)}
            className="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs outline-none focus:border-teal-500"
          >
            <option value="all">All lesson types</option>
            <option value="video">Video</option>
            <option value="reading">Reading</option>
            <option value="resources">Resources</option>
            <option value="exercise">Exercise</option>
            <option value="legacy_mixed">Legacy Mixed</option>
          </select>
          <select
            aria-label="Filter by lesson status"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as "all" | "draft" | "published" | "archived")}
            className="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs outline-none focus:border-teal-500"
          >
            <option value="all">All statuses</option>
            <option value="published">Published</option>
            <option value="draft">Draft</option>
            <option value="archived">Archived</option>
          </select>
          <select
            aria-label="Filter by module"
            value={moduleFilter}
            onChange={(e) => setModuleFilter(e.target.value)}
            className="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs outline-none focus:border-teal-500"
          >
            <option value="all">All modules</option>
            {modules.map((m) => (
              <option key={m.id} value={String(m.id)}>
                {m.title}
              </option>
            ))}
          </select>
        </div>
      )}

      {/* Empty States */}
      {modules.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
          <h3 className="text-base font-black text-[#102b4c]">No modules in this course yet</h3>
          <p className="mt-1 text-xs text-slate-500">
            Start structuring your curriculum by creating the first module.
          </p>
          <button
            type="button"
            onClick={() => setShowAddModule(true)}
            className="mt-4 inline-flex min-h-10 items-center rounded-xl bg-[#0e8988] px-4 text-xs font-extrabold text-white"
          >
            + Create First Module
          </button>
        </div>
      ) : filteredModules.length === 0 ? (
        <div className="rounded-2xl border border-slate-200 bg-white p-8 text-center">
          <p className="text-sm font-bold text-slate-700">No lessons match your current filters.</p>
          <button
            type="button"
            onClick={() => {
              setSearchQuery("");
              setKindFilter("all");
              setStatusFilter("all");
              setModuleFilter("all");
            }}
            className="mt-3 rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-[#0e8988] hover:bg-slate-50"
          >
            Reset filters
          </button>
        </div>
      ) : (
        <div className="space-y-4">
          {filteredModules.map((mod) => {
            const realModIndex = modules.findIndex((m) => m.id === mod.id);
            const isEditingMod = editingModuleId === mod.id;

            return (
              <article key={mod.id} className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                {/* Module Header */}
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:p-5">
                  <div className="min-w-0 flex-1">
                    {isEditingMod ? (
                      <div className="space-y-2 max-w-xl">
                        <input
                          type="text"
                          value={editModuleTitle}
                          onChange={(e) => setEditModuleTitle(e.target.value)}
                          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold"
                        />
                        <input
                          type="text"
                          value={editModuleDescription}
                          onChange={(e) => setEditModuleDescription(e.target.value)}
                          placeholder="Module description"
                          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs"
                        />
                        <div className="flex gap-2">
                          <button
                            type="button"
                            disabled={Boolean(busyKey)}
                            onClick={() => void handleSaveModule(mod.id)}
                            className="rounded-lg bg-[#0e8988] px-3 py-1 text-xs font-bold text-white"
                          >
                            Save
                          </button>
                          <button
                            type="button"
                            onClick={() => setEditingModuleId(null)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1 text-xs font-bold text-slate-600"
                          >
                            Cancel
                          </button>
                        </div>
                      </div>
                    ) : (
                      <>
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-[11px] font-extrabold uppercase tracking-wide text-slate-400">
                            Module {realModIndex + 1}
                          </span>
                          <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-extrabold uppercase ${STATUS_BADGES[mod.status] ?? STATUS_BADGES.draft}`}>
                            {mod.status}
                          </span>
                          <span className="text-[11px] text-slate-500">
                            {mod.published_lesson_count}/{mod.lesson_count} published
                          </span>
                        </div>
                        <h3 className="mt-1 text-base font-black text-[#102b4c]">{mod.title}</h3>
                        {mod.description && (
                          <p className="mt-1 text-xs text-slate-600">{mod.description}</p>
                        )}
                      </>
                    )}
                  </div>

                  {/* Module Actions */}
                  <div className="flex flex-wrap items-center gap-1.5">
                    <button
                      type="button"
                      disabled={Boolean(busyKey) || realModIndex <= 0}
                      onClick={() => void handleReorderModule(realModIndex, -1)}
                      title="Move module up"
                      className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                    >
                      ↑
                    </button>
                    <button
                      type="button"
                      disabled={Boolean(busyKey) || realModIndex >= modules.length - 1}
                      onClick={() => void handleReorderModule(realModIndex, 1)}
                      title="Move module down"
                      className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                    >
                      ↓
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setEditingModuleId(mod.id);
                        setEditModuleTitle(mod.title);
                        setEditModuleDescription(mod.description ?? "");
                      }}
                      className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50"
                    >
                      Rename
                    </button>
                    {mod.status !== "published" && (
                      <button
                        type="button"
                        disabled={Boolean(busyKey)}
                        onClick={() => void runAction(`pub-mod-${mod.id}`, () => updateAdminModule(centerSlug, courseSlug, mod.id, { status: "published" }).then(() => undefined))}
                        className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800"
                      >
                        Publish
                      </button>
                    )}
                    {mod.status !== "archived" && (
                      <button
                        type="button"
                        disabled={Boolean(busyKey)}
                        onClick={() => void handleArchiveModule(mod)}
                        className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-600 hover:border-rose-200 hover:text-rose-700"
                      >
                        Archive
                      </button>
                    )}
                    <button
                      type="button"
                      onClick={() => {
                        setAddingLessonModuleId(addingLessonModuleId === mod.id ? null : mod.id);
                        setNewLessonTitle("");
                        setNewLessonDescription("");
                        setNewLessonDuration("");
                        setNewLessonKind("reading");
                      }}
                      className="rounded-lg bg-[#092443] px-3 py-1 text-xs font-extrabold text-white hover:bg-[#123b64]"
                    >
                      + Add Lesson
                    </button>
                  </div>
                </div>

                {/* Add Lesson Inline Form */}
                {addingLessonModuleId === mod.id && (
                  <form
                    onSubmit={(e) => void handleCreateLesson(e, mod.id)}
                    className="border-b border-teal-100 bg-teal-50/40 p-4 space-y-3"
                  >
                    <h4 className="text-xs font-black uppercase tracking-wide text-teal-900">
                      Add Lesson to {mod.title}
                    </h4>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                      <label className="text-xs font-semibold text-slate-700 sm:col-span-2">
                        Lesson title
                        <input
                          type="text"
                          required
                          maxLength={255}
                          value={newLessonTitle}
                          onChange={(e) => setNewLessonTitle(e.target.value)}
                          placeholder="e.g. Present Simple Practice"
                          className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs"
                        />
                      </label>
                      <label className="text-xs font-semibold text-slate-700">
                        Lesson type
                        <select
                          value={newLessonKind}
                          onChange={(e) => setNewLessonKind(e.target.value as CanonicalLessonKind)}
                          className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs"
                        >
                          <option value="video">Video</option>
                          <option value="reading">Reading</option>
                          <option value="resources">Resources</option>
                          <option value="exercise">Exercise</option>
                        </select>
                      </label>
                      <label className="text-xs font-semibold text-slate-700">
                        Duration (min, optional)
                        <input
                          type="number"
                          min={1}
                          max={1440}
                          value={newLessonDuration}
                          onChange={(e) => setNewLessonDuration(e.target.value)}
                          className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs"
                        />
                      </label>
                      <label className="text-xs font-semibold text-slate-700 sm:col-span-4">
                        Short description (optional)
                        <input
                          type="text"
                          maxLength={2000}
                          value={newLessonDescription}
                          onChange={(e) => setNewLessonDescription(e.target.value)}
                          className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs"
                        />
                      </label>
                    </div>
                    <div className="flex justify-end gap-2">
                      <button
                        type="button"
                        onClick={() => setAddingLessonModuleId(null)}
                        className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600"
                      >
                        Cancel
                      </button>
                      <button
                        type="submit"
                        disabled={Boolean(busyKey) || !newLessonTitle.trim()}
                        className="rounded-lg bg-[#0e8988] px-4 py-1.5 text-xs font-extrabold text-white disabled:opacity-50"
                      >
                        {busyKey === `create-lesson-${mod.id}` ? "Creating…" : "Create Lesson"}
                      </button>
                    </div>
                  </form>
                )}

                {/* Lessons List */}
                {mod.lessons.length === 0 ? (
                  <div className="p-6 text-center text-xs text-slate-500">
                    No lessons in this module yet.{" "}
                    <button
                      type="button"
                      onClick={() => setAddingLessonModuleId(mod.id)}
                      className="font-bold text-[#0e8988] hover:underline"
                    >
                      Add your first lesson
                    </button>
                  </div>
                ) : (
                  <div className="divide-y divide-slate-100">
                    {mod.lessons.map((lesson, lessonIndex) => {
                      const kind = lesson.effective_kind ?? "reading";
                      const kindMeta = KIND_META[kind] ?? KIND_META.reading;
                      const isEditingLesson = editingLessonId === lesson.id;
                      const isMovingLesson = movingLessonId === lesson.id;
                      const isPreviewOpen = Boolean(openPreviews[lesson.id]);
                      const editorHref = `/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(courseSlug)}/lessons/${lesson.id}`;
                      const videoDur = formatVideoDuration(lesson.summary?.video_duration_seconds ?? null);

                      return (
                        <div key={lesson.id} className="p-4 sm:px-5">
                          <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0 flex-1">
                              {isEditingLesson ? (
                                <div className="space-y-2 max-w-2xl">
                                  <div className="grid gap-2 sm:grid-cols-3">
                                    <input
                                      type="text"
                                      value={editLessonTitle}
                                      onChange={(e) => setEditLessonTitle(e.target.value)}
                                      className="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold"
                                    />
                                    <select
                                      value={editLessonKind}
                                      onChange={(e) => setEditLessonKind(e.target.value as EffectiveLessonKind)}
                                      className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs"
                                    >
                                      <option value="video">Video</option>
                                      <option value="reading">Reading</option>
                                      <option value="resources">Resources</option>
                                      <option value="exercise">Exercise</option>
                                      {lesson.effective_kind === "legacy_mixed" && (
                                        <option value="legacy_mixed">Legacy Mixed</option>
                                      )}
                                    </select>
                                  </div>
                                  <div className="grid gap-2 sm:grid-cols-3">
                                    <input
                                      type="text"
                                      value={editLessonDescription}
                                      onChange={(e) => setEditLessonDescription(e.target.value)}
                                      placeholder="Short description"
                                      className="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-1.5 text-xs"
                                    />
                                    <input
                                      type="number"
                                      min={1}
                                      max={1440}
                                      value={editLessonDuration}
                                      onChange={(e) => setEditLessonDuration(e.target.value)}
                                      placeholder="Duration (min)"
                                      className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs"
                                    />
                                  </div>
                                  <div className="flex gap-2">
                                    <button
                                      type="button"
                                      disabled={Boolean(busyKey)}
                                      onClick={() => void handleSaveLesson(lesson.id)}
                                      className="rounded-lg bg-[#0e8988] px-3 py-1 text-xs font-bold text-white"
                                    >
                                      Save
                                    </button>
                                    <button
                                      type="button"
                                      onClick={() => setEditingLessonId(null)}
                                      className="rounded-lg border border-slate-300 px-3 py-1 text-xs font-bold text-slate-600"
                                    >
                                      Cancel
                                    </button>
                                  </div>
                                </div>
                              ) : (
                                <>
                                  <div className="flex flex-wrap items-center gap-2">
                                    <span className={`inline-flex rounded-md border px-2 py-0.5 text-[10px] font-extrabold uppercase ${kindMeta.badgeClass}`}>
                                      {kindMeta.label}
                                    </span>
                                    <span className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase ${STATUS_BADGES[lesson.status] ?? STATUS_BADGES.draft}`}>
                                      {lesson.status}
                                    </span>
                                    {lesson.duration_minutes ? (
                                      <span className="text-[11px] font-semibold text-slate-500">
                                        {lesson.duration_minutes} min
                                      </span>
                                    ) : null}
                                  </div>
                                  <h4 className="mt-1.5 text-sm font-bold text-[#102b4c]">{lesson.title}</h4>
                                  {lesson.description && (
                                    <p className="mt-0.5 text-xs text-slate-500 line-clamp-2">{lesson.description}</p>
                                  )}

                                  {/* Type-specific indicators */}
                                  <div className="mt-2 flex flex-wrap gap-2 text-[11px] text-slate-600">
                                    {kind === "video" && (
                                      <span className="rounded-md bg-indigo-50/70 px-2 py-0.5 font-medium text-indigo-800">
                                        {lesson.summary?.has_video
                                          ? `Video ready${videoDur ? ` (${videoDur})` : ""}`
                                          : "No video uploaded yet"}
                                      </span>
                                    )}
                                    {kind === "reading" && (
                                      <span className="rounded-md bg-teal-50/70 px-2 py-0.5 font-medium text-teal-800">
                                        {lesson.summary?.has_reading_content ? "Reading content ready" : "Empty reading draft"}
                                      </span>
                                    )}
                                    {kind === "resources" && (
                                      <span className="rounded-md bg-amber-50/70 px-2 py-0.5 font-medium text-amber-800">
                                        {lesson.summary?.resources_count ?? 0} attached file(s)
                                      </span>
                                    )}
                                    {kind === "exercise" && (
                                      <span className="rounded-md bg-emerald-50/70 px-2 py-0.5 font-medium text-emerald-800">
                                        {lesson.summary?.question_count ?? 0} question(s) · {lesson.summary?.total_points ?? 0} pts
                                      </span>
                                    )}
                                    {kind === "legacy_mixed" && (
                                      <span className="rounded-md bg-slate-100 px-2 py-0.5 font-medium text-slate-700">
                                        {lesson.exercises_count} exercise(s) · {lesson.assets_count} asset(s)
                                      </span>
                                    )}
                                  </div>
                                </>
                              )}
                            </div>

                            {/* Lesson Actions */}
                            <div className="flex flex-wrap items-center gap-1.5">
                              {!hasActiveFilters && (
                                <>
                                  <button
                                    type="button"
                                    disabled={Boolean(busyKey) || lessonIndex <= 0}
                                    onClick={() => void handleReorderLesson(mod, lessonIndex, -1)}
                                    title="Move lesson up"
                                    className="rounded-lg border border-slate-200 px-2 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                                  >
                                    ↑
                                  </button>
                                  <button
                                    type="button"
                                    disabled={Boolean(busyKey) || lessonIndex >= mod.lessons.length - 1}
                                    onClick={() => void handleReorderLesson(mod, lessonIndex, 1)}
                                    title="Move lesson down"
                                    className="rounded-lg border border-slate-200 px-2 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                                  >
                                    ↓
                                  </button>
                                </>
                              )}
                              <button
                                type="button"
                                onClick={() => onTogglePreview(lesson.id)}
                                className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50"
                              >
                                {isPreviewOpen ? "Hide Preview" : "Preview"}
                              </button>
                              <button
                                type="button"
                                onClick={() => {
                                  setEditingLessonId(lesson.id);
                                  setEditLessonTitle(lesson.title);
                                  setEditLessonDescription(lesson.description ?? "");
                                  setEditLessonKind(lesson.effective_kind);
                                  setEditLessonDuration(lesson.duration_minutes ? String(lesson.duration_minutes) : "");
                                }}
                                className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50"
                              >
                                Edit Info
                              </button>
                              {modules.length > 1 && (
                                <button
                                  type="button"
                                  onClick={() => {
                                    setMovingLessonId(isMovingLesson ? null : lesson.id);
                                    const firstOther = modules.find((m) => m.id !== mod.id);
                                    setMoveTargetModuleId(firstOther ? String(firstOther.id) : "");
                                  }}
                                  className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                >
                                  Move
                                </button>
                              )}
                              <button
                                type="button"
                                disabled={Boolean(busyKey)}
                                onClick={() => void handleDuplicateLesson(lesson.id)}
                                className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                              >
                                Duplicate
                              </button>
                              {lesson.status !== "archived" && (
                                <button
                                  type="button"
                                  disabled={Boolean(busyKey)}
                                  onClick={() => void handleArchiveLesson(lesson)}
                                  className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-600 hover:border-rose-200 hover:text-rose-700 disabled:opacity-40"
                                >
                                  Archive
                                </button>
                              )}
                              <Link
                                href={editorHref}
                                className="rounded-lg bg-[#0e8988] px-3 py-1 text-xs font-extrabold text-white hover:bg-[#0b7170]"
                              >
                                Open Editor
                              </Link>
                            </div>
                          </div>

                          {/* Move Lesson Inline Bar */}
                          {isMovingLesson && (
                            <div className="mt-3 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2.5">
                              <span className="text-xs font-semibold text-slate-700">Move to module:</span>
                              <select
                                value={moveTargetModuleId}
                                onChange={(e) => setMoveTargetModuleId(e.target.value)}
                                className="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs"
                              >
                                {modules
                                  .filter((m) => m.id !== mod.id)
                                  .map((m) => (
                                    <option key={m.id} value={String(m.id)}>
                                      {m.title}
                                    </option>
                                  ))}
                              </select>
                              <button
                                type="button"
                                disabled={Boolean(busyKey) || !moveTargetModuleId}
                                onClick={() => void handleMoveLesson(lesson.id)}
                                className="rounded-lg bg-[#092443] px-3 py-1 text-xs font-bold text-white"
                              >
                                Confirm Move
                              </button>
                              <button
                                type="button"
                                onClick={() => setMovingLessonId(null)}
                                className="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-bold text-slate-600"
                              >
                                Cancel
                              </button>
                            </div>
                          )}

                          {/* On-demand Lesson Preview */}
                          {isPreviewOpen && (
                            <div className="mt-3 border-t border-slate-100 pt-3">
                              {renderLessonPreview(lesson.id)}
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </div>
                )}
              </article>
            );
          })}
        </div>
      )}
    </section>
  );
}
