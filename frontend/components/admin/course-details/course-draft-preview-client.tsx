"use client";

import React, { useEffect, useState } from "react";
import Link from "next/link";
import { apiGetMe, type AuthUser } from "@/lib/auth";
import {
  getAdminCoursePreview,
  type AdminCoursePreviewData,
} from "@/lib/admin-learning";
import LessonTextBlock from "@/components/learning/lesson-text-block";
import LessonMediaBlock from "@/components/learning/lesson-media-block";
import LessonExerciseBlock from "@/components/learning/lesson-exercise-block";

interface Props {
  centerSlug: string;
  courseSlug: string;
}

type UiLocale = "en" | "fr";

const PREVIEW_I18N: Record<
  UiLocale,
  {
    loading: string;
    unavailableTitle: string;
    unavailableText: string;
    backToWorkspace: string;
    backToEditor: string;
    privateBadge: string;
    draftChangesBadge: string;
    neverPublishedBadge: string;
    draftTab: string;
    publishedTab: string;
    outcomesTitle: string;
    audienceTitle: string;
    prerequisitesTitle: string;
    curriculumTitle: string;
    draftView: string;
    publishedView: string;
    emptyPublished: string;
    emptyDraft: string;
    emptyLessonBlocks: string;
    expandLesson: string;
    hideLesson: string;
    video: string;
    reading: string;
    resources: string;
    exercise: string;
    mixed: string;
    free: string;
    uponRequest: string;
    from: string;
    publishedMetadataNote: string;
    draftStatus: string;
    publishedStatus: string;
    archivedStatus: string;
    previewLanguage: string;
    previewVersion: string;
  }
> = {
  en: {
    loading: "Loading private course preview…",
    unavailableTitle: "Preview Unavailable",
    unavailableText: "Sign in with an authorized administrator account to view this private preview.",
    backToWorkspace: "Back to Course Workspace",
    backToEditor: "← Back to Editor",
    privateBadge: "Private Admin Preview · noindex",
    draftChangesBadge: "Unpublished Draft Changes",
    neverPublishedBadge: "Initial Draft (Not Published Yet)",
    draftTab: "Draft Version",
    publishedTab: "Published Version",
    outcomesTitle: "What you will learn",
    audienceTitle: "Target Audience",
    prerequisitesTitle: "Prerequisites",
    curriculumTitle: "Curriculum",
    draftView: "Draft View",
    publishedView: "Published View",
    emptyPublished: "No published modules or lessons are visible yet in the published version.",
    emptyDraft: "No active modules in this course yet.",
    emptyLessonBlocks: "No content blocks authored in this revision yet.",
    expandLesson: "Inspect Lesson Content",
    hideLesson: "Hide Content",
    video: "Video",
    reading: "Reading",
    resources: "Resources",
    exercise: "Exercise",
    mixed: "Mixed Content",
    free: "Free",
    uponRequest: "Price upon request",
    from: "From",
    publishedMetadataNote: "Published View uses published Lesson revisions and curriculum structure. Course details on this page reflect current settings because historical Course metadata snapshots are not available.",
    draftStatus: "Draft",
    publishedStatus: "Published",
    archivedStatus: "Archived",
    previewLanguage: "Preview language",
    previewVersion: "Preview version",
  },
  fr: {
    loading: "Chargement de l'aperçu privé du cours…",
    unavailableTitle: "Aperçu indisponible",
    unavailableText: "Connectez-vous avec un compte administrateur autorisé pour consulter cet aperçu privé.",
    backToWorkspace: "Retour à l'espace du cours",
    backToEditor: "← Retour à l'éditeur",
    privateBadge: "Aperçu admin privé · noindex",
    draftChangesBadge: "Modifications en brouillon non publiées",
    neverPublishedBadge: "Brouillon initial (non publié)",
    draftTab: "Version brouillon",
    publishedTab: "Version publiée",
    outcomesTitle: "Ce que vous allez apprendre",
    audienceTitle: "Public cible",
    prerequisitesTitle: "Prérequis",
    curriculumTitle: "Programme",
    draftView: "Vue brouillon",
    publishedView: "Vue publiée",
    emptyPublished: "Aucun module ou leçon publié n'est encore visible dans la version publiée.",
    emptyDraft: "Aucun module actif dans ce cours pour le moment.",
    emptyLessonBlocks: "Aucun bloc de contenu rédigé dans cette révision.",
    expandLesson: "Inspecter le contenu",
    hideLesson: "Masquer le contenu",
    video: "Vidéo",
    reading: "Lecture",
    resources: "Ressources",
    exercise: "Exercice",
    mixed: "Contenu mixte",
    free: "Gratuit",
    uponRequest: "Tarif sur demande",
    from: "À partir de",
    publishedMetadataNote: "La vue publiée utilise les révisions des leçons et la structure du programme publiées. Les informations du cours affichées ici reflètent les paramètres actuels, car les instantanés historiques des métadonnées du cours ne sont pas disponibles.",
    draftStatus: "Brouillon",
    publishedStatus: "Publié",
    archivedStatus: "Archivé",
    previewLanguage: "Langue de l'aperçu",
    previewVersion: "Version de l'aperçu",
  },
};

