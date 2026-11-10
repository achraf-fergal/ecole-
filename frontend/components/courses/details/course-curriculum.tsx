
import type {
  CourseModulePreview,
  Locale,
} from "@/lib/courses";

interface Props {
  modules: CourseModulePreview[];
  locale: Locale;
}

const translations = {
  en: {
    lessons: "lessons",
    video: "Video",
    text: "Reading",
    resource: "Resource",
    quiz: "Quiz",
    minutes: "min",
  },

  fr: {
    lessons: "leçons",
    video: "Vidéo",
    text: "Lecture",
    resource: "Ressource",
    quiz: "Quiz",
    minutes: "min",
  },
};

function LessonIcon({
  type,
}: {
  type: string;
}) {
  const common = {
    width: 19,
    height: 19,
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.7,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    "aria-hidden": true as const,
  };

  switch (type) {
    case "video":
      return (
        <svg viewBox="0 0 24 24" {...common}>
          <rect x="3" y="5" width="18" height="14" rx="2" />
          <path d="m10 9 5 3-5 3z" />
        </svg>
      );

    case "resource":
      return (
        <svg viewBox="0 0 24 24" {...common}>
          <path d="M12 3v12m-5-5 5 5 5-5" />
          <path d="M5 17v4h14v-4" />
        </svg>
      );

    case "quiz":
      return (
        <svg viewBox="0 0 24 24" {...common}>
          <rect x="4" y="3" width="16" height="18" rx="2" />
          <path d="m8 9 2 2 4-4M8 16h8" />
        </svg>
      );

    default:
      return (
        <svg viewBox="0 0 24 24" {...common}>
          <path d="M5 3h10l4 4v14H5z" />
          <path d="M14 3v5h5M8 13h8M8 17h8" />
        </svg>
      );
  }
}

export default function CourseCurriculum({
  modules,
  locale,
}: Props) {
  const t = translations[locale];

  if (modules.length === 0) return null;

  const totalLessons = modules.reduce(
    (total, module) => total + module.lessons.length,
    0
  );

  return (
    <section
      id="curriculum"
      aria-labelledby="curriculum-heading"
      className="scroll-mt-28"
    >
      <div className="mb-7">
        <span className="text-xs font-extrabold uppercase tracking-[0.16em] text-[#0e8988]">
          COURSE CONTENT
        </span>

        <h2
          id="curriculum-heading"
          className="mt-3 text-2xl font-black tracking-tight text-[#102b4c] sm:text-3xl"
        >
          {locale === "en"
            ? "Course Curriculum"
            : "Programme de formation"}
        </h2>

        <p className="mt-3 text-sm leading-7 text-slate-600">
          {locale === "en"
            ? "Explore the topics and learning activities included in this programme."
            : "Découvrez les thèmes et les activités pédagogiques de ce programme."}
        </p>

        <p className="mt-3 text-xs font-bold text-slate-500">
          {modules.length} {locale === "en" ? "Modules" : "Modules"}
          {totalLessons > 0 &&
            ` · ${totalLessons} ${t.lessons}`}
        </p>
      </div>

      <div className="space-y-3">
        {modules.map((module, index) => (
          <details
            key={module.id}
            open={index === 0}
            className="group overflow-hidden rounded-2xl border border-slate-200 bg-white transition-colors open:border-[#b5dcd7]"
          >
            <summary className="flex min-h-[76px] cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
              <div className="min-w-0">
                <span className="text-[10px] font-extrabold uppercase tracking-widest text-[#0e8988]">
                  {locale === "en" ? "MODULE" : "MODULE"}{" "}
                  {String(index + 1).padStart(2, "0")}
                </span>

                <h3 className="mt-1 text-sm font-extrabold text-[#102b4c] sm:text-base">
                  {module.title}
                </h3>

                <p className="mt-1 text-xs text-slate-500">
                  {module.lessons.length} {t.lessons}
                </p>
              </div>

              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#edf7f6] text-[#0e8988] transition-transform group-open:rotate-180">
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  aria-hidden="true"
                >
                  <path d="m6 9 6 6 6-6" />
                </svg>
              </span>
            </summary>

            {module.description && (
              <p className="border-t border-slate-100 px-5 pt-4 text-sm leading-6 text-slate-600 sm:px-6">
                {module.description}
              </p>
            )}

            <div className="border-t border-slate-100 px-5 py-2 sm:px-6">
              {module.lessons.map((lesson) => {
                const label =
                  lesson.contentType === "video"
                    ? t.video
                    : lesson.contentType === "quiz"
                      ? t.quiz
                      : lesson.contentType === "resource"
                        ? t.resource
                        : t.text;

                return (
                  <div
                    key={lesson.id}
                    className="flex min-h-12 items-center gap-3 border-b border-slate-50 py-3 last:border-b-0"
                  >
                    <span className="shrink-0 text-[#0e8988]">
                      <LessonIcon type={lesson.contentType} />
                    </span>

                    <span className="min-w-0 flex-1 text-sm font-medium text-slate-700">
                      {lesson.title}
                    </span>

                    <span className="shrink-0 rounded-full bg-slate-50 px-2.5 py-1 text-[10px] font-bold text-slate-500">
                      {label}
                    </span>

                    {lesson.durationMinutes && (
                      <span className="shrink-0 text-[11px] text-slate-400">
                        {lesson.durationMinutes} {t.minutes}
                      </span>
                    )}
                  </div>
                );
              })}
            </div>
          </details>
        ))}
      </div>
    </section>
  );
}
