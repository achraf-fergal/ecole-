"use client";

import React, { useEffect, useState } from "react";
import Link from "next/link";
import { apiGetMe, type AuthUser } from "@/lib/auth";
import {
  getAdminCoursePreview,
  type AdminCoursePreviewData,
} from "@/lib/admin-learning";

interface Props {
  centerSlug: string;
  courseSlug: string;
}

function normalizeLabel(raw: string): string {
  switch (raw) {
    case "video":
      return "Video";
    case "reading":
    case "text":
      return "Reading";
    case "resources":
    case "resource":
    case "file":
    case "image":
      return "Resources";
    case "exercise":
    case "quiz":
      return "Exercise";
    case "legacy_mixed":
    case "mixed":
      return "Mixed Content";
    default:
      return "Reading";
  }
}

export default function CourseDraftPreviewClient({ centerSlug, courseSlug }: Props) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [authResolved, setAuthResolved] = useState(false);
  const [version, setVersion] = useState<"draft" | "published">("draft");
  const [data, setData] = useState<AdminCoursePreviewData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

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
      setIsLoading(false);
      return;
    }

    let active = true;
    setIsLoading(true);
    setError(null);
    void getAdminCoursePreview(centerSlug, courseSlug, version)
      .then((res) => {
        if (active) setData(res);
      })
      .catch((err: unknown) => {
        if (active) setError(err instanceof Error ? err.message : "Failed to load preview.");
      })
      .finally(() => {
        if (active) setIsLoading(false);
      });

    return () => {
      active = false;
    };
  }, [authResolved, user, centerSlug, courseSlug, version]);

  const backHref = `/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(courseSlug)}`;

  if (!authResolved || isLoading) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <p className="text-sm font-bold text-slate-500">Loading private course preview…</p>
      </main>
    );
  }

  if (!user || error) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <div className="max-w-md rounded-2xl border border-rose-200 bg-white p-8 text-center">
          <h1 className="text-lg font-black text-slate-900">Preview Unavailable</h1>
          <p className="mt-2 text-xs text-slate-600">
            {error || "Sign in with an authorized administrator account to view this private preview."}
          </p>
          <Link
            href={backHref}
            className="mt-5 inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-bold text-white"
          >
            Back to Course Workspace
          </Link>
        </div>
      </main>
    );
  }

  if (!data) return null;

  return (
    <main className="min-h-screen bg-[#f8fafc] pb-16">
      <div className="sticky top-0 z-30 border-b border-indigo-200 bg-indigo-950 px-4 py-3 text-white shadow-sm">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-2.5">
            <span className="rounded-full bg-indigo-500/30 px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wider text-indigo-100">
              Private Admin Preview · noindex
            </span>
            {data.has_draft_changes && (
              <span className="rounded-full bg-amber-400 px-2.5 py-0.5 text-[10px] font-extrabold uppercase text-amber-950">
                Draft Changes Present
              </span>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <div role="tablist" aria-label="Preview version" className="flex rounded-lg bg-indigo-900 p-0.5">
              <button
                type="button"
                role="tab"
                aria-selected={version === "draft"}
                onClick={() => setVersion("draft")}
                className={`rounded-md px-3 py-1 text-xs font-bold ${
                  version === "draft" ? "bg-white text-indigo-950" : "text-indigo-200 hover:text-white"
                }`}
              >
                Draft Version
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
                Published Version
              </button>
            </div>
            <Link
              href={backHref}
              className="rounded-lg border border-indigo-400/40 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-900"
            >
              ← Back to Editor
            </Link>
          </div>
        </div>
      </div>

      <div className="mx-auto mt-8 max-w-5xl space-y-8 px-4">
        <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
          <div className="flex flex-wrap items-center gap-2 text-xs font-bold">
            <span className="rounded-full bg-slate-100 px-2.5 py-0.5 uppercase text-slate-700">
              {data.course.status}
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
          </div>
          <h1 className="mt-3 text-2xl font-black text-[#092443] sm:text-3xl">{data.course.title}</h1>
          {(data.course.short_description || data.course.description) && (
            <p className="mt-3 whitespace-pre-line text-sm leading-7 text-slate-600">
              {data.course.short_description || data.course.description}
            </p>
          )}
        </section>

        {data.course.outcomes && data.course.outcomes.length > 0 && (
          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-xs">
            <h2 className="text-base font-black text-[#102b4c]">What you will learn</h2>
            <ul className="mt-3 grid gap-2 sm:grid-cols-2">
              {data.course.outcomes.map((o, idx) => (
                <li key={idx} className="flex items-start gap-2 text-xs text-slate-700">
                  <span className="mt-0.5 text-[#0e8988]">✓</span>
                  <span>{o.content}</span>
                </li>
              ))}
            </ul>
          </section>
        )}

        <section className="space-y-4">
          <h2 className="text-lg font-black text-[#092443]">
            Curriculum ({version === "draft" ? "Draft View" : "Published View"})
          </h2>
          {data.modules.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-xs text-slate-500">
              {version === "published"
                ? "No published modules are visible yet in the published version."
                : "No active modules in this course yet."}
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
                <div className="divide-y divide-slate-100 px-5">
                  {mod.lessons.map((lesson) => (
                    <div key={lesson.id} className="flex flex-wrap items-center justify-between gap-3 py-3.5">
                      <div>
                        <p className="text-xs font-bold text-[#102b4c]">{lesson.title}</p>
                        <p className="mt-0.5 text-[11px] text-slate-500">
                          {normalizeLabel(lesson.content_type)}
                          {lesson.duration_minutes ? ` · ${lesson.duration_minutes} min` : ""}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        {lesson.has_draft && version === "draft" && (
                          <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-800">
                            Draft
                          </span>
                        )}
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600">
                          {lesson.status}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </article>
            ))
          )}
        </section>
      </div>
    </main>
  );
}
