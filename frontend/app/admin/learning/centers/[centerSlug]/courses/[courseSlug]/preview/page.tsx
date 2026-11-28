import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import CourseDraftPreviewClient from "@/components/admin/course-details/course-draft-preview-client";

export const metadata: Metadata = {
  title: "Draft Course Preview",
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
  }>;
}

function isValidSlug(value: string): boolean {
  return value.length > 0 &&
    value.length <= 150 &&
    !value.includes("/") &&
    !value.includes("\\") &&
    !value.includes("..") &&
    !value.includes("\0");
}

async function AdminCourseDraftPreviewRoute({ params }: PageProps) {
  const { centerSlug, courseSlug } = await params;

  if (!isValidSlug(centerSlug) || !isValidSlug(courseSlug)) {
    notFound();
  }

  return <CourseDraftPreviewClient centerSlug={centerSlug} courseSlug={courseSlug} />;
}

export default function AdminCourseDraftPreviewPage(props: PageProps) {
  return (
    <Suspense
      fallback={
        <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
          <p className="text-sm font-bold text-slate-500">Loading private course preview…</p>
        </main>
      }
    >
      <AdminCourseDraftPreviewRoute {...props} />
    </Suspense>
  );
}
