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
  publishAdminModule,
  reorderAdminLessons,
  reorderAdminModules,
  restoreAdminLesson,
  restoreAdminModule,
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
  { label: string; badgeClass: string; thumbClass: string; shortHint: string }
> = {
  video: {
    label: "Video",
    badgeClass: "bg-indigo-50 text-indigo-800 border-indigo-200",
    thumbClass: "bg-indigo-50 text-indigo-700 border-indigo-200",
    shortHint: "Primary video with optional notes",
  },
  reading: {
    label: "Reading",
    badgeClass: "bg-teal-50 text-teal-800 border-teal-200",
    thumbClass: "bg-teal-50 text-teal-700 border-teal-200",
    shortHint: "Rich text & inline images",
  },
  resources: {
    label: "Resources",
    badgeClass: "bg-amber-50 text-amber-800 border-amber-200",
    thumbClass: "bg-amber-50 text-amber-700 border-amber-200",
    shortHint: "Downloadable files & handouts",
  },
  exercise: {
    label: "Exercise",
    badgeClass: "bg-emerald-50 text-emerald-800 border-emerald-200",
    thumbClass: "bg-emerald-50 text-emerald-700 border-emerald-200",
    shortHint: "Graded assessment & feedback",
  },
  legacy_mixed: {
    label: "Mixed Content",
    badgeClass: "bg-slate-100 text-slate-700 border-slate-300",
    thumbClass: "bg-slate-100 text-slate-600 border-slate-300",
    shortHint: "Legacy mixed lesson blocks",
  },
};

const CREATE_KIND_CARDS: { value: CanonicalLessonKind; label: string; description: string }[] = [
  { value: "video", label: "Video", description: "Upload a video or link YouTube / Google Drive." },
  { value: "reading", label: "Reading", description: "Structured article with headings and inline images." },
  { value: "resources", label: "Resources", description: "PDFs, worksheets, audio, or downloadable files." },
  { value: "exercise", label: "Exercise", description: "Interactive quiz with auto-grading and review." },
];

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

