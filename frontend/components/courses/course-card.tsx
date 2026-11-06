
import Image from "next/image";
import Link from "next/link";
import type {
  Course,
  Locale,
} from "@/lib/courses";

interface Props {
  course: Course;
  locale: Locale;
  priority?: boolean;
}

const translations = {
  en: {
    online: "Online",
    in_person: "In-person",
    hybrid: "Hybrid",
    duration: "Duration",
    details: "View course details",
    noImage: "Language programme",
  },

  fr: {
    online: "En ligne",
    in_person: "En présentiel",
    hybrid: "Hybride",
    duration: "Durée",
    details: "Voir les détails du cours",
    noImage: "Programme de langues",
  },
};

export default function CourseCard({
  course,
  locale,
  priority = false,
}: Props) {
  const t = translations[locale];

  const formatLabel = course.studyFormat
    ? t[course.studyFormat]
    : null;

  return (
    <article className="group flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white transition duration-300 hover:-translate-y-1 hover:border-[#b4d9d5] hover:shadow-[0_18px_42px_rgba(16,43,76,0.09)]">

      {/* Course Image */}
      <div className="relative aspect-[16/9] overflow-hidden bg-gradient-to-br from-[#e4f1f3] to-[#dce9f0]">
        {course.coverImageUrl ? (
          <Image
            src={course.coverImageUrl}
            alt={course.coverImageAlt || course.title}
            fill
            preload={priority}
            loading={priority ? undefined : "lazy"}
            unoptimized={course.coverImageUrl.startsWith("http://")}
            sizes="(max-width: 639px) 100vw, (max-width: 1023px) 50vw, 33vw"
            className="object-cover transition duration-500 group-hover:scale-105"
          />
        ) : (
          <div className="flex h-full items-center justify-center text-[#0e8988]">
            <svg
              width="42"
              height="42"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.5"
              aria-hidden="true"
            >
              <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z" />
              <path d="M8 8h8M8 12h6" />
            </svg>
          </div>
        )}
      </div>

      {/* Content */}
      <div className="flex flex-1 flex-col p-5 sm:p-6">

        <div className="flex flex-wrap items-center gap-2">
          <span className="rounded-full bg-[#e5f5f2] px-3 py-1 text-xs font-bold text-[#0c7777]">
            {course.language.label}
          </span>

          {course.level && (
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
              {course.level.label}
            </span>
          )}
        </div>

        <h2 className="mt-4 line-clamp-2 min-h-[3.5rem] text-xl font-extrabold leading-7 text-[#102b4c]">
          {course.title}
        </h2>

        <p className="mt-2 line-clamp-2 min-h-12 text-sm leading-6 text-slate-600">
          {course.description}
        </p>

        {/* Metadata: only display values supplied by the API */}
        {(formatLabel || course.durationLabel) && (
          <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 pt-4 text-xs font-medium text-slate-500">

            {formatLabel && (
              <span className="inline-flex items-center gap-2">
                <svg
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.7"
                  aria-hidden="true"
                >
                  <rect x="3" y="4" width="18" height="13" rx="2" />
                  <path d="M8 21h8M12 17v4" />
                </svg>

                {formatLabel}
              </span>
            )}

            {course.durationLabel && (
              <span className="inline-flex items-center gap-2">
                <svg
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.7"
                  aria-hidden="true"
                >
                  <circle cx="12" cy="12" r="9" />
                  <path d="M12 7v5l3 2" />
                </svg>

                {course.durationLabel}
              </span>
            )}
          </div>
        )}

        {/* Primary CTA */}
        <div className="mt-auto pt-6">
          <Link
            href={`/courses/${encodeURIComponent(course.slug)}`}
            className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-[#b6d7d5] bg-[#f3faf9] px-4 py-3 text-sm font-extrabold text-[#087b83] transition hover:border-[#0e8988] hover:bg-[#e5f5f2] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f6a23b]"
            aria-label={`${t.details}: ${course.title}`}
          >
            {t.details}

            <svg
              width="17"
              height="17"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M5 12h14M12 5l7 7-7 7" />
            </svg>
          </Link>
        </div>
      </div>
    </article>
  );
}
