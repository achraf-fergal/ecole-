"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState, type FormEvent } from "react";

import DashboardLayout from "@/components/dashboard/dashboard-layout";
import LessonAssetViewer from "@/components/learning/lesson-asset-viewer";
import {
  AdminLearningApiError,
  getAdminCourseCurriculum,
  getAdminLessonContent,
  triggerCourseRevalidation,
  updateAdminCourseSettings,
  type AdminLessonContent,
  type CurriculumCourseOutcome,
  type CurriculumCourseSummary,
  type CurriculumResponse,
} from "@/lib/admin-learning";
import CurriculumBuilder from "./curriculum-builder";
import {
  apiGetMe,
  type AuthUser,
} from "@/lib/auth";
import {
  apiGetPlatformCourseDetails,
  type PlatformCourseDetails,
  type PlatformCourseStatus,
} from "@/lib/admin";
import type { LessonBlock, TipTapDocument } from "@/lib/learning";

interface CourseDetailsClientProps {
  centerSlug: string;
  courseSlug: string;
}

interface CourseForm {
  title: string;
  slug: string;
  short_description: string;
  description: string;
  prerequisites: string;
  target_audience: string;
  language_id: string;
  level_id: string;
  source_locale: string;
  study_format: "in_person" | "online" | "hybrid";
  duration_hours: string;
  cover_image_url: string;
  cover_image_alt: string;
  pricing_mode: "fixed" | "from" | "upon_request" | "free";
  price: string;
  currency: string;
  is_curriculum_public: boolean;
  meta_title: string;
  meta_description: string;
  outcomes: CurriculumCourseOutcome[];
}

type SettingsTab = "general" | "audience" | "pricing" | "seo";

type LessonPreviewState =
  | { status: "loading" }
  | { status: "loaded"; content: AdminLessonContent }
  | { status: "error"; message: string };

const statusStyles: Record<PlatformCourseStatus, string> = {
  draft: "border-amber-200 bg-amber-50 text-amber-800",
  published: "border-emerald-200 bg-emerald-50 text-emerald-800",
  archived: "border-slate-200 bg-slate-100 text-slate-700",
};

function toCourseForm(course: Partial<CurriculumCourseSummary> & {
  title: string;
  slug: string;
  source_locale: string;
  study_format: "in_person" | "online" | "hybrid";
  duration_hours: number | null;
  cover_image_url: string | null;
  cover_image_alt: string | null;
  short_description: string | null;
  description: string | null;
  language: { id: number } | null;
  level: { id: number } | null;
}): CourseForm {
  return {
    title: course.title,
    slug: course.slug,
    short_description: course.short_description ?? "",
    description: course.description ?? "",
    prerequisites: course.prerequisites ?? "",
    target_audience: course.target_audience ?? "",
    language_id: course.language ? String(course.language.id) : "",
    level_id: course.level ? String(course.level.id) : "",
    source_locale: course.source_locale,
    study_format: course.study_format,
    duration_hours: course.duration_hours === null ? "" : String(course.duration_hours),
    cover_image_url: course.cover_image_url ?? "",
    cover_image_alt: course.cover_image_alt ?? "",
    pricing_mode: course.pricing_mode ?? "upon_request",
    price: course.price ?? "",
    currency: course.currency ?? "MAD",
    is_curriculum_public: course.is_curriculum_public ?? true,
    meta_title: course.meta_title ?? "",
    meta_description: course.meta_description ?? "",
    outcomes: (course.outcomes ?? []).map((o) => ({
      id: o.id,
      content: o.content,
      is_highlight: Boolean(o.is_highlight),
    })),
  };
}

function safePublicImageUrl(value: string | null | undefined): string | null {
  if (!value?.trim()) return null;

  try {
    const url = new URL(value.trim());
    if (url.protocol !== "https:" || url.username || url.password) return null;
    return url.toString();
  } catch {
    return null;
  }
}

function CourseCover({
  src,
  alt,
  title,
}: {
  src: string | null | undefined;
  alt: string;
  title: string;
}) {
  const [failedSource, setFailedSource] = useState<string | null>(null);
  const safeSrc = safePublicImageUrl(src);
  const showImage = safeSrc !== null && failedSource !== safeSrc;

  return (
    <div className="relative aspect-[16/10] overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-[#e7f2f4] to-[#e7edf3] sm:aspect-[4/3]">
      {showImage ? (
        // Course covers are public URLs; lesson asset previews use the protected asset viewer below.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={safeSrc}
          alt={alt || title}
          loading="eager"
          fetchPriority="high"
          onError={() => setFailedSource(safeSrc)}
          className="h-full w-full object-cover"
        />
      ) : (
        <div className="flex h-full flex-col items-center justify-center gap-2 px-5 text-center text-[#0e8988]">
          <svg viewBox="0 0 24 24" className="h-10 w-10" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
            <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z" />
            <path d="M8 8h8M8 12h6" />
          </svg>
          <span className="text-xs font-bold text-slate-600">
            {src ? "Course image unavailable" : "No course image"}
          </span>
        </div>
      )}
    </div>
  );
}

