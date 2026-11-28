import type { Metadata } from "next";
import { notFound } from "next/navigation";
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

export default async function AdminCourseDraftPreviewPage({ params }: PageProps) {
  const { centerSlug, courseSlug } = await params;

  if (!centerSlug || !courseSlug || centerSlug.includes("..") || courseSlug.includes("..")) {
    notFound();
  }

  return <CourseDraftPreviewClient centerSlug={centerSlug} courseSlug={courseSlug} />;
}
