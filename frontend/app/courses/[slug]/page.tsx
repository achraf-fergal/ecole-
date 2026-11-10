
import type { Metadata } from "next";
import { notFound } from "next/navigation";

import {
  getCourseDetails,
  type Locale,
} from "@/lib/courses";

import CourseDetailsView, {
  CourseDetailsNotConfigured,
  CourseTranslationUnavailable,
} from "@/components/courses/details/course-details-view";

interface PageProps {
  params: Promise<{
    slug: string;
  }>;

  searchParams: Promise<{
    lang?: string | string[];
  }>;
}

/* =========================================================
   ROUTE HELPERS
========================================================= */

function parseLocale(
  value: string | string[] | undefined
): Locale {
  const requested = Array.isArray(value)
    ? value[0]
    : value;

  return requested?.toLowerCase() === "fr"
    ? "fr"
    : "en";
}

function isValidSlug(slug: string): boolean {
  return (
    slug.length > 0 &&
    slug.length <= 150 &&
    !slug.includes("/") &&
    !slug.includes("\\") &&
    !slug.includes("..")
  );
}

function courseUrl(
  slug: string,
  locale: Locale
): string {
  const path = `/courses/${encodeURIComponent(slug)}`;

  return locale === "fr"
    ? `${path}?lang=fr`
    : path;
}

/* =========================================================
   DYNAMIC SEO METADATA
========================================================= */

export async function generateMetadata({
  params,
  searchParams,
}: PageProps): Promise<Metadata> {
  const [routeParams, query] = await Promise.all([
    params,
    searchParams,
  ]);

  const slug = routeParams.slug;
  const locale = parseLocale(query.lang);

  if (!isValidSlug(slug)) {
    return {
      title: "Course not found",
      robots: {
        index: false,
        follow: false,
      },
    };
  }

  const result = await getCourseDetails(slug, locale);

  if (result.kind === "not-found") {
    return {
      title: "Course not found",
      robots: {
        index: false,
        follow: false,
      },
    };
  }

  if (result.kind === "not-configured") {
    return {
      title: "Course details unavailable",
      robots: {
        index: false,
        follow: false,
      },
    };
  }

  if (result.kind === "translation-unavailable") {
    return {
      title: "Course translation unavailable",
      robots: {
        index: false,
        follow: false,
      },
    };
  }

  const course = result.course;

  // Publish alternate URLs only for available translations.
  const alternateLanguages: Record<string, string> = {};

  for (const availableLocale of course.localization.availableLocales) {
    if (availableLocale === "en" || availableLocale === "fr") {
      alternateLanguages[availableLocale] = courseUrl(
        course.slug,
        availableLocale
      );
    }
  }

  const metadata: Metadata = {
    title: course.seo.title,

    description: course.seo.description,

    keywords: [
      course.title,
      course.language.label,
      course.level.label,
      "language courses",
      "language learning",
    ],

    alternates: {
      canonical: courseUrl(course.slug, locale),
      languages: alternateLanguages,
    },

    robots: {
      index: true,
      follow: true,
    },

    openGraph: {
      title: course.seo.title,
      description: course.seo.description,
      type: "website",
      images: course.coverImage.url
        ? [
            {
              url: course.coverImage.url,
              alt: course.coverImage.alt,
            },
          ]
        : [],
    },

    twitter: {
      card: course.coverImage.url
        ? "summary_large_image"
        : "summary",
      title: course.seo.title,
      description: course.seo.description,
      images: course.coverImage.url
        ? [course.coverImage.url]
        : [],
    },
  };

  return metadata;
}

/* =========================================================
   PAGE
========================================================= */

export default async function CourseDetailsPage({
  params,
  searchParams,
}: PageProps) {
  const [routeParams, query] = await Promise.all([
    params,
    searchParams,
  ]);

  const slug = routeParams.slug;
  const locale = parseLocale(query.lang);

  // Reject malformed slugs before making an API request.
  if (!isValidSlug(slug)) {
    notFound();
  }

  const result = await getCourseDetails(slug, locale);

  if (result.kind === "not-found") {
    notFound();
  }

  if (result.kind === "not-configured") {
    return <CourseDetailsNotConfigured />;
  }

  if (result.kind === "translation-unavailable") {
    return (
      <CourseTranslationUnavailable
        slug={slug}
        sourceLocale={result.sourceLocale}
        availableLocales={result.availableLocales}
      />
    );
  }

  return (
    <CourseDetailsView
      course={result.course}
      locale={locale}
    />
  );
}