function normalizeLabel(raw: string, t: (typeof PREVIEW_I18N)["en"]): string {
  switch (raw) {
    case "video":
      return t.video;
    case "reading":
    case "text":
      return t.reading;
    case "resources":
    case "resource":
    case "file":
    case "image":
      return t.resources;
    case "exercise":
    case "quiz":
      return t.exercise;
    case "legacy_mixed":
    case "mixed":
      return t.mixed;
    default:
      return t.reading;
  }
}

export default function CourseDraftPreviewClient({ centerSlug, courseSlug }: Props) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [authResolved, setAuthResolved] = useState(false);
  const [version, setVersion] = useState<"draft" | "published">("draft");
  const [uiLocale, setUiLocale] = useState<UiLocale>("en");
  const [data, setData] = useState<AdminCoursePreviewData | null>(null);
  const [loadState, setLoadState] = useState<{ key: string; error?: string } | null>(null);
  const [expandedLessons, setExpandedLessons] = useState<Record<number, boolean>>({});
  const [coverFailed, setCoverFailed] = useState(false);

  const t = PREVIEW_I18N[uiLocale];
  const requestKey = JSON.stringify([centerSlug, courseSlug, version]);

  useEffect(() => {
    let active = true;
    void apiGetMe()
      .then((u) => {
        if (active) setUser(u);
      })
      .catch(() => {
        if (active) setUser(null);
      })
      .finally(() => {
        if (active) setAuthResolved(true);
      });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (!authResolved) return;
    if (!user) {
      return;
    }

    let active = true;
    void getAdminCoursePreview(centerSlug, courseSlug, version)
      .then((res) => {
        if (active) {
          setData(res);
          setLoadState({ key: requestKey });
          // Expand the first lesson automatically for immediate inspection
          const firstLessonId = res.modules[0]?.lessons[0]?.id;
          if (firstLessonId) {
            setExpandedLessons({ [firstLessonId]: true });
          }
        }
      })
      .catch((err: unknown) => {
        if (active) {
          setLoadState({
            key: requestKey,
            error: err instanceof Error ? err.message : "Failed to load preview.",
          });
        }
      });

    return () => {
      active = false;
    };
  }, [authResolved, user, centerSlug, courseSlug, version, requestKey]);

  const backHref = `/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(courseSlug)}`;

  if (!authResolved || (Boolean(user) && loadState?.key !== requestKey)) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <p className="text-sm font-bold text-slate-500">{t.loading}</p>
      </main>
    );
  }

  if (!user || loadState?.error) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <div className="max-w-md rounded-2xl border border-rose-200 bg-white p-8 text-center">
          <h1 className="text-lg font-black text-slate-900">{t.unavailableTitle}</h1>
          <p className="mt-2 text-xs text-slate-600">{loadState?.error || t.unavailableText}</p>
          <Link
            href={backHref}
            className="mt-5 inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-bold text-white"
          >
            {t.backToWorkspace}
          </Link>
        </div>
      </main>
    );
  }

  if (!data) return null;

  const outcomesForLocale = (data.course.outcomes ?? []).filter(
    (o) => !o.locale || o.locale === uiLocale
  );

  const pricingLabel =
    data.course.pricing_mode === "free"
      ? t.free
      : data.course.pricing_mode === "fixed" && data.course.price
      ? `${data.course.price} ${data.course.currency || "MAD"}`
      : data.course.pricing_mode === "from" && data.course.price
      ? `${t.from} ${data.course.price} ${data.course.currency || "MAD"}`
      : t.uponRequest;

  return (
    <main className="min-h-screen bg-[#f8fafc] pb-16">
      <div className="sticky top-0 z-30 border-b border-indigo-200 bg-indigo-950 px-4 py-3 text-white shadow-sm">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-2.5">
            <span className="rounded-full bg-indigo-500/30 px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wider text-indigo-100">
              {t.privateBadge}
            </span>
            {data.is_never_published ? (
              <span className="rounded-full bg-slate-200 px-2.5 py-0.5 text-[10px] font-extrabold uppercase text-slate-900">
                {t.neverPublishedBadge}
              </span>
            ) : data.has_draft_changes ? (
              <span className="rounded-full bg-amber-400 px-2.5 py-0.5 text-[10px] font-extrabold uppercase text-amber-950">
                {t.draftChangesBadge}
              </span>
            ) : null}
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <div role="group" aria-label={t.previewLanguage} className="flex rounded-lg bg-indigo-900 p-0.5">
              {(["en", "fr"] as UiLocale[]).map((loc) => (
                <button
                  key={loc}
                  type="button"
                  onClick={() => setUiLocale(loc)}
                  className={`rounded-md px-2 py-1 text-[11px] font-bold uppercase ${
                    uiLocale === loc ? "bg-white text-indigo-950" : "text-indigo-200 hover:text-white"
                  }`}
                >
                  {loc}
                </button>
              ))}
            </div>

            <div role="tablist" aria-label={t.previewVersion} className="flex rounded-lg bg-indigo-900 p-0.5">
              <button
                type="button"
                role="tab"
                aria-selected={version === "draft"}
                onClick={() => setVersion("draft")}
                className={`rounded-md px-3 py-1 text-xs font-bold ${
                  version === "draft" ? "bg-white text-indigo-950" : "text-indigo-200 hover:text-white"
                }`}
              >
                {t.draftTab}
              </button>
              <button
                type="button"
                role="tab"
                aria-selected={version === "published"}
                onClick={() => setVersion("published")}
                className={`rounded-md px-3 py-1 text-xs font-bold ${
                  version === "published" ? "bg-white text-indigo-950" : "text-indigo-200 hover:text-white"
                }`}
              >
                {t.publishedTab}
              </button>
            </div>
            <Link
              href={backHref}
              className="rounded-lg border border-indigo-400/40 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-900"
            >
              {t.backToEditor}
            </Link>
          </div>
        </div>
      </div>

      <div className="mx-auto mt-8 max-w-5xl space-y-8 px-4">
        {/* Course Header with Cover Image & Pricing */}
        <section className="grid gap-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8 lg:grid-cols-[240px_minmax(0,1fr)] lg:items-center">
          <div className="aspect-[4/3] overflow-hidden rounded-2xl border border-slate-200 bg-slate-100">
            {data.course.cover_image_url && !coverFailed ? (
              // Course cover URLs are center-managed and vary by tenant at runtime.
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={data.course.cover_image_url}
                alt={data.course.cover_image_alt || data.course.title}
                onError={() => setCoverFailed(true)}
                className="h-full w-full object-cover"
              />
            ) : (
              <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-[#092443] to-[#0e8988] p-4 text-center text-xs font-black text-white">
                {data.course.title}
              </div>
            )}
          </div>

          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2 text-xs font-bold">
              <span className="rounded-full bg-slate-100 px-2.5 py-0.5 uppercase text-slate-700">
                {data.course.status === "draft" ? t.draftStatus : data.course.status === "published" ? t.publishedStatus : t.archivedStatus}
              </span>
              {data.course.language && (
                <span className="rounded-full bg-teal-50 px-2.5 py-0.5 text-teal-800">
                  {data.course.language.name}
                </span>
              )}
              {data.course.level && (
                <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-700">
                  {data.course.level.name}
                </span>
              )}
              <span className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-emerald-800">
                {pricingLabel}
              </span>
            </div>
            <h1 className="mt-3 text-2xl font-black text-[#092443] sm:text-3xl">{data.course.title}</h1>
            {(data.course.short_description || data.course.description) && (
              <p className="mt-3 whitespace-pre-line text-sm leading-7 text-slate-600">
                {data.course.short_description || data.course.description}
              </p>
            )}
          </div>
        </section>

        {version === "published" && (
          <p className="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-xs leading-5 text-indigo-950">
            {t.publishedMetadataNote}
          </p>
        )}

        {/* Objectives, Audience, Prerequisites */}
        {(outcomesForLocale.length > 0 || data.course.target_audience || data.course.prerequisites) && (
          <div className="grid gap-4 md:grid-cols-2">
            {outcomesForLocale.length > 0 && (
              <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-xs md:col-span-2">
                <h2 className="text-base font-black text-[#102b4c]">{t.outcomesTitle}</h2>
                <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                  {outcomesForLocale.map((o, idx) => (
                    <li key={o.id ?? idx} className="flex items-start gap-2 text-xs text-slate-700">
                      <span className="mt-0.5 text-[#0e8988]">✓</span>
                      <span>{o.content}</span>
                    </li>
                  ))}
                </ul>
              </section>
            )}
            {data.course.target_audience && (
              <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-xs">
                <h2 className="text-sm font-black text-[#102b4c]">{t.audienceTitle}</h2>
                <p className="mt-2 whitespace-pre-line text-xs leading-6 text-slate-600">
                  {data.course.target_audience}
                </p>
              </section>
            )}
            {data.course.prerequisites && (
              <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-xs">
                <h2 className="text-sm font-black text-[#102b4c]">{t.prerequisitesTitle}</h2>
                <p className="mt-2 whitespace-pre-line text-xs leading-6 text-slate-600">
                  {data.course.prerequisites}
                </p>
              </section>
            )}
          </div>
        )}

        {/* Curriculum with Real Lesson Content Renderers */}
        <section className="space-y-4">
          <h2 className="text-lg font-black text-[#092443]">
            {t.curriculumTitle} ({version === "draft" ? t.draftView : t.publishedView})
          </h2>
          {data.modules.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-xs text-slate-500">
              {version === "published" ? t.emptyPublished : t.emptyDraft}
            </div>
          ) : (
            data.modules.map((mod, mIdx) => (
              <article key={mod.id} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div className="border-b border-slate-100 bg-slate-50 px-5 py-4">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-[10px] font-extrabold uppercase tracking-wider text-[#0e8988]">
                      Module {String(mIdx + 1).padStart(2, "0")}
                    </span>
                    <span className="text-[10px] font-bold uppercase text-slate-500">{mod.status}</span>
                  </div>
                  <h3 className="mt-1 text-sm font-black text-[#102b4c]">{mod.title}</h3>
                  {mod.description && <p className="mt-1 text-xs text-slate-500">{mod.description}</p>}
                </div>

                <div className="divide-y divide-slate-100">
                  {mod.lessons.map((lesson) => {
                    const isExpanded = Boolean(expandedLessons[lesson.id]);
                    const lessonParams = {
                      centerSlug,
                      courseSlug,
                      lessonId: lesson.id,
                    };

                    return (
                      <div key={lesson.id} className="p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                          <div>
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-extrabold uppercase text-slate-700">
                                {normalizeLabel(lesson.effective_kind || lesson.content_type, t)}
                              </span>
                              {lesson.revision && (
                                <span className="text-[10px] font-bold text-slate-500">
                                  v{lesson.revision.version_number} · {lesson.revision.status}
                                </span>
                              )}
                              {lesson.has_draft && version === "draft" && (
                                <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-800">
                                  Draft
                                </span>
                              )}
                            </div>
                            <h4 className="mt-1 text-sm font-bold text-[#102b4c]">{lesson.title}</h4>
                            {lesson.description && (
                              <p className="mt-0.5 text-xs text-slate-500">{lesson.description}</p>
                            )}
                          </div>

                          <button
                            type="button"
                            onClick={() =>
                              setExpandedLessons((prev) => ({
                                ...prev,
                                [lesson.id]: !prev[lesson.id],
                              }))
                            }
                            className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-[#0e8988] hover:bg-slate-50"
                          >
                            {isExpanded ? t.hideLesson : t.expandLesson}
                          </button>
                        </div>

                        {isExpanded && (
                          <div className="mt-4 space-y-4 border-t border-slate-100 pt-4">
                            {lesson.blocks.length === 0 ? (
                              <p className="rounded-xl bg-slate-50 p-4 text-xs text-slate-500">
                                {t.emptyLessonBlocks}
                              </p>
                            ) : (
                              lesson.blocks.map((block) => {
                                if (block.block_type === "text") {
                                  return (
                                    <LessonTextBlock
                                      key={block.id}
                                      block={block}
                                      params={lessonParams}
                                      locale={uiLocale}
                                    />
                                  );
                                }
                                if (block.block_type === "media") {
                                  return (
                                    <LessonMediaBlock
                                      key={block.id}
                                      block={block}
                                      params={lessonParams}
                                      autoPreviewImages
                                      locale={uiLocale}
                                    />
                                  );
                                }
                                if (block.block_type === "exercise") {
                                  return (
                                    <LessonExerciseBlock
                                      key={block.id}
                                      block={block}
                                      isAdminPreview={true}
                                      params={lessonParams}
                                    />
                                  );
                                }
                                return null;
                              })
                            )}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </article>
            ))
          )}
        </section>
      </div>
    </main>
  );
}
