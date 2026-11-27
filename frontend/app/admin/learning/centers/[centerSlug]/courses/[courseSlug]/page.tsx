import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";

import CourseDetailsClient from "@/components/admin/course-details/course-details-client";

export const metadata: Metadata = {
  title: "Course Management",
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
  return (
    value.length > 0 &&
    value.length <= 150 &&
    !value.includes("/") &&
    !value.includes("\\") &&
    !value.includes("..") &&
    !value.includes("\0")
  );
}

async function AdminCourseDetailsRoute({ params }: PageProps) {
  const { centerSlug, courseSlug } = await params;

  if (!isValidSlug(centerSlug) || !isValidSlug(courseSlug)) {
    notFound();
  }

  return (
    <CourseDetailsClient
      centerSlug={centerSlug}
      courseSlug={courseSlug}
    />
  );
}

export default function AdminCourseDetailsPage(props: PageProps) {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center bg-slate-50">
          <p className="text-sm font-bold text-slate-500">Loading course workspace...</p>
        </div>
      }
    >
      <AdminCourseDetailsRoute {...props} />
    </Suspense>
  );
}
