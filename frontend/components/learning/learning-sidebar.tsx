
import Link from "next/link";

import type {
  LearningOverview,
  LessonProgressStatus,
} from "@/lib/learning";

interface LearningSidebarProps {
  overview: LearningOverview;
  courseSlug: string;
  currentLessonId: number;
  isMobileOpen: boolean;
  onClose: () => void;
}

function StatusIcon({
  status,
}: {
  status: LessonProgressStatus;
}) {
  if (status === "completed") {
    return (
      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#e5f5f2] text-[#0e8988]">
        <svg
          viewBox="0 0 20 20"
          fill="none"
          className="h-3.5 w-3.5"
          aria-hidden="true"
        >
          <path
            d="m4 10 4 4 8-8"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      </span>
    );
  }

  if (status === "in_progress") {
    return (
      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-[#0e8988]">
        <span className="h-2 w-2 rounded-full bg-[#0e8988]" />
      </span>
    );
  }

  return (
    <span className="h-5 w-5 shrink-0 rounded-full border-2 border-slate-300" />
  );
}

function formatDuration(minutes: number | null): string {
  if (!minutes || minutes <= 0) {
    return "";
  }

  return `${minutes} min`;
}

export default function LearningSidebar({
  overview,
  courseSlug,
  currentLessonId,
  isMobileOpen,
  onClose,
}: LearningSidebarProps) {
  const summary = overview.progress_summary;

  const percentage = Math.min(
    100,
    Math.max(0, summary.completion_percentage)
  );

  return (
    <>
      {isMobileOpen && (
        <button
          type="button"
          aria-label="Close course navigation"
          onClick={onClose}
          className="fixed inset-0 z-40 bg-[#092443]/50 backdrop-blur-sm md:hidden"
        />
      )}

      <aside
        aria-label="Course curriculum"
        className={`fixed inset-y-0 left-0 z-50 flex w-[min(86vw,340px)] flex-col border-r border-slate-200 bg-white transition-transform duration-300 md:sticky md:top-[73px] md:z-auto md:h-[calc(100vh-73px)] md:w-[310px] md:shrink-0 md:translate-x-0 ${
          isMobileOpen
            ? "translate-x-0"
            : "-translate-x-full md:translate-x-0"
        }`}
      >
        {/* Sidebar Header */}
        <div className="border-b border-slate-100 p-5">
          <div className="flex items-center justify-between gap-3">
            <div className="min-w-0">
              <span className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-[#0e8988]">
                COURSE CONTENT
              </span>

              <h2 className="mt-2 line-clamp-2 text-base font-black text-[#102b4c]">
                {overview.course.title}
              </h2>
            </div>

            <button
              type="button"
              onClick={onClose}
              aria-label="Close sidebar"
              className="rounded-xl p-2 text-slate-500 transition hover:bg-slate-100 md:hidden"
            >
              <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-5 w-5"
                stroke="currentColor"
                strokeWidth="2"
                aria-hidden="true"
              >
                <path d="m18 6-12 12M6 6l12 12" />
              </svg>
            </button>
          </div>

          {/* Course Progress */}
          <div className="mt-5">
            <div className="flex items-center justify-between gap-3 text-xs">
              <span className="font-bold text-slate-600">
                Your progress
              </span>

              <span className="font-black text-[#0e8988]">
                {percentage}%
              </span>
            </div>

            <div
              className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"
              role="progressbar"
              aria-label="Course completion"
              aria-valuenow={percentage}
              aria-valuemin={0}
              aria-valuemax={100}
            >
              <div
                className="h-full rounded-full bg-[#0e8988] transition-all duration-500"
                style={{ width: `${percentage}%` }}
              />
            </div>

            <p className="mt-2 text-[11px] text-slate-500">
              {summary.completed_lessons} of {summary.total_lessons} lessons completed
            </p>
          </div>
        </div>

        {/* Scrollable Curriculum */}
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          {overview.modules.length === 0 ? (
            <div className="rounded-2xl bg-slate-50 p-5 text-center">
              <p className="text-sm font-bold text-slate-700">
                No lessons available
              </p>

              <p className="mt-2 text-xs leading-6 text-slate-500">
                Your instructor has not published any lessons yet.
              </p>
            </div>
          ) : (
            <div className="space-y-5">
              {overview.modules.map((module, moduleIndex) => {
                const completedCount = module.lessons.filter(
                  (lesson) => lesson.progress.status === "completed"
                ).length;

                return (
                  <section
                    key={module.id}
                    aria-labelledby={`module-${module.id}`}
                  >
                    <div className="mb-3 flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <span className="text-[9px] font-extrabold uppercase tracking-[0.13em] text-[#0e8988]">
                          MODULE {String(moduleIndex + 1).padStart(2, "0")}
                        </span>

                        <h3
                          id={`module-${module.id}`}
                          className="mt-1 text-sm font-black text-[#102b4c]"
                        >
                          {module.title}
                        </h3>
                      </div>

                      <span className="shrink-0 rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">
                        {completedCount}/{module.lessons.length}
                      </span>
                    </div>

                    <div className="space-y-1">
                      {module.lessons.map((lesson, lessonIndex) => {
                        const isActive =
                          lesson.id === currentLessonId;

                        const href =
                          `/courses/${encodeURIComponent(courseSlug)}` +
                          `/learn/lessons/${lesson.id}`;

                        return (
                          <Link
                            key={lesson.id}
                            href={href}
                            onClick={onClose}
                            aria-current={isActive ? "page" : undefined}
                            className={`group flex items-start gap-3 rounded-xl p-3 transition ${
                              isActive
                                ? "bg-[#e8f5f3] ring-1 ring-[#bce5df]"
                                : "hover:bg-slate-50"
                            }`}
                          >
                            <StatusIcon
                              status={lesson.progress.status}
                            />

                            <div className="min-w-0 flex-1">
                              <p
                                className={`text-xs font-bold leading-5 ${
                                  isActive
                                    ? "text-[#087d7d]"
                                    : "text-slate-700 group-hover:text-[#102b4c]"
                                }`}
                              >
                                {lessonIndex + 1}. {lesson.title}
                              </p>

                              <div className="mt-1 flex flex-wrap items-center gap-2 text-[10px] text-slate-500">
                                <span className="capitalize">
                                  {lesson.content_type}
                                </span>

                                {lesson.duration_minutes !== null && (
                                  <>
                                    <span aria-hidden="true">·</span>
                                    <span>
                                      {formatDuration(
                                        lesson.duration_minutes
                                      )}
                                    </span>
                                  </>
                                )}
                              </div>
                            </div>

                            {isActive && (
                              <span
                                className="mt-1 h-2 w-2 shrink-0 rounded-full bg-[#0e8988]"
                                aria-label="Current lesson"
                              />
                            )}
                          </Link>
                        );
                      })}
                    </div>
                  </section>
                );
              })}
            </div>
          )}
        </div>

        {/* Sidebar Footer */}
        <div className="border-t border-slate-100 p-4">
          <Link
            href={`/courses/${encodeURIComponent(courseSlug)}`}
            onClick={onClose}
            className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 transition hover:border-[#0e8988] hover:text-[#0e8988]"
          >
            <span aria-hidden="true">←</span>
            Back to course details
          </Link>
        </div>
      </aside>
    </>
  );
}