function StatusBadge({ status }: { status: PlatformCourseStatus }) {
  return (
    <span className={`inline-flex rounded-full border px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-wide ${statusStyles[status]}`}>
      {status}
    </span>
  );
}

function formatDate(value: string | null): string {
  if (!value) return "Not published";
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "—"
    : new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(date);
}

function formatRelativeDate(value: string | null): string {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "—"
    : new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(date);
}

function plainText(document: TipTapDocument | null): string {
  if (!document) return "";

  const visit = (node: { text?: string; content?: unknown[] }): string => {
    const ownText = typeof node.text === "string" ? node.text : "";
    const children = Array.isArray(node.content)
      ? node.content.map((child) => {
          if (!child || typeof child !== "object") return "";
          return visit(child as { text?: string; content?: unknown[] });
        }).filter(Boolean)
      : [];
    return [ownText, ...children].filter(Boolean).join(" ");
  };

  return (document.content ?? []).map(visit).filter(Boolean).join(" ").trim();
}

function FieldError({ message, id }: { message?: string; id: string }) {
  if (!message) return null;
  return <p id={id} className="mt-1 text-xs font-semibold text-red-700">{message}</p>;
}

function LessonBlocksPreview({
  blocks,
  centerSlug,
  courseSlug,
  lessonId,
}: {
  blocks: LessonBlock[];
  centerSlug: string;
  courseSlug: string;
  lessonId: number;
}) {
  if (blocks.length === 0) {
    return <p className="rounded-xl bg-slate-50 p-4 text-xs text-slate-500">No content blocks in this revision.</p>;
  }

  return (
    <div className="space-y-3">
      {blocks.map((block) => {
        if (block.block_type === "media") {
          return block.asset ? (
            <LessonAssetViewer
              key={block.id}
              asset={block.asset}
              params={{ centerSlug, courseSlug, lessonId }}
            />
          ) : (
            <p key={block.id} className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800">
              This media block has no available asset.
            </p>
          );
        }

        if (block.block_type === "exercise") {
          return (
            <article key={block.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
              <div className="min-w-0">
                <p className="text-[10px] font-extrabold uppercase tracking-wide text-teal-700">Exercise</p>
                <h4 className="mt-1 truncate text-sm font-bold text-[#102b4c]">{block.exercise?.title ?? "Unavailable exercise"}</h4>
              </div>
              {block.exercise && <StatusBadge status={block.exercise.status} />}
            </article>
          );
        }

        const excerpt = plainText(block.content_json);
        return (
          <article key={block.id} className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-[10px] font-extrabold uppercase tracking-wide text-teal-700">Text content</p>
            <p className="mt-2 line-clamp-4 whitespace-pre-wrap text-xs leading-6 text-slate-600">
              {excerpt || "Formatted text block"}
            </p>
          </article>
        );
      })}
    </div>
  );
}

export default function CourseDetailsClient({
  centerSlug,
  courseSlug,
}: CourseDetailsClientProps) {
  const router = useRouter();
  const [user, setUser] = useState<AuthUser | null>(null);
  const [authResolved, setAuthResolved] = useState(false);
  const [details, setDetails] = useState<PlatformCourseDetails | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [accessDeniedMessage, setAccessDeniedMessage] = useState<string | null>(null);
  const [isEditing, setIsEditing] = useState(false);
  const [settingsTab, setSettingsTab] = useState<SettingsTab>("general");
  const [form, setForm] = useState<CourseForm | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [isChangingStatus, setIsChangingStatus] = useState(false);
  const [saveMessage, setSaveMessage] = useState("");
  const [lessonPreviews, setLessonPreviews] = useState<Record<number, LessonPreviewState>>({});
  const [openPreviews, setOpenPreviews] = useState<Record<number, boolean>>({});
  const [curriculum, setCurriculum] = useState<CurriculumResponse | null>(null);
  const [curriculumStatus, setCurriculumStatus] = useState<"loading" | "loaded" | "error">("loading");
  const [curriculumError, setCurriculumError] = useState<string | null>(null);

  const activeCourse = curriculum?.course ?? details?.course ?? null;
  const activeCenter = curriculum?.center ?? details?.center ?? null;
  const activeStats = curriculum?.statistics ?? details?.statistics ?? null;
  const languageOptions = curriculum?.language_options ?? details?.language_options ?? [];
  const levelOptions = curriculum?.level_options ?? details?.level_options ?? [];

  const savedForm = useMemo(
    () => (activeCourse ? toCourseForm(activeCourse) : null),
    [activeCourse]
  );
  const isDirty = Boolean(form && savedForm && JSON.stringify(form) !== JSON.stringify(savedForm));

  useEffect(() => {
    let active = true;
    void apiGetMe().then((authenticatedUser) => {
      if (!active) return;
      setUser(authenticatedUser);
    }).catch(() => {
      if (active) setUser(null);
    }).finally(() => {
      if (active) setAuthResolved(true);
    });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!authResolved) return;
    if (!user) {
      setIsLoading(false);
      return;
    }

    let active = true;
    // Phase B 4.2: Reset all stale course-specific state on navigation
    setIsLoading(true);
    setPageError("");
    setAccessDeniedMessage(null);
    setSaveMessage("");
    setDetails(null);
    setCurriculum(null);
    setCurriculumStatus("loading");
    setCurriculumError(null);
    setLessonPreviews({});
    setOpenPreviews({});
    setIsEditing(false);
    setFieldErrors({});
    setForm(null);

    const loadPlatformDetails = user.is_platform_admin
      ? apiGetPlatformCourseDetails(centerSlug, courseSlug).catch((err: unknown) => {
          if (active && !curriculum) {
            setPageError(err instanceof Error ? err.message : "Unable to load course details.");
          }
          return null;
        })
      : Promise.resolve(null);

    const loadCurriculum = getAdminCourseCurriculum(centerSlug, courseSlug)
      .then((curr) => {
        if (!active) return null;
        setCurriculum(curr);
        setCurriculumStatus("loaded");
        setCurriculumError(null);
        return curr;
      })
      .catch((err: unknown) => {
        if (!active) return null;
        if (err instanceof AdminLearningApiError && (err.status === 401 || err.status === 403)) {
          setAccessDeniedMessage(err.message || "You do not have permission to manage this course.");
        }
        setCurriculumStatus("error");
        setCurriculumError(err instanceof Error ? err.message : "Could not load curriculum.");
        return null;
      });

    void Promise.all([loadPlatformDetails, loadCurriculum])
      .then(([platformResult, currResult]) => {
        if (!active) return;
        if (platformResult) {
          setDetails(platformResult);
        }
        const sourceCourse = currResult?.course ?? platformResult?.course;
        if (sourceCourse) {
          setForm(toCourseForm(sourceCourse));
        }
      })
      .finally(() => {
        if (active) setIsLoading(false);
      });

    return () => {
      active = false;
    };
  }, [authResolved, centerSlug, courseSlug, user]);

  async function refreshCurriculum() {
    setCurriculumError(null);
    try {
      const curr = await getAdminCourseCurriculum(centerSlug, activeCourse?.slug ?? courseSlug);
      setCurriculum(curr);
      setCurriculumStatus("loaded");
      setForm((prev) => (isEditing ? prev : toCourseForm(curr.course)));
      await triggerCourseRevalidation(curr.course.slug);
    } catch (err: unknown) {
      setCurriculumStatus("error");
      setCurriculumError(err instanceof Error ? err.message : "Failed to refresh curriculum.");
      throw err;
    }
  }

  useEffect(() => {
    if (!isDirty) return;
    const warnOnLeave = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warnOnLeave);
    return () => window.removeEventListener("beforeunload", warnOnLeave);
  }, [isDirty]);

  function updateField<K extends keyof CourseForm>(key: K, value: CourseForm[K]) {
    setForm((current) => current ? { ...current, [key]: value } : current);
    setFieldErrors((current) => ({ ...current, [key]: [] }));
    setPageError("");
    setSaveMessage("");
  }

  function formPayload(nextForm: CourseForm): Record<string, unknown> {
    return {
      title: nextForm.title.trim(),
      slug: nextForm.slug.trim(),
      short_description: nextForm.short_description.trim() || null,
      description: nextForm.description.trim() || null,
      prerequisites: nextForm.prerequisites.trim() || null,
      target_audience: nextForm.target_audience.trim() || null,
      language_id: Number(nextForm.language_id),
      level_id: Number(nextForm.level_id),
      source_locale: nextForm.source_locale.trim(),
      study_format: nextForm.study_format,
      duration_hours: nextForm.duration_hours.trim() ? Number(nextForm.duration_hours) : null,
      cover_image_url: nextForm.cover_image_url.trim() || null,
      cover_image_alt: nextForm.cover_image_alt.trim() || null,
      pricing_mode: nextForm.pricing_mode,
      price: nextForm.pricing_mode === "fixed" || nextForm.pricing_mode === "from"
        ? (nextForm.price.trim() ? Number(nextForm.price) : null)
        : null,
      currency: nextForm.currency.trim() || "MAD",
      is_curriculum_public: nextForm.is_curriculum_public,
      meta_title: nextForm.meta_title.trim() || null,
      meta_description: nextForm.meta_description.trim() || null,
      outcomes: nextForm.outcomes
        .filter((o) => o.content.trim() !== "")
        .map((o) => ({ content: o.content.trim(), is_highlight: Boolean(o.is_highlight) })),
    };
  }

  async function handleSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!form || !activeCourse || isSaving) return;

    setIsSaving(true);
    setPageError("");
    setFieldErrors({});
    setSaveMessage("");

    try {
      const updated = await updateAdminCourseSettings(
        centerSlug,
        activeCourse.slug,
        formPayload(form)
      );
      setCurriculum((current) => current ? { ...current, course: updated } : current);
      setForm(toCourseForm(updated));
      setIsEditing(false);
      setSaveMessage("Course settings saved.");
      await triggerCourseRevalidation(updated.slug);
      if (updated.slug !== courseSlug) {
        router.replace(`/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(updated.slug)}`);
      }
    } catch (error: unknown) {
      if (error instanceof AdminLearningApiError) {
        setPageError(error.message);
        setFieldErrors(error.errors ?? {});
      } else {
        setPageError(error instanceof Error ? error.message : "Unable to save course changes.");
      }
    } finally {
      setIsSaving(false);
    }
  }

  async function handleStatusChange(status: PlatformCourseStatus) {
    if (!activeCourse || isChangingStatus || isDirty) {
      if (isDirty) setPageError("Save or cancel the course edits before changing its status.");
      return;
    }
    if (status === "archived" && !window.confirm("Archive this course? It will no longer appear as an active learning course.")) return;

    setIsChangingStatus(true);
    setPageError("");
    setSaveMessage("");
    try {
      const updated = await updateAdminCourseSettings(centerSlug, activeCourse.slug, { status });
      setCurriculum((current) => current ? { ...current, course: updated } : current);
      setForm(toCourseForm(updated));
      setSaveMessage(status === "published" ? "Course published." : status === "archived" ? "Course archived." : "Course moved to draft.");
      await triggerCourseRevalidation(updated.slug);
    } catch (error: unknown) {
      setPageError(error instanceof Error ? error.message : "Unable to change course status.");
    } finally {
      setIsChangingStatus(false);
    }
  }

  async function loadLessonPreview(lessonId: number) {
    if (lessonPreviews[lessonId]) return;
    setLessonPreviews((current) => ({ ...current, [lessonId]: { status: "loading" } }));
    try {
      const content = await getAdminLessonContent(centerSlug, details?.course.slug ?? courseSlug, lessonId);
      setLessonPreviews((current) => ({ ...current, [lessonId]: { status: "loaded", content } }));
    } catch (error: unknown) {
      setLessonPreviews((current) => ({
        ...current,
        [lessonId]: {
          status: "error",
          message: error instanceof AdminLearningApiError || error instanceof Error
            ? error.message
            : "Unable to load lesson content.",
        },
      }));
    }
  }

  if (!authResolved || isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <p className="text-sm font-bold text-slate-500">Loading course workspace…</p>
      </div>
    );
  }

  if (!user || accessDeniedMessage) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <div className="max-w-md rounded-2xl border border-red-200 bg-white p-8 text-center">
          <h1 className="text-xl font-black text-slate-900">Access Restricted</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">
            {accessDeniedMessage
              ? accessDeniedMessage
              : "Sign in with a center administrator or platform administrator account to manage this course."}
          </p>
          {!user && (
            <Link
              href={`/login?redirect=${encodeURIComponent(`/admin/learning/centers/${centerSlug}/courses/${courseSlug}`)}`}
              className="mt-5 inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-[#102b4c]"
            >
              Sign in
            </Link>
          )}
          <Link href="/admin/learning" className="mt-5 inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-bold text-white">Return to learning centers</Link>
        </div>
      </main>
    );
  }

  const course = activeCourse;
  const courseListHref = `/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses`;

  return (
    <DashboardLayout user={user} showCenterSwitcher={false}>
      <div className="mx-auto max-w-7xl space-y-6 pb-12">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Link href={courseListHref} className="inline-flex min-h-10 items-center gap-2 text-xs font-bold text-[#0e8988] hover:underline">
            <span aria-hidden="true">←</span> {activeCenter?.name ?? "Courses"}
          </Link>
          <span className="text-xs text-slate-500">{activeCenter?.slug ?? centerSlug}</span>
        </div>

        {pageError && (
          <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {pageError}
          </div>
        )}
        {saveMessage && (
          <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">
            {saveMessage}
          </div>
        )}

        {!course ? (
          <section className="rounded-2xl border border-slate-200 bg-white p-8 text-center">
            <h1 className="text-lg font-black text-[#102b4c]">Course details unavailable</h1>
            <p className="mt-2 text-sm text-slate-600">{pageError || "This course may have been removed."}</p>
            <Link href={courseListHref} className="mt-5 inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-bold text-white">Back to courses</Link>
          </section>
        ) : (
          <>
            <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 lg:grid-cols-[minmax(230px,0.72fr)_minmax(0,1.6fr)] lg:items-center">
              <CourseCover
                src={isEditing && form ? form.cover_image_url : course.cover_image_url}
                alt={isEditing && form ? form.cover_image_alt : course.cover_image_alt ?? course.title}
                title={course.title}
              />

              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <StatusBadge status={course.status} />
                  {course.language && <span className="rounded-full bg-teal-50 px-2.5 py-1 text-[10px] font-bold text-teal-800">{course.language.name}</span>}
                  {course.level && <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-700">{course.level.name}</span>}
                </div>
                <h1 className="mt-3 break-words text-2xl font-black leading-tight text-[#092443] sm:text-3xl">{course.title}</h1>
                <p className="mt-1 break-all text-xs text-slate-500">{course.slug}</p>
                <p className="mt-3 whitespace-pre-line text-sm leading-6 text-slate-600">
                  {course.short_description || course.description || "No course description has been added."}
                </p>
                <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-[11px] text-slate-500">
                  <span>Language: <strong className="text-slate-700">{course.language?.name ?? "Not set"}</strong></span>
                  <span>Level: <strong className="text-slate-700">{course.level?.name ?? "Not set"}</strong></span>
                  <span>Published: <strong className="text-slate-700">{formatDate(course.published_at)}</strong></span>
                  <span>Updated: <strong className="text-slate-700">{formatRelativeDate(course.updated_at)}</strong></span>
                </div>
                <div className="mt-5 flex flex-wrap gap-2">
                  {!isEditing ? (
                    <button type="button" onClick={() => { setForm(toCourseForm(course)); setIsEditing(true); setPageError(""); }} className="inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-extrabold text-white hover:bg-[#123b64]">Edit Course</button>
                  ) : (
                    <a href="#course-edit" className="inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-extrabold text-white">Editing Course</a>
                  )}
                  <a href="#course-content" className="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-extrabold text-[#102b4c] hover:border-teal-300 hover:text-teal-800">Manage Content</a>
                  <Link
                    href={`/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(course.slug)}/preview`}
                    className="inline-flex min-h-10 items-center rounded-xl border border-indigo-200 bg-indigo-50 px-4 text-xs font-extrabold text-indigo-800 hover:bg-indigo-100"
                  >
                    Draft Preview
                  </Link>
                  {course.status === "published" ? (
                    <Link href={`/courses/${encodeURIComponent(course.slug)}`} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-extrabold text-[#102b4c] hover:border-teal-300 hover:text-teal-800">Public Page</Link>
                  ) : (
                    <button type="button" disabled title="Publish the course to open its public page." className="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-400">Public page unavailable</button>
                  )}
                  {course.status === "draft" && <button type="button" disabled={isChangingStatus} onClick={() => void handleStatusChange("published")} className="inline-flex min-h-10 items-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 text-xs font-extrabold text-emerald-800 disabled:opacity-50">Publish</button>}
                  {course.status === "published" && <button type="button" disabled={isChangingStatus} onClick={() => void handleStatusChange("draft")} className="inline-flex min-h-10 items-center rounded-xl border border-amber-200 bg-amber-50 px-4 text-xs font-extrabold text-amber-800 disabled:opacity-50">Unpublish</button>}
                  {course.status === "archived" ? (
                    <button type="button" disabled={isChangingStatus} onClick={() => void handleStatusChange("draft")} className="inline-flex min-h-10 items-center rounded-xl border border-slate-300 bg-slate-100 px-4 text-xs font-extrabold text-slate-700 disabled:opacity-50">Restore to Draft</button>
                  ) : (
                    <button type="button" disabled={isChangingStatus} onClick={() => void handleStatusChange("archived")} className="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600 hover:border-red-200 hover:text-red-700 disabled:opacity-50">Archive</button>
                  )}
                </div>
              </div>
            </section>

            {isEditing && form && (
              <section id="course-edit" className="scroll-mt-6 rounded-2xl border border-teal-200 bg-white p-5 shadow-sm sm:p-6">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h2 className="text-lg font-black text-[#102b4c]">Course Settings</h2>
                    <p className="mt-1 text-xs leading-5 text-slate-500">Configure general details, learning objectives, pricing, visibility, and SEO metadata.</p>
                  </div>
                  <span className={`rounded-full px-2.5 py-1 text-[10px] font-bold ${isDirty ? "bg-amber-50 text-amber-800" : "bg-emerald-50 text-emerald-800"}`}>
                    {isDirty ? "Unsaved changes" : "All changes saved"}
                  </span>
                </div>

                {/* Settings Tabs */}
                <div role="tablist" aria-label="Course settings sections" className="mb-5 flex flex-wrap gap-2 border-b border-slate-100 pb-3">
                  {(
                    [
                      ["general", "General Info"],
                      ["audience", "Objectives & Audience"],
                      ["pricing", "Pricing & Visibility"],
                      ["seo", "SEO Metadata"],
                    ] as [SettingsTab, string][]
                  ).map(([tabKey, tabLabel]) => (
                    <button
                      key={tabKey}
                      type="button"
                      role="tab"
                      aria-selected={settingsTab === tabKey}
                      onClick={() => setSettingsTab(tabKey)}
                      className={`rounded-xl px-3.5 py-2 text-xs font-bold transition ${
                        settingsTab === tabKey
                          ? "bg-[#092443] text-white"
                          : "border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100"
                      }`}
                    >
                      {tabLabel}
                    </button>
                  ))}
                </div>

                <form onSubmit={(event) => void handleSave(event)} className="space-y-5">
                  {settingsTab === "general" && (
                  <div className="grid gap-4 md:grid-cols-2">
                    <div>
                      <label htmlFor="course-title" className="text-xs font-bold text-slate-700">Course title</label>
                      <input id="course-title" value={form.title} onChange={(event) => updateField("title", event.target.value)} required maxLength={255} aria-invalid={Boolean(fieldErrors.title?.length)} aria-describedby={fieldErrors.title?.length ? "course-title-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-title-error" message={fieldErrors.title?.[0]} />
                    </div>
                    <div>
                      <label htmlFor="course-slug" className="text-xs font-bold text-slate-700">URL slug</label>
                      <input id="course-slug" value={form.slug} onChange={(event) => updateField("slug", event.target.value)} required maxLength={150} aria-invalid={Boolean(fieldErrors.slug?.length)} aria-describedby={fieldErrors.slug?.length ? "course-slug-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-slug-error" message={fieldErrors.slug?.[0]} />
                    </div>
                    <div>
                      <label htmlFor="course-language" className="text-xs font-bold text-slate-700">Language</label>
                      <select id="course-language" value={form.language_id} onChange={(event) => updateField("language_id", event.target.value)} required aria-invalid={Boolean(fieldErrors.language_id?.length)} aria-describedby={fieldErrors.language_id?.length ? "course-language-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400">
                        {languageOptions.map((language) => <option key={language.id} value={language.id}>{language.name} ({language.code})</option>)}
                      </select>
                      <FieldError id="course-language-error" message={fieldErrors.language_id?.[0]} />
                    </div>
                    <div>
                      <label htmlFor="course-level" className="text-xs font-bold text-slate-700">Level</label>
                      <select id="course-level" value={form.level_id} onChange={(event) => updateField("level_id", event.target.value)} required aria-invalid={Boolean(fieldErrors.level_id?.length)} aria-describedby={fieldErrors.level_id?.length ? "course-level-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400">
                        {levelOptions.map((level) => <option key={level.id} value={level.id}>{level.name} ({level.code})</option>)}
                      </select>
                      <FieldError id="course-level-error" message={fieldErrors.level_id?.[0]} />
                    </div>
                    <div>
                      <label htmlFor="course-locale" className="text-xs font-bold text-slate-700">Content locale</label>
                      <input id="course-locale" value={form.source_locale} onChange={(event) => updateField("source_locale", event.target.value)} required maxLength={35} aria-invalid={Boolean(fieldErrors.source_locale?.length)} aria-describedby={fieldErrors.source_locale?.length ? "course-locale-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-locale-error" message={fieldErrors.source_locale?.[0]} />
                    </div>
                    <div>
                      <label htmlFor="course-study-format" className="text-xs font-bold text-slate-700">Study format</label>
                      <select id="course-study-format" value={form.study_format} onChange={(event) => updateField("study_format", event.target.value as CourseForm["study_format"])} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-teal-500">
                        <option value="in_person">In person</option>
                        <option value="online">Online</option>
                        <option value="hybrid">Hybrid</option>
                      </select>
                    </div>
                    <div>
                      <label htmlFor="course-duration" className="text-xs font-bold text-slate-700">Duration (hours)</label>
                      <input id="course-duration" type="number" min="0" max="65535" value={form.duration_hours} onChange={(event) => updateField("duration_hours", event.target.value)} aria-invalid={Boolean(fieldErrors.duration_hours?.length)} aria-describedby={fieldErrors.duration_hours?.length ? "course-duration-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-duration-error" message={fieldErrors.duration_hours?.[0]} />
                    </div>
                    <div className="md:col-span-2">
                      <label htmlFor="course-short-description" className="text-xs font-bold text-slate-700">Short description</label>
                      <textarea id="course-short-description" value={form.short_description} onChange={(event) => updateField("short_description", event.target.value)} rows={2} maxLength={500} aria-invalid={Boolean(fieldErrors.short_description?.length)} aria-describedby={fieldErrors.short_description?.length ? "course-short-description-error" : undefined} className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-short-description-error" message={fieldErrors.short_description?.[0]} />
                    </div>
                    <div className="md:col-span-2">
                      <label htmlFor="course-description" className="text-xs font-bold text-slate-700">Course description</label>
                      <textarea id="course-description" value={form.description} onChange={(event) => updateField("description", event.target.value)} rows={5} maxLength={50000} aria-invalid={Boolean(fieldErrors.description?.length)} aria-describedby={fieldErrors.description?.length ? "course-description-error" : undefined} className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm leading-6 outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-description-error" message={fieldErrors.description?.[0]} />
                    </div>
                    <div>
                      <label htmlFor="course-cover-url" className="text-xs font-bold text-slate-700">Cover image URL</label>
                      <input id="course-cover-url" type="url" value={form.cover_image_url} onChange={(event) => updateField("cover_image_url", event.target.value)} placeholder="https://…" aria-invalid={Boolean(fieldErrors.cover_image_url?.length)} aria-describedby={fieldErrors.cover_image_url?.length ? "course-cover-url-error" : "course-cover-url-help"} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <p id="course-cover-url-help" className="mt-1 text-[10px] text-slate-500">Clear the field to remove the image.</p>
                      <FieldError id="course-cover-url-error" message={fieldErrors.cover_image_url?.[0]} />
                    </div>
                    <div>
                      <label htmlFor="course-cover-alt" className="text-xs font-bold text-slate-700">Cover image alt text</label>
                      <input id="course-cover-alt" value={form.cover_image_alt} onChange={(event) => updateField("cover_image_alt", event.target.value)} maxLength={255} aria-invalid={Boolean(fieldErrors.cover_image_alt?.length)} aria-describedby={fieldErrors.cover_image_alt?.length ? "course-cover-alt-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-cover-alt-error" message={fieldErrors.cover_image_alt?.[0]} />
                    </div>
                  </div>
                  )}

                  {settingsTab === "audience" && (
                  <div className="space-y-4">
                    <div>
                      <label htmlFor="course-target-audience" className="text-xs font-bold text-slate-700">Target audience</label>
                      <textarea id="course-target-audience" rows={3} maxLength={10000} value={form.target_audience} onChange={(e) => updateField("target_audience", e.target.value)} placeholder="Who is this course designed for?" className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                    <div>
                      <label htmlFor="course-prerequisites" className="text-xs font-bold text-slate-700">Prerequisites</label>
                      <textarea id="course-prerequisites" rows={3} maxLength={10000} value={form.prerequisites} onChange={(e) => updateField("prerequisites", e.target.value)} placeholder="Required prior level or placement test..." className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-bold text-slate-700">Learning Objectives / Outcomes</span>
                        <button
                          type="button"
                          onClick={() => updateField("outcomes", [...form.outcomes, { content: "", is_highlight: false }])}
                          className="rounded-lg border border-teal-300 px-2.5 py-1 text-xs font-bold text-teal-800 hover:bg-teal-50"
                        >
                          + Add Objective
                        </button>
                      </div>
                      {form.outcomes.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-slate-200 p-4 text-xs text-slate-500">No learning objectives added yet.</p>
                      ) : (
                        <div className="space-y-2">
                          {form.outcomes.map((outcome, idx) => (
                            <div key={idx} className="flex flex-wrap items-center gap-2">
                              <input
                                type="text"
                                maxLength={500}
                                value={outcome.content}
                                onChange={(e) => {
                                  const next = [...form.outcomes];
                                  next[idx] = { ...next[idx], content: e.target.value };
                                  updateField("outcomes", next);
                                }}
                                placeholder={`Objective ${idx + 1}`}
                                className="min-w-[200px] flex-1 rounded-xl border border-slate-200 px-3 py-2 text-xs outline-none focus:border-teal-500"
                              />
                              <label className="flex items-center gap-1.5 text-xs text-slate-700">
                                <input
                                  type="checkbox"
                                  checked={Boolean(outcome.is_highlight)}
                                  onChange={(e) => {
                                    const next = [...form.outcomes];
                                    next[idx] = { ...next[idx], is_highlight: e.target.checked };
                                    updateField("outcomes", next);
                                  }}
                                />
                                Highlight
                              </label>
                              <button
                                type="button"
                                onClick={() => updateField("outcomes", form.outcomes.filter((_, i) => i !== idx))}
                                className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50"
                              >
                                Remove
                              </button>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  </div>
                  )}

                  {settingsTab === "pricing" && (
                  <div className="grid gap-4 md:grid-cols-2">
                    <div>
                      <label htmlFor="course-pricing-mode" className="text-xs font-bold text-slate-700">Pricing mode</label>
                      <select id="course-pricing-mode" value={form.pricing_mode} onChange={(e) => updateField("pricing_mode", e.target.value as CourseForm["pricing_mode"])} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-teal-500">
                        <option value="upon_request">Upon request</option>
                        <option value="fixed">Fixed price</option>
                        <option value="from">Starting from</option>
                        <option value="free">Free</option>
                      </select>
                    </div>
                    <div>
                      <label htmlFor="course-currency" className="text-xs font-bold text-slate-700">Currency</label>
                      <input id="course-currency" maxLength={10} value={form.currency} onChange={(e) => updateField("currency", e.target.value)} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                    {(form.pricing_mode === "fixed" || form.pricing_mode === "from") && (
                      <div>
                        <label htmlFor="course-price" className="text-xs font-bold text-slate-700">Price</label>
                        <input id="course-price" type="number" step="0.01" min="0" value={form.price} onChange={(e) => updateField("price", e.target.value)} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500" />
                      </div>
                    )}
                    <div className="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                      <label className="flex items-center gap-2.5 text-xs font-bold text-slate-800">
                        <input
                          type="checkbox"
                          checked={form.is_curriculum_public}
                          onChange={(e) => updateField("is_curriculum_public", e.target.checked)}
                        />
                        Show published curriculum outline on the public course page
                      </label>
                      <p className="mt-1 text-[11px] text-slate-500">
                        Only published modules and published lessons are visible publicly; private files and draft content are never exposed.
                      </p>
                    </div>
                  </div>
                  )}

                  {settingsTab === "seo" && (
                  <div className="space-y-4">
                    <div>
                      <label htmlFor="course-meta-title" className="text-xs font-bold text-slate-700">SEO Meta Title</label>
                      <input id="course-meta-title" maxLength={255} value={form.meta_title} onChange={(e) => updateField("meta_title", e.target.value)} placeholder={form.title} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                    <div>
                      <label htmlFor="course-meta-description" className="text-xs font-bold text-slate-700">SEO Meta Description</label>
                      <textarea id="course-meta-description" rows={3} maxLength={500} value={form.meta_description} onChange={(e) => updateField("meta_description", e.target.value)} placeholder={form.short_description} className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                  </div>
                  )}

                  <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" disabled={isSaving} onClick={() => { setForm(toCourseForm(course)); setIsEditing(false); setFieldErrors({}); setPageError(""); }} className="min-h-10 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700 disabled:opacity-50">Cancel</button>
                    <button type="submit" disabled={isSaving || !isDirty} className="min-h-10 rounded-xl bg-[#0e8988] px-5 text-xs font-extrabold text-white disabled:cursor-not-allowed disabled:opacity-50">{isSaving ? "Saving…" : "Save Changes"}</button>
                  </div>
                </form>
              </section>
            )}

            {activeStats && (
              <section aria-label="Course statistics" className="grid gap-3 grid-cols-2 sm:grid-cols-4 xl:grid-cols-7">
                {[
                  ["Modules", activeStats.modules],
                  ["Lessons", activeStats.lessons],
                  ["Published", activeStats.published_lessons],
                  ["Draft", activeStats.draft_lessons],
                  ["Archived", activeStats.archived_lessons],
                  ["Exercises", activeStats.exercises],
                  ["Assets", activeStats.assets],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-xl border border-slate-200 bg-white p-4">
                    <p className="text-xl font-black tabular-nums text-[#092443]">{value}</p>
                    <p className="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-500">{label}</p>
                  </div>
                ))}
              </section>
            )}

            {curriculumStatus === "loading" ? (
              <section className="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                <p role="status" className="text-sm font-bold text-slate-500">Loading curriculum…</p>
              </section>
            ) : curriculumStatus === "error" ? (
              <section role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-center space-y-3">
                <h2 className="text-base font-black text-rose-900">Unable to load curriculum</h2>
                <p className="text-xs text-rose-800">{curriculumError || "An error occurred while loading modules and lessons."}</p>
                <button
                  type="button"
                  onClick={() => {
                    setCurriculumStatus("loading");
                    void refreshCurriculum().catch(() => undefined);
                  }}
                  className="inline-flex min-h-10 items-center rounded-xl bg-rose-700 px-4 text-xs font-bold text-white hover:bg-rose-800"
                >
                  Retry Curriculum Load
                </button>
              </section>
            ) : (
            <CurriculumBuilder
              centerSlug={centerSlug}
              courseSlug={course.slug}
              modules={curriculum?.modules ?? []}
              onRefresh={refreshCurriculum}
              openPreviews={openPreviews}
              onTogglePreview={(lessonId) => {
                const nextOpen = !openPreviews[lessonId];
                setOpenPreviews((prev) => ({ ...prev, [lessonId]: nextOpen }));
                if (nextOpen) {
                  void loadLessonPreview(lessonId);
                }
              }}
              renderLessonPreview={(lessonId) => {
                const preview = lessonPreviews[lessonId];
                if (!preview || preview.status === "loading") {
                  return <p role="status" className="rounded-xl bg-slate-50 p-4 text-xs text-slate-500">Loading lesson content…</p>;
                }
                if (preview.status === "error") {
                  return (
                    <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-xs text-red-800">
                      <p>{preview.message}</p>
                      <button
                        type="button"
                        onClick={() => {
                          setLessonPreviews((current) => {
                            const next = { ...current };
                            delete next[lessonId];
                            return next;
                          });
                          void loadLessonPreview(lessonId);
                        }}
                        className="mt-2 font-bold underline"
                      >
                        Retry
                      </button>
                    </div>
                  );
                }
                const revision = preview.content.draft_revision ?? preview.content.published_revision;
                return (
                  <div className="space-y-3">
                    {revision && (
                      <p className="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                        {revision.status} revision · v{revision.version_number}
                      </p>
                    )}
                    <LessonBlocksPreview
                      blocks={revision?.blocks ?? []}
                      centerSlug={centerSlug}
                      courseSlug={course.slug}
                      lessonId={lessonId}
                    />
                  </div>
                );
              }}
            />
            )}
          </>
        )}
      </div>
    </DashboardLayout>
  );
}
