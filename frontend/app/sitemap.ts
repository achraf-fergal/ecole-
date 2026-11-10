import type { MetadataRoute } from "next";

import {
  getCourseSitemapItems,
  type Locale,
} from "@/lib/courses";

function buildCourseUrl(
  siteUrl: string,
  slug: string,
  locale: Locale
): string {
  const url = new URL(
    `/courses/${encodeURIComponent(slug)}`,
    siteUrl
  );

  if (locale === "fr") {
    url.searchParams.set("lang", "fr");
  }

  return url.toString();
}

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const siteUrl = process.env.SITE_URL || "http://localhost:3000";
  const defaultLocale: Locale =
    process.env.DEFAULT_SITE_LOCALE === "fr" ? "fr" : "en";

  const staticRoutes: MetadataRoute.Sitemap = [
    {
      url: new URL("/", siteUrl).toString(),
      changeFrequency: "weekly",
      priority: 1,
    },
    {
      url: new URL("/about", siteUrl).toString(),
      changeFrequency: "monthly",
      priority: 0.7,
    },
    {
      url: new URL("/courses", siteUrl).toString(),
      changeFrequency: "daily",
      priority: 0.9,
    },
  ];

  try {
    const courses = await getCourseSitemapItems(defaultLocale);
    const courseRoutes: MetadataRoute.Sitemap = [];

    for (const course of courses) {
      const locales = course.availableLocales.filter(
        (locale): locale is Locale =>
          locale === "en" || locale === "fr"
      );

      if (locales.length === 0) {
        continue;
      }

      const languageUrls: Record<string, string> = {};

      for (const locale of locales) {
        languageUrls[locale] = buildCourseUrl(
          siteUrl,
          course.slug,
          locale
        );
      }

      for (const locale of locales) {
        courseRoutes.push({
          url: languageUrls[locale],
          changeFrequency: "weekly",
          priority: 0.8,
          alternates: {
            languages: languageUrls,
          },
        });
      }
    }

    return [...staticRoutes, ...courseRoutes];
  } catch (error) {
    console.error("Sitemap generation error:", error);

    return staticRoutes;
  }
}
