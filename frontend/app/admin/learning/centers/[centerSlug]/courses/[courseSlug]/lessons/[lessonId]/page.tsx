
import type { Metadata } from "next";
import { notFound } from "next/navigation";

import AdminLessonPageClient from "@/components/admin/lesson-editor/admin-lesson-page-client";

export const metadata: Metadata = {
  title: "Administrative Lesson Workspace",
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
  searchParams?: Promise<{ locale?: string }>;
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
  return (
    /^[1-9]\d*$/.test(value) &&
    Number.isSafeInteger(Number(value))
  );
}

export default async function AdminLearningLessonPreviewPage({
  params,
  searchParams,
}: PageProps) {
  const {
    centerSlug,
    courseSlug,
    lessonId,
  } = await params;
  const query: { locale?: string } = searchParams ? await searchParams : {};
  const locale = query.locale === "fr" ? "fr" : "en";

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
    `/admin/learning/centers/${encodeURIComponent(centerSlug)}` +
    `/courses/${encodeURIComponent(courseSlug)}?locale=${locale}`;

  return (
    <AdminLessonPageClient
      centerSlug={centerSlug}
      courseSlug={courseSlug}
      lessonId={Number(lessonId)}
      adminPreviewBasePath={adminPreviewBasePath}
      adminPreviewReturnHref={adminPreviewReturnHref}
      locale={locale}
    />
  );
}
