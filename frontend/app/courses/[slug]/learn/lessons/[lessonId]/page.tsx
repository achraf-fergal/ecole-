
import type { Metadata } from "next";
import { notFound } from "next/navigation";

import LearningPageClient from "@/components/learning/learning-page-client";

export const metadata: Metadata = {
  title: "Course Learning",
  robots: {
    index: false,
    follow: false,
    noarchive: true,
  },
};

interface PageProps {
  params: Promise<{
    slug: string;
    lessonId: string;
  }>;
  searchParams?: Promise<{
    center?: string;
  }>;
}

function isValidCourseSlug(slug: string): boolean {
  return (
    slug.length > 0 &&
    slug.length <= 150 &&
    !slug.includes("/") &&
    !slug.includes("\\") &&
    !slug.includes("..")
  );
}

function isValidLessonId(value: string): boolean {
  return (
    /^[1-9]\d*$/.test(value) &&
    Number.isSafeInteger(Number(value))
  );
}

export default async function LearningLessonPage({
  params,
  searchParams,
}: PageProps) {
  const { slug, lessonId } = await params;
  const resolvedSearchParams = searchParams ? await searchParams : {};

  if (
    !isValidCourseSlug(slug) ||
    !isValidLessonId(lessonId)
  ) {
    notFound();
  }

  // Resolve the center from searchParams or existing server environment configuration.
  const centerSlug =
    resolvedSearchParams.center?.trim() ||
    process.env.CENTER_SLUG?.trim() ||
    "";

  if (!centerSlug) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#f8fafc] p-6">
        <div className="w-full max-w-md rounded-3xl border border-amber-200 bg-white p-8 text-center">
          <h1 className="text-xl font-black text-[#102b4c]">
            Learning environment is not configured
          </h1>

          <p className="mt-3 text-sm leading-7 text-slate-600">
            Configure CENTER_SLUG in the frontend environment before
            accessing learning content.
          </p>
        </div>
      </main>
    );
  }

  return (
    <LearningPageClient
      centerSlug={centerSlug}
      courseSlug={slug}
      lessonId={Number(lessonId)}
    />
  );
}
