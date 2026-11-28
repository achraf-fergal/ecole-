"use client";

import React, { useState } from "react";
import Link from "next/link";
import UnifiedLessonEditor from "./unified-lesson-editor";
import LearningPageClient from "@/components/learning/learning-page-client";

interface AdminLessonPageClientProps {
  centerSlug: string;
  courseSlug: string;
  lessonId: number;
  adminPreviewBasePath: string;
  adminPreviewReturnHref: string;
  initialTab?: "editor" | "preview";
  locale?: "en" | "fr";
}

export default function AdminLessonPageClient({
  centerSlug,
  courseSlug,
  lessonId,
  adminPreviewBasePath,
  adminPreviewReturnHref,
  initialTab = "editor",
  locale = "en",
}: AdminLessonPageClientProps) {
  const [activeTab, setActiveTab] = useState<"editor" | "preview">(initialTab);
  const t = (text: string) => locale === "fr"
    ? ({ "Back to Course": "Retour au cours" }[text] ?? text)
    : text;

  return (
    <div className="min-h-screen bg-[#f8fafc]">
      {/* Top Administrative Mode Navigation */}
      <div className="sticky top-0 z-40 border-b border-slate-200 bg-white/95 px-4 py-2.5 backdrop-blur-md">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <Link
              href={adminPreviewReturnHref}
              className="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:border-[#0e8988] hover:text-[#0e8988] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0e8988]"
            >
              <span aria-hidden="true">←</span>
              {t("Back to Course")}
            </Link>
            <span className="rounded-md bg-indigo-50 px-2.5 py-1 text-[10px] font-extrabold uppercase text-indigo-700">
              Admin Workspace
            </span>
            <span className="text-xs text-slate-400">·</span>
            <span className="text-xs font-semibold text-slate-600">
              {centerSlug} / {courseSlug}
            </span>
          </div>

          <div className="flex items-center rounded-xl border border-slate-200 bg-slate-100 p-1">
            <button
              type="button"
              onClick={() => setActiveTab("editor")}
              className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                activeTab === "editor"
                  ? "bg-white text-[#102b4c] shadow-xs"
                  : "text-slate-600 hover:text-[#102b4c]"
              }`}
            >
              ✎ Content Editor
            </button>
            <button
              type="button"
              onClick={() => setActiveTab("preview")}
              className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                activeTab === "preview"
                  ? "bg-white text-[#102b4c] shadow-xs"
                  : "text-slate-600 hover:text-[#102b4c]"
              }`}
            >
              👁 Live Student Preview
            </button>
          </div>
        </div>
      </div>

      {/* Main Content Area */}
      {activeTab === "editor" ? (
        <main className="p-4 sm:p-6 lg:p-8">
          <UnifiedLessonEditor
            centerSlug={centerSlug}
            courseSlug={courseSlug}
            lessonId={lessonId}
            returnHref={adminPreviewReturnHref}
            onSwitchToPreview={() => setActiveTab("preview")}
          />
        </main>
      ) : (
        <LearningPageClient
          centerSlug={centerSlug}
          courseSlug={courseSlug}
          lessonId={lessonId}
          adminPreviewBasePath={adminPreviewBasePath}
          adminPreviewReturnHref={adminPreviewReturnHref}
          locale={locale}
        />
      )}
    </div>
  );
}