function LessonKindThumbnail({ kind }: { kind: EffectiveLessonKind }) {
  const meta = KIND_META[kind] ?? KIND_META.reading;
  const common = {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.8,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    className: "h-6 w-6",
    "aria-hidden": true as const,
  };

  return (
    <div
      className={`flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-xl border ${meta.thumbClass}`}
      title={meta.label}
    >
      {kind === "video" ? (
        <svg {...common}>
          <rect x="3" y="5" width="18" height="14" rx="2" />
          <path d="m10 9 5 3-5 3z" />
        </svg>
      ) : kind === "resources" ? (
        <svg {...common}>
          <path d="M12 3v12m-5-5 5 5 5-5" />
          <path d="M5 17v4h14v-4" />
        </svg>
      ) : kind === "exercise" ? (
        <svg {...common}>
          <rect x="4" y="3" width="16" height="18" rx="2" />
          <path d="m8 9 2 2 4-4M8 16h8" />
        </svg>
      ) : (
        <svg {...common}>
          <path d="M5 3h10l4 4v14H5z" />
          <path d="M14 3v5h5M8 13h8M8 17h8" />
        </svg>
      )}
    </div>
  );
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

  // Collapsible state (default expanded)
  const [collapsedModules, setCollapsedModules] = useState<Record<number, boolean>>({});

  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [refreshWarning, setRefreshWarning] = useState<string | null>(null);
  const [createdLessonNotice, setCreatedLessonNotice] = useState<{ id: number; title: string } | null>(null);

  // Overflow menu state
  const [openMenuLessonId, setOpenMenuLessonId] = useState<number | null>(null);

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
  const [movePlacement, setMovePlacement] = useState<"first" | "last" | "before" | "after">("last");
  const [moveAnchorLessonId, setMoveAnchorLessonId] = useState<string>("");

  const hasActiveFilters =
    searchQuery.trim() !== "" ||
    kindFilter !== "all" ||
    statusFilter !== "all" ||
    moduleFilter !== "all";

  const totalLessonCount = useMemo(
    () => modules.reduce((acc, m) => acc + m.lessons.length, 0),
    [modules]
  );

  const filteredModules = useMemo(() => {
    const query = searchQuery.trim().toLowerCase();
    return modules
      .filter((mod) => moduleFilter === "all" || String(mod.id) === moduleFilter)
      .map((mod) => {
        const moduleMatchesQuery = query
          ? `${mod.title} ${mod.description ?? ""}`.toLowerCase().includes(query)
          : false;

        const lessons = mod.lessons.filter((lesson) => {
          if (kindFilter !== "all" && lesson.effective_kind !== kindFilter) return false;
          if (statusFilter !== "all" && lesson.status !== statusFilter) return false;
          if (query) {
            const lessonMatches = `${lesson.title} ${lesson.description ?? ""}`.toLowerCase().includes(query);
            if (!lessonMatches && !moduleMatchesQuery) return false;
          }
          return true;
        });
        return { ...mod, lessons, moduleMatchesQuery };
      })
      .filter((mod) => {
        if (!hasActiveFilters) return true;
        if (mod.lessons.length > 0) return true;
        if (mod.moduleMatchesQuery && kindFilter === "all" && statusFilter === "all") return true;
        return moduleFilter !== "all" && String(mod.id) === moduleFilter;
      });
  }, [modules, moduleFilter, kindFilter, statusFilter, searchQuery, hasActiveFilters]);

  const filteredLessonCount = useMemo(
    () => filteredModules.reduce((acc, m) => acc + m.lessons.length, 0),
    [filteredModules]
  );

  // Separate mutation failure from refresh failure (Phase C 5.7)
  const runAction = async (key: string, fn: () => Promise<void>) => {
    if (busyKey) return;
    setBusyKey(key);
    setErrorMessage(null);
    setRefreshWarning(null);
    try {
      await fn();
    } catch (err: unknown) {
      setErrorMessage(err instanceof Error ? err.message : "Operation failed. Please try again.");
      setBusyKey(null);
      return;
    }

    try {
      await onRefresh();
    } catch (err: unknown) {
      setRefreshWarning(
        err instanceof Error
          ? `Changes were saved, but the curriculum list could not be refreshed: ${err.message}`
          : "Changes were saved, but the curriculum list could not be refreshed."
      );
    } finally {
      setBusyKey(null);
    }
  };

  const toggleModuleCollapse = (moduleId: number) => {
    setCollapsedModules((prev) => ({ ...prev, [moduleId]: !prev[moduleId] }));
  };

  const expandAllModules = () => setCollapsedModules({});
  const collapseAllModules = () => {
    const next: Record<number, boolean> = {};
    for (const m of modules) next[m.id] = true;
    setCollapsedModules(next);
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
    const activeModules = modules.filter((m) => m.status !== "archived");
    const target = index + direction;
    if (target < 0 || target >= activeModules.length) return;
    const ids = activeModules.map((m) => m.id);
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
      const created = await createAdminLesson(centerSlug, courseSlug, moduleId, {
        title: newLessonTitle.trim(),
        description: newLessonDescription.trim() || null,
        content_type: newLessonKind,
        duration_minutes: newLessonDuration.trim() ? Number(newLessonDuration) : null,
      });
      setCreatedLessonNotice({ id: created.id, title: created.title });
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

  const handleReorderLesson = async (mod: CurriculumModule, activeIndex: number, direction: -1 | 1) => {
    const activeLessons = mod.lessons.filter((l) => l.status !== "archived");
    const target = activeIndex + direction;
    if (target < 0 || target >= activeLessons.length) return;
    const ids = activeLessons.map((l) => l.id);
    [ids[activeIndex], ids[target]] = [ids[target], ids[activeIndex]];
    await runAction(`reorder-lesson-${mod.id}-${activeIndex}`, async () => {
      await reorderAdminLessons(centerSlug, courseSlug, mod.id, ids);
    });
  };

  const handleMoveLesson = async (lessonId: number) => {
    const targetId = Number(moveTargetModuleId);
    if (!targetId) return;
    await runAction(`move-lesson-${lessonId}`, async () => {
      await moveAdminLesson(centerSlug, courseSlug, lessonId, targetId, {
        placement: movePlacement,
        anchorLessonId:
          (movePlacement === "before" || movePlacement === "after") && moveAnchorLessonId
            ? Number(moveAnchorLessonId)
            : null,
      });
      setMovingLessonId(null);
      setMoveTargetModuleId("");
      setMoveAnchorLessonId("");
      setMovePlacement("last");
    });
  };

  const handleDuplicateLesson = async (lessonId: number) => {
    setOpenMenuLessonId(null);
    await runAction(`dup-lesson-${lessonId}`, async () => {
      await duplicateAdminLesson(centerSlug, courseSlug, lessonId);
    });
  };

  const handleArchiveLesson = async (lesson: CurriculumLesson) => {
    setOpenMenuLessonId(null);
    if (!window.confirm(`Archive lesson "${lesson.title}"? Published history and student attempts will remain intact.`)) {
      return;
    }
    await runAction(`archive-lesson-${lesson.id}`, async () => {
      await archiveAdminLesson(centerSlug, courseSlug, lesson.id);
    });
  };

  const handleRestoreLesson = async (lesson: CurriculumLesson) => {
    setOpenMenuLessonId(null);
    await runAction(`restore-lesson-${lesson.id}`, async () => {
      await restoreAdminLesson(centerSlug, courseSlug, lesson.id);
    });
  };

  const activeDestinationModules = modules.filter((m) => m.status !== "archived");

  return (
    <section id="course-content" className="scroll-mt-6 space-y-4">
      {/* Header + Collapse/Expand + Add Module CTA */}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-xl font-black text-[#092443]">Curriculum Builder</h2>
          <p className="mt-1 text-xs text-slate-500">
            Organize modules and lessons. Open any lesson to edit its specialized content, revisions, or exercises.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {modules.length > 0 && (
            <>
              <button
                type="button"
                onClick={expandAllModules}
                className="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50"
              >
                Expand All
              </button>
              <button
                type="button"
                onClick={collapseAllModules}
                className="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50"
              >
                Collapse All
              </button>
            </>
          )}
          <button
            type="button"
            disabled={Boolean(busyKey)}
            onClick={() => setShowAddModule((v) => !v)}
            className="inline-flex min-h-10 items-center rounded-xl bg-[#0e8988] px-4 text-xs font-extrabold text-white shadow-xs transition hover:bg-[#0b7170] disabled:opacity-50"
          >
            {showAddModule ? "Cancel" : "+ Add Module"}
          </button>
        </div>
      </div>

      {errorMessage && (
        <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-800">
          {errorMessage}
        </div>
      )}

      {refreshWarning && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-900">
          <span>{refreshWarning}</span>
          <button
            type="button"
            onClick={() => void runAction("manual-refresh", async () => undefined)}
            className="rounded-lg border border-amber-400 bg-white px-3 py-1 font-bold text-amber-900 hover:bg-amber-100"
          >
            Refresh Now
          </button>
        </div>
      )}

      {createdLessonNotice && (
        <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-900">
          <span>
            Lesson <strong>{createdLessonNotice.title}</strong> created as Draft.
          </span>
          <div className="flex items-center gap-2">
            <Link
              href={`/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(courseSlug)}/lessons/${createdLessonNotice.id}`}
              className="rounded-lg bg-[#0e8988] px-3 py-1.5 font-extrabold text-white"
            >
              Open Lesson Editor →
            </Link>
            <button
              type="button"
              onClick={() => setCreatedLessonNotice(null)}
              className="text-xs font-bold text-emerald-800 hover:underline"
            >
              Dismiss
            </button>
          </div>
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
        <div className="space-y-2 rounded-2xl border border-slate-200 bg-white p-3">
          <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
            <input
              type="search"
              aria-label="Search modules and lessons"
              placeholder="Search modules or lessons..."
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
              <option value="legacy_mixed">Mixed Content</option>
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
          {hasActiveFilters && (
            <div className="flex flex-wrap items-center justify-between gap-2 px-1 text-[11px] text-slate-600">
              <span>
                Showing <strong>{filteredLessonCount}</strong> of <strong>{totalLessonCount}</strong> total lessons across {filteredModules.length} module(s). Reordering is disabled while filtered.
              </span>
              <button
                type="button"
                onClick={() => {
                  setSearchQuery("");
                  setKindFilter("all");
                  setStatusFilter("all");
                  setModuleFilter("all");
                }}
                className="font-bold text-[#0e8988] hover:underline"
              >
                Reset filters
              </button>
            </div>
          )}
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
          <p className="text-sm font-bold text-slate-700">No modules or lessons match your current filters.</p>
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
        <div className={`grid gap-6 ${modules.length >= 3 ? "xl:grid-cols-[220px_minmax(0,1fr)]" : ""}`}>
          {/* Desktop Course Index for large courses */}
          {modules.length >= 3 && (
            <aside aria-label="Course outline" className="hidden xl:block">
              <div className="sticky top-6 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
                <p className="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">
                  Course Index ({modules.length})
                </p>
                <nav className="mt-2.5 space-y-1">
                  {filteredModules.map((m, idx) => (
                    <a
                      key={m.id}
                      href={`#module-${m.id}`}
                      onClick={() => {
                        if (collapsedModules[m.id]) toggleModuleCollapse(m.id);
                      }}
                      className="flex items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-[#0e8988]"
                    >
                      <span className="truncate">{idx + 1}. {m.title}</span>
                      <span className="shrink-0 text-[10px] text-slate-400">{m.lessons.length}</span>
                    </a>
                  ))}
                </nav>
              </div>
            </aside>
          )}

          <div className="space-y-4">
            {filteredModules.map((mod) => {
              const activeModules = modules.filter((m) => m.status !== "archived");
              const activeModIndex = activeModules.findIndex((m) => m.id === mod.id);
              const realModIndex = modules.findIndex((m) => m.id === mod.id);
              const isEditingMod = editingModuleId === mod.id;
              const isCollapsed = Boolean(collapsedModules[mod.id]);
              const isModuleArchived = mod.status === "archived";

              return (
                <article
                  key={mod.id}
                  id={`module-${mod.id}`}
                  className="scroll-mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs"
                >
                  {/* Module Header */}
                  <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:p-5">
                    <div className="flex min-w-0 flex-1 items-start gap-2.5">
                      <button
                        type="button"
                        aria-expanded={!isCollapsed}
                        aria-label={isCollapsed ? `Expand ${mod.title}` : `Collapse ${mod.title}`}
                        onClick={() => toggleModuleCollapse(mod.id)}
                        className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:bg-slate-100"
                      >
                        {isCollapsed ? "▸" : "▾"}
                      </button>

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
                    </div>

                    {/* Module Actions */}
                    <div className="flex flex-wrap items-center gap-1.5">
                      {!hasActiveFilters && !isModuleArchived && (
                        <>
                          <button
                            type="button"
                            disabled={Boolean(busyKey) || activeModIndex <= 0}
                            onClick={() => void handleReorderModule(activeModIndex, -1)}
                            title="Move module up"
                            aria-label={`Move ${mod.title} up`}
                            className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                          >
                            ↑
                          </button>
                          <button
                            type="button"
                            disabled={Boolean(busyKey) || activeModIndex >= activeModules.length - 1}
                            onClick={() => void handleReorderModule(activeModIndex, 1)}
                            title="Move module down"
                            aria-label={`Move ${mod.title} down`}
                            className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                          >
                            ↓
                          </button>
                        </>
                      )}
                      <button
                        type="button"
                        onClick={() => {
                          setEditingModuleId(mod.id);
                          setEditModuleTitle(mod.title);
                          setEditModuleDescription(mod.description ?? "");
                        }}
                        className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50"
                      >
                        Rename
                      </button>
                      {mod.status === "draft" && (
                        <button
                          type="button"
                          disabled={Boolean(busyKey)}
                          onClick={() => void runAction(`pub-mod-${mod.id}`, () => publishAdminModule(centerSlug, courseSlug, mod.id))}
                          className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-800"
                        >
                          Publish
                        </button>
                      )}
                      {isModuleArchived ? (
                        <button
                          type="button"
                          disabled={Boolean(busyKey)}
                          onClick={() => void runAction(`res-mod-${mod.id}`, () => restoreAdminModule(centerSlug, courseSlug, mod.id))}
                          className="rounded-lg border border-teal-200 bg-teal-50 px-2.5 py-1.5 text-xs font-bold text-teal-800"
                        >
                          Restore to Draft
                        </button>
                      ) : (
                        <>
                          <button
                            type="button"
                            disabled={Boolean(busyKey)}
                            onClick={() => void handleArchiveModule(mod)}
                            className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:border-rose-200 hover:text-rose-700"
                          >
                            Archive
                          </button>
                          <button
                            type="button"
                            onClick={() => {
                              if (isCollapsed) toggleModuleCollapse(mod.id);
                              setAddingLessonModuleId(addingLessonModuleId === mod.id ? null : mod.id);
                              setNewLessonTitle("");
                              setNewLessonDescription("");
                              setNewLessonDuration("");
                              setNewLessonKind("reading");
                            }}
                            className="rounded-lg bg-[#092443] px-3 py-1.5 text-xs font-extrabold text-white hover:bg-[#123b64]"
                          >
                            + Add Lesson
                          </button>
                        </>
                      )}
                    </div>
                  </div>

                  {!isCollapsed && (
                    <>
                      {/* Add Lesson Inline Form with Visual Type Cards */}
                      {addingLessonModuleId === mod.id && !isModuleArchived && (
                        <form
                          onSubmit={(e) => void handleCreateLesson(e, mod.id)}
                          className="border-b border-teal-100 bg-teal-50/40 p-4 space-y-4"
                        >
                          <h4 className="text-xs font-black uppercase tracking-wide text-teal-900">
                            Add Lesson to {mod.title}
                          </h4>

                          <div role="radiogroup" aria-label="Lesson type" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            {CREATE_KIND_CARDS.map((card) => {
                              const active = newLessonKind === card.value;
                              return (
                                <button
                                  key={card.value}
                                  type="button"
                                  role="radio"
                                  aria-checked={active}
                                  onClick={() => setNewLessonKind(card.value)}
                                  className={`rounded-xl border p-3 text-left transition ${
                                    active
                                      ? "border-[#0e8988] bg-white ring-1 ring-[#0e8988]"
                                      : "border-slate-200 bg-white/80 hover:border-slate-300"
                                  }`}
                                >
                                  <p className="text-xs font-extrabold text-[#102b4c]">{card.label}</p>
                                  <p className="mt-1 text-[11px] text-slate-500">{card.description}</p>
                                </button>
                              );
                            })}
                          </div>

                          <div className="grid gap-3 sm:grid-cols-3">
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
                            <label className="text-xs font-semibold text-slate-700 sm:col-span-3">
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
                          No lessons match in this module.{" "}
                          {!isModuleArchived && !hasActiveFilters && (
                            <button
                              type="button"
                              onClick={() => setAddingLessonModuleId(mod.id)}
                              className="font-bold text-[#0e8988] hover:underline"
                            >
                              Add your first lesson
                            </button>
                          )}
                        </div>
                      ) : (
                        <div className="divide-y divide-slate-100">
                          {mod.lessons.map((lesson) => {
                            const activeLessons = mod.lessons.filter((l) => l.status !== "archived");
                            const activeLessonIndex = activeLessons.findIndex((l) => l.id === lesson.id);
                            const kind = lesson.effective_kind ?? "reading";
                            const kindMeta = KIND_META[kind] ?? KIND_META.reading;
                            const isEditingLesson = editingLessonId === lesson.id;
                            const isMovingLesson = movingLessonId === lesson.id;
                            const isPreviewOpen = Boolean(openPreviews[lesson.id]);
                            const isMenuOpen = openMenuLessonId === lesson.id;
                            const isLessonArchived = lesson.status === "archived";
                            const editorHref = `/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(courseSlug)}/lessons/${lesson.id}`;
                            const videoDur = formatVideoDuration(lesson.summary?.video_duration_seconds ?? null);

                            const moveDestinationLessons =
                              modules.find((m) => String(m.id) === moveTargetModuleId)?.lessons.filter(
                                (l) => l.id !== lesson.id && l.status !== "archived"
                              ) ?? [];

                            return (
                              <div key={lesson.id} className="p-4 sm:px-5">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                  <div className="flex min-w-0 flex-1 items-start gap-3.5">
                                    <LessonKindThumbnail kind={kind} />

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
                                                <option value="legacy_mixed">Mixed Content</option>
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
                                            {lesson.has_draft_changes && lesson.status === "published" && (
                                              <span className="inline-flex rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-800">
                                                Draft changes
                                              </span>
                                            )}
                                            {lesson.duration_minutes ? (
                                              <span className="text-[11px] font-semibold text-slate-500">
                                                {lesson.duration_minutes} min
                                              </span>
                                            ) : null}
                                          </div>
                                          <h4 className="mt-1 text-sm font-bold text-[#102b4c]">{lesson.title}</h4>
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
                                                {lesson.summary?.resources_count ?? 0} valid resource(s)
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
                                            {(lesson.summary?.broken_media_count ?? 0) > 0 && (
                                              <span className="rounded-md bg-rose-50 px-2 py-0.5 font-semibold text-rose-700">
                                                {lesson.summary.broken_media_count} missing/archived asset(s)
                                              </span>
                                            )}
                                          </div>
                                        </>
                                      )}
                                    </div>
                                  </div>

                                  {/* Primary Actions + Overflow Menu (Phase C 5.4) */}
                                  <div className="relative flex flex-wrap items-center gap-1.5">
                                    {!hasActiveFilters && !isLessonArchived && (
                                      <>
                                        <button
                                          type="button"
                                          disabled={Boolean(busyKey) || activeLessonIndex <= 0}
                                          onClick={() => void handleReorderLesson(mod, activeLessonIndex, -1)}
                                          title="Move lesson up"
                                          aria-label={`Move ${lesson.title} up`}
                                          className="min-h-9 rounded-lg border border-slate-200 px-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                                        >
                                          ↑
                                        </button>
                                        <button
                                          type="button"
                                          disabled={Boolean(busyKey) || activeLessonIndex >= activeLessons.length - 1}
                                          onClick={() => void handleReorderLesson(mod, activeLessonIndex, 1)}
                                          title="Move lesson down"
                                          aria-label={`Move ${lesson.title} down`}
                                          className="min-h-9 rounded-lg border border-slate-200 px-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                                        >
                                          ↓
                                        </button>
                                      </>
                                    )}
                                    <button
                                      type="button"
                                      onClick={() => onTogglePreview(lesson.id)}
                                      className="min-h-9 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                    >
                                      {isPreviewOpen ? "Hide" : "Preview"}
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
                                      className="min-h-9 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                    >
                                      Edit
                                    </button>
                                    <Link
                                      href={editorHref}
                                      className="inline-flex min-h-9 items-center rounded-lg bg-[#0e8988] px-3.5 text-xs font-extrabold text-white hover:bg-[#0b7170]"
                                    >
                                      Open Editor
                                    </Link>

                                    <button
                                      type="button"
                                      aria-expanded={isMenuOpen}
                                      aria-label={`More actions for ${lesson.title}`}
                                      onClick={() => setOpenMenuLessonId(isMenuOpen ? null : lesson.id)}
                                      className="min-h-9 rounded-lg border border-slate-200 px-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                    >
                                      ⋯
                                    </button>

                                    {isMenuOpen && (
                                      <div className="absolute right-0 top-10 z-20 w-44 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
                                        {!isLessonArchived && activeDestinationModules.length > 0 && (
                                          <button
                                            type="button"
                                            onClick={() => {
                                              setOpenMenuLessonId(null);
                                              setMovingLessonId(isMovingLesson ? null : lesson.id);
                                              const defaultDest =
                                                activeDestinationModules.find((m) => m.id !== mod.id) ??
                                                activeDestinationModules[0];
                                              setMoveTargetModuleId(defaultDest ? String(defaultDest.id) : "");
                                              setMovePlacement("last");
                                              setMoveAnchorLessonId("");
                                            }}
                                            className="block w-full rounded-lg px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                          >
                                            Move To…
                                          </button>
                                        )}
                                        {!isModuleArchived && (
                                          <button
                                            type="button"
                                            disabled={Boolean(busyKey)}
                                            onClick={() => void handleDuplicateLesson(lesson.id)}
                                            className="block w-full rounded-lg px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                                          >
                                            Duplicate
                                          </button>
                                        )}
                                        {isLessonArchived ? (
                                          !isModuleArchived && (
                                            <button
                                              type="button"
                                              disabled={Boolean(busyKey)}
                                              onClick={() => void handleRestoreLesson(lesson)}
                                              className="block w-full rounded-lg px-3 py-2 text-left text-xs font-semibold text-teal-700 hover:bg-teal-50 disabled:opacity-40"
                                            >
                                              Restore to Draft
                                            </button>
                                          )
                                        ) : (
                                          <button
                                            type="button"
                                            disabled={Boolean(busyKey)}
                                            onClick={() => void handleArchiveLesson(lesson)}
                                            className="block w-full rounded-lg px-3 py-2 text-left text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-40"
                                          >
                                            Archive
                                          </button>
                                        )}
                                      </div>
                                    )}
                                  </div>
                                </div>

                                {/* Move Lesson Inline Bar with Exact Placement (Phase C 5.5) */}
                                {isMovingLesson && (
                                  <div className="mt-3 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <span className="text-xs font-bold text-slate-700">Move to:</span>
                                    <select
                                      aria-label="Destination module"
                                      value={moveTargetModuleId}
                                      onChange={(e) => {
                                        setMoveTargetModuleId(e.target.value);
                                        setMoveAnchorLessonId("");
                                      }}
                                      className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs"
                                    >
                                      {activeDestinationModules.map((m) => (
                                        <option key={m.id} value={String(m.id)}>
                                          {m.title}
                                        </option>
                                      ))}
                                    </select>
                                    <select
                                      aria-label="Placement"
                                      value={movePlacement}
                                      onChange={(e) =>
                                        setMovePlacement(e.target.value as "first" | "last" | "before" | "after")
                                      }
                                      className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs"
                                    >
                                      <option value="first">First in module</option>
                                      <option value="last">Last in module</option>
                                      {moveDestinationLessons.length > 0 && (
                                        <>
                                          <option value="before">Before lesson…</option>
                                          <option value="after">After lesson…</option>
                                        </>
                                      )}
                                    </select>
                                    {(movePlacement === "before" || movePlacement === "after") && (
                                      <select
                                        aria-label="Anchor lesson"
                                        value={moveAnchorLessonId}
                                        onChange={(e) => setMoveAnchorLessonId(e.target.value)}
                                        className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs"
                                      >
                                        <option value="">Select lesson…</option>
                                        {moveDestinationLessons.map((l) => (
                                          <option key={l.id} value={String(l.id)}>
                                            {l.title}
                                          </option>
                                        ))}
                                      </select>
                                    )}
                                    <button
                                      type="button"
                                      disabled={
                                        Boolean(busyKey) ||
                                        !moveTargetModuleId ||
                                        ((movePlacement === "before" || movePlacement === "after") && !moveAnchorLessonId)
                                      }
                                      onClick={() => void handleMoveLesson(lesson.id)}
                                      className="rounded-lg bg-[#092443] px-3 py-1.5 text-xs font-bold text-white disabled:opacity-40"
                                    >
                                      Confirm Move
                                    </button>
                                    <button
                                      type="button"
                                      onClick={() => setMovingLessonId(null)}
                                      className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-600"
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
                    </>
                  )}
                </article>
              );
            })}
          </div>
        </div>
      )}
    </section>
  );
}
