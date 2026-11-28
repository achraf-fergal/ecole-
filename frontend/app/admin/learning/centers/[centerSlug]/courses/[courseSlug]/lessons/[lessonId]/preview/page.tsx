import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";

import AdminLessonPageClient from "@/components/admin/lesson-editor/admin-lesson-page-client";

export const metadata: Metadata = {
  title: "Administrative Lesson Preview",
  robots: {
    index: false,
    follow: false,
    noarchive: true,
  },
};

interface PageProps {
  params: Promise<{
    centerSlug: string;
    courseSlug: string;
    lessonId: string;
  }>;
}

function isValidSlug(value: string): boolean {
  return (
    value.length > 0 &&
    value.length <= 150 &&
    !value.includes("/") &&
    !value.includes("\\") &&
    !value.includes("..") &&
    !value.includes("\0")
  );
}

function isValidLessonId(value: string): boolean {
  return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value));
}

async function AdminLearningLessonPreviewRoute({ params }: PageProps) {
  const { centerSlug, courseSlug, lessonId } = await params;

  if (
    !isValidSlug(centerSlug) ||
    !isValidSlug(courseSlug) ||
    !isValidLessonId(lessonId)
  ) {
    notFound();
  }

  const adminPreviewBasePath =
    `/admin/learning/centers/${encodeURIComponent(centerSlug)}` +
    `/courses/${encodeURIComponent(courseSlug)}`;

  const adminPreviewReturnHref =
    `/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses`;

  return (
    <AdminLessonPageClient
      key={`preview-${lessonId}`}
      centerSlug={centerSlug}
      courseSlug={courseSlug}
      lessonId={Number(lessonId)}
      adminPreviewBasePath={adminPreviewBasePath}
      adminPreviewReturnHref={adminPreviewReturnHref}
      initialTab="preview"
    />
  );
}

export default function AdminLearningLessonPreviewPage(props: PageProps) {
  return (
    <Suspense
      fallback={
        <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
          <p className="text-sm font-bold text-slate-500">Loading lesson preview…</p>
        </main>
      }
    >
      <AdminLearningLessonPreviewRoute {...props} />
    </Suspense>
  );
}
