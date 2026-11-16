
"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";

import { apiGetMe, type AuthUser } from "@/lib/auth";

import LearningSidebar from "./learning-sidebar";
import LessonAssetViewer from "./lesson-asset-viewer";
import LearningPageSkeleton from "./learning-page-skeleton";

import {
  getLearningLesson,
  getLearningOverview,
  updateLessonProgress,
  LearningApiError,
  type LearningLessonData,
  type LearningOverview,
  type LearningRouteParams,
  type UpdateLessonProgressPayload,
} from "@/lib/learning";

interface LearningPageClientProps {
  centerSlug: string;
  courseSlug: string;
  lessonId: number;
}

type PageError =
  | "forbidden"
  | "not_found"
  | "network"
  | "server";

function ErrorState({
  type,
  onRetry,
}: {
  type: PageError;
  onRetry: () => void;
}) {
  const content = {
    forbidden: {
      title: "Access restricted",
      description:
        "You do not have active permission to access this course or lesson. Contact your learning center for assistance.",
    },
    not_found: {
      title: "Lesson not found",
      description:
        "This lesson may have been removed, unpublished, or is no longer available.",
    },
    network: {
      title: "Connection problem",
      description:
        "We could not connect to the learning service. Check your connection and try again.",
    },
    server: {
      title: "Unable to load your lesson",
      description:
        "An unexpected error occurred while loading the learning content.",
    },
  }[type];

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f8fafc] p-6">
      <div className="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-[#fff3e3] text-[#c77c20]">
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            className="h-7 w-7"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v5M12 16h.01" />
          </svg>
        </div>

        <h1 className="mt-5 text-xl font-black text-[#102b4c]">
          {content.title}
        </h1>

        <p className="mt-3 text-sm leading-7 text-slate-600">
          {content.description}
        </p>

        <button
          type="button"
          onClick={onRetry}
          className="mt-7 min-h-12 w-full rounded-xl bg-[#092443] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#123b64]"
        >
          Try again
        </button>

        <Link
          href="/courses"
          className="mt-5 inline-block text-xs font-bold text-[#0e8988] hover:underline"
        >
          Back to courses
        </Link>
      </div>
    </main>
  );
}

function calculateProgress(
  overview: LearningOverview
): LearningOverview {
  const completed = overview.modules.reduce(
    (total, module) =>
      total +
      module.lessons.filter(
        (lesson) => lesson.progress.status === "completed"
      ).length,
    0
  );

  const inProgress = overview.modules.reduce(
    (total, module) =>
      total +
      module.lessons.filter(
        (lesson) => lesson.progress.status === "in_progress"
      ).length,
    0
  );

  const notStarted = Math.max(
    0,
    overview.progress_summary.total_lessons - completed - inProgress
  );

  const percentage =
    overview.progress_summary.total_lessons > 0
      ? Number(
          (
            (completed /
              overview.progress_summary.total_lessons) *
            100
          ).toFixed(1)
        )
      : 0;

  return {
    ...overview,
    progress_summary: {
      ...overview.progress_summary,
      completed_lessons: completed,
      in_progress_lessons: inProgress,
      not_started_lessons: notStarted,
      completion_percentage: percentage,
    },
  };
}

export default function LearningPageClient({
  centerSlug,
  courseSlug,
  lessonId,
}: LearningPageClientProps) {
  const router = useRouter();

  const routeParams = useMemo<LearningRouteParams>(
    () => ({
      centerSlug,
      courseSlug,
      lessonId,
    }),
    [centerSlug, courseSlug, lessonId]
  );

  const currentPath =
    `/courses/${encodeURIComponent(courseSlug)}` +
    `/learn/lessons/${lessonId}`;

  const [overview, setOverview] =
    useState<LearningOverview | null>(null);

  const [lessonData, setLessonData] =
    useState<LearningLessonData | null>(null);

  const [currentUser, setCurrentUser] =
    useState<AuthUser | null>(null);

  const [isLoading, setIsLoading] = useState(true);
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);

  const [pageError, setPageError] =
    useState<PageError | null>(null);

  const [isSavingProgress, setIsSavingProgress] =
    useState(false);

  const [progressError, setProgressError] = useState("");
  const [progressMessage, setProgressMessage] = useState("");

  const loadLearningPage = useCallback(
    async (signal?: AbortSignal) => {
      setIsLoading(true);
      setPageError(null);

      try {
        // Retrieve course curriculum and current lesson concurrently.
        const [overviewResult, lessonResult, userResult] =
          await Promise.all([
            getLearningOverview(
              centerSlug,
              courseSlug,
              signal
            ),
            getLearningLesson(routeParams, signal),
            apiGetMe(),
          ]);

        if (signal?.aborted) return;

        setOverview(overviewResult);
        setLessonData(lessonResult);
        setCurrentUser(userResult);
      } catch (error: unknown) {
        if (signal?.aborted) return;

        if (error instanceof LearningApiError) {
          if (error.status === 401) {
            router.replace(
              `/login?redirect=${encodeURIComponent(currentPath)}`
            );
            return;
          }

          if (error.status === 403) {
            setPageError("forbidden");
            return;
          }

          if (error.status === 404) {
            setPageError("not_found");
            return;
          }

          if (error.status === 0) {
            setPageError("network");
            return;
          }
        }

        setPageError("server");
      } finally {
        if (!signal?.aborted) {
          setIsLoading(false);
        }
      }
    },
    [
      centerSlug,
      courseSlug,
      routeParams,
      currentPath,
      router,
    ]
  );

  useEffect(() => {
    const controller = new AbortController();

    queueMicrotask(() => {
      void loadLearningPage(controller.signal);
    });

    return () => {
      controller.abort();
    };
  }, [loadLearningPage]);

  /**
   * Apply a server-confirmed progress update to both views.
   */
  const saveProgress = useCallback(
    async (
      payload: UpdateLessonProgressPayload
    ): Promise<boolean> => {
      // Administrative previews must never modify student progress.
      if (
        overview?.access_mode !== "student" ||
        lessonData?.access_mode !== "student"
      ) {
        return false;
      }

      if (isSavingProgress) return false;

      setIsSavingProgress(true);
      setProgressError("");
      setProgressMessage("");

      try {
        const result = await updateLessonProgress(
          routeParams,
          payload
        );

        setLessonData((current) => {
          if (!current) return current;

          return {
            ...current,
            progress: {
              ...current.progress,
              status: result.status,
              last_position_seconds:
                result.last_position_seconds,
              started_at: result.started_at,
              completed_at: result.completed_at,
              last_accessed_at: result.last_accessed_at,
            },
          };
        });

        setOverview((current) => {
          if (!current) return current;

          const updated: LearningOverview = {
            ...current,
            modules: current.modules.map((module) => ({
              ...module,
              lessons: module.lessons.map((lesson) =>
                lesson.id === result.lesson_id
                  ? {
                      ...lesson,
                      progress: {
                        status: result.status,
                        last_position_seconds:
                          result.last_position_seconds,
                        started_at: result.started_at,
                        completed_at: result.completed_at,
                        last_accessed_at: result.last_accessed_at,
                      },
                    }
                  : lesson
              ),
            })),
          };

          return calculateProgress(updated);
        });

        if (payload.completed) {
          setProgressMessage("Lesson completed successfully.");
        }

        return true;
      } catch (error: unknown) {
        if (
          error instanceof LearningApiError &&
          error.status === 401
        ) {
          router.replace(
            `/login?redirect=${encodeURIComponent(currentPath)}`
          );
          return false;
        }

        setProgressError(
          error instanceof Error
            ? error.message
            : "Unable to save lesson progress."
        );

        return false;
      } finally {
        setIsSavingProgress(false);
      }
    },
    [
      routeParams,
      currentPath,
      router,
      isSavingProgress,
      overview?.access_mode,
      lessonData?.access_mode,
    ]
  );

  if (isLoading) {
    return <LearningPageSkeleton />;
  }

  if (pageError) {
    return (
      <ErrorState
        type={pageError}
        onRetry={() => void loadLearningPage()}
      />
    );
  }

  if (!overview || !lessonData) {
    return (
      <ErrorState
        type="server"
        onRetry={() => void loadLearningPage()}
      />
    );
  }

  const { lesson, module, progress, navigation } =
    lessonData;

  // The backend response determines the active learning mode.
  const isAdminPreview =
    overview.access_mode === "admin_preview" ||
    lessonData.access_mode === "admin_preview";

  const isCompleted =
    !isAdminPreview && progress.status === "completed";

  const previousHref = navigation.previous_lesson
    ? `${currentPath.replace(
        /\/[^/]+$/,
        `/${navigation.previous_lesson.id}`
      )}`
    : null;

  const nextHref = navigation.next_lesson
    ? `/courses/${encodeURIComponent(courseSlug)}/learn/lessons/${navigation.next_lesson.id}`
    : null;

  return (
    <div className="min-h-screen bg-[#f8fafc]">
      {/* Learning Header */}
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur-md">
        <div className="mx-auto flex min-h-[72px] max-w-[1600px] items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
          <div className="flex min-w-0 items-center gap-3">
            <Link
              href={`/courses/${encodeURIComponent(courseSlug)}`}
              aria-label="Back to course details"
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-[#102b4c] transition hover:bg-slate-50"
            >
              <span aria-hidden="true">←</span>
            </Link>

            <div className="min-w-0">
              <p className="text-[9px] font-extrabold uppercase tracking-[0.16em] text-[#0e8988]">
                LEARNING PORTAL
              </p>

              <h1 className="truncate text-sm font-black text-[#102b4c] sm:text-base">
                {overview.course.title}
              </h1>
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-3">
            {isAdminPreview && (
              <span className="rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-[9px] font-extrabold uppercase tracking-wide text-indigo-700">
                Admin Preview
              </span>
            )}

            <div className="hidden text-right sm:block">
              <p className="text-[10px] text-slate-500">
                Signed in as
              </p>

              <p className="max-w-[180px] truncate text-xs font-bold text-[#102b4c]">
                {currentUser?.name || "Student"}
              </p>
            </div>

            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-[#092443] text-sm font-black text-white">
              {(currentUser?.name || "S")
                .charAt(0)
                .toUpperCase()}
            </div>
          </div>
        </div>
      </header>

      <div className="mx-auto flex min-h-[calc(100vh-72px)] max-w-[1600px]">
        {/* Curriculum Sidebar */}
        <LearningSidebar
          overview={overview}
          courseSlug={courseSlug}
          currentLessonId={lesson.id}
          isMobileOpen={isSidebarOpen}
          onClose={() => setIsSidebarOpen(false)}
        />

        {/* Main Lesson Area */}
        <main className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          <div className="mx-auto max-w-5xl">
            {/* Mobile Curriculum Toggle */}
            <button
              type="button"
              onClick={() => setIsSidebarOpen(true)}
              className="mb-5 inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-[#102b4c] shadow-sm transition hover:border-[#0e8988] md:hidden"
            >
              <span aria-hidden="true">☰</span>
              Course contents
            </button>

            {/* Lesson Heading */}
            <div className="mb-6">
              <div className="flex flex-wrap items-center gap-2 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                <span>{overview.course.title}</span>
                <span aria-hidden="true">/</span>
                <span>{module.title}</span>
              </div>

              <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
                <div>
                  <h2 className="text-2xl font-black tracking-tight text-[#102b4c] sm:text-3xl">
                    {lesson.title}
                  </h2>

                  {lesson.description && (
                    <p className="mt-3 max-w-3xl text-sm leading-7 text-slate-600">
                      {lesson.description}
                    </p>
                  )}
                </div>

                {isAdminPreview ? (
                  <span
                    className={`rounded-full px-3 py-1.5 text-[10px] font-extrabold uppercase ${
                      lesson.status === "published"
                        ? "bg-emerald-50 text-emerald-700"
                        : lesson.status === "draft"
                          ? "bg-amber-50 text-amber-700"
                          : "bg-slate-100 text-slate-600"
                    }`}
                  >
                    {lesson.status}
                  </span>
                ) : (
                  <span
                    className={`rounded-full px-3 py-1.5 text-[10px] font-extrabold ${
                      isCompleted
                        ? "bg-[#e5f5f2] text-[#087b83]"
                        : "bg-amber-50 text-amber-700"
                    }`}
                  >
                    {isCompleted ? "COMPLETED" : "IN PROGRESS"}
                  </span>
                )}
              </div>

              <div className="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                {lesson.duration_minutes !== null && (
                  <span>
                    {lesson.duration_minutes} minutes
                  </span>
                )}

                <span className="capitalize">
                  {lesson.content_type} lesson
                </span>

                {!isAdminPreview && (
                  <span>
                    {overview.progress_summary.completion_percentage}%
                    {" "}course progress
                  </span>
                )}

                {isAdminPreview && (
                  <span className="font-bold text-indigo-700">
                    Course status: {overview.course.status}
                  </span>
                )}
              </div>
            </div>

            {/* Lesson Content */}
            <section className="space-y-6">
              {lesson.body && (
                <article className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                  <h3 className="text-sm font-black text-[#102b4c]">
                    Lesson content
                  </h3>

                  <div className="mt-4 whitespace-pre-wrap text-sm leading-8 text-slate-700">
                    {lesson.body}
                  </div>
                </article>
              )}

              {/* Lesson Assets */}
              {lessonData.assets.length > 0 && (
                <section aria-labelledby="lesson-resources-heading">
                  <div className="mb-4">
                    <h3
                      id="lesson-resources-heading"
                      className="text-lg font-black text-[#102b4c]"
                    >
                      Lesson resources
                    </h3>

                    <p className="mt-1 text-xs text-slate-500">
                      Videos, images, documents and additional learning resources.
                    </p>
                  </div>

                  <div className="space-y-4">
                    {lessonData.assets.map((asset) => (
                      <LessonAssetViewer
                        key={asset.id}
                        asset={asset}
                        params={routeParams}
                      />
                    ))}
                  </div>
                </section>
              )}

              {!lesson.body && lessonData.assets.length === 0 && (
                <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                  <h3 className="font-black text-[#102b4c]">
                    {isAdminPreview
                      ? "No lesson content has been added"
                      : "Lesson content is not available"}
                  </h3>

                  <p className="mt-2 text-sm text-slate-500">
                    {isAdminPreview
                      ? "This lesson does not contain text or active media resources yet."
                      : "Your instructor has not added resources to this lesson yet."}
                  </p>
                </div>
              )}

              {/* Exercises Preview */}
              {lessonData.exercises.length > 0 && (
                <section>
                  <h3 className="mb-4 text-lg font-black text-[#102b4c]">
                    Exercises
                  </h3>

                  <div className="space-y-3">
                    {lessonData.exercises.map((exercise) => (
                      <article
                        key={exercise.id}
                        className="rounded-2xl border border-slate-200 bg-white p-5"
                      >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <h4 className="font-extrabold text-[#102b4c]">
                              {exercise.title}
                            </h4>

                            {exercise.instructions && (
                              <p className="mt-2 text-xs leading-6 text-slate-600">
                                {exercise.instructions}
                              </p>
                            )}

                            <p className="mt-3 text-[11px] text-slate-500">
                              {exercise.questions.length} questions
                              {" · "}
                              Passing score: {exercise.passing_score}%
                            </p>

                            {isAdminPreview && (
                              <span className="mt-2 inline-block rounded-md bg-amber-50 px-2 py-1 text-[9px] font-extrabold uppercase text-amber-700">
                                {exercise.status}
                              </span>
                            )}
                          </div>

                          {!isAdminPreview && (
                            <span className="rounded-full bg-amber-50 px-3 py-1 text-[10px] font-bold text-amber-700">
                              Assessment coming next
                            </span>
                          )}
                        </div>

                        {isAdminPreview &&
                          exercise.questions.length > 0 && (
                            <div className="mt-5 space-y-4 border-t border-slate-100 pt-4">
                              {exercise.questions.map(
                                (question, questionIndex) => (
                                  <div
                                    key={question.id}
                                    className="rounded-xl bg-slate-50 p-4"
                                  >
                                    <p className="text-xs font-extrabold text-[#102b4c]">
                                      Question {questionIndex + 1}
                                    </p>

                                    <p className="mt-2 text-sm leading-6 text-slate-700">
                                      {question.prompt}
                                    </p>

                                    <div className="mt-3 space-y-2">
                                      {question.options.map(
                                        (option) => (
                                          <div
                                            key={option.id}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white p-3"
                                          >
                                            <span className="text-xs text-slate-700">
                                              {option.option_text}
                                            </span>

                                            {option.is_correct ===
                                              true && (
                                                <span className="rounded-md bg-emerald-50 px-2 py-1 text-[9px] font-extrabold text-emerald-700">
                                                  Correct Answer
                                                </span>
                                              )}
                                          </div>
                                        )
                                      )}
                                    </div>

                                    {question.explanation && (
                                      <div className="mt-3 rounded-lg border border-indigo-100 bg-indigo-50 p-3">
                                        <p className="text-[10px] font-extrabold uppercase text-indigo-700">
                                          Explanation
                                        </p>

                                        <p className="mt-1 text-xs leading-6 text-indigo-900">
                                          {question.explanation}
                                        </p>
                                      </div>
                                    )}
                                  </div>
                                )
                              )}
                            </div>
                          )}
                      </article>
                    ))}
                  </div>
                </section>
              )}

              {!isAdminPreview && (
                <>
                  {/* Progress Feedback */}
                  {progressError && (
                    <div
                      role="alert"
                      className="rounded-xl border border-red-200 bg-red-50 p-4 text-xs text-red-700"
                    >
                      {progressError}
                    </div>
                  )}

                  {progressMessage && (
                    <div
                      role="status"
                      className="rounded-xl border border-[#bce5df] bg-[#e8f5f3] p-4 text-xs font-bold text-[#087b83]"
                    >
                      {progressMessage}
                    </div>
                  )}

                  {/* Lesson Actions */}
                  <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                    <div>
                      <p className="text-sm font-black text-[#102b4c]">
                        Lesson completion
                      </p>

                      <p className="mt-1 text-xs text-slate-500">
                        Mark this lesson as completed when you finish studying.
                      </p>
                    </div>

                    <button
                      type="button"
                      disabled={isCompleted || isSavingProgress}
                      onClick={() =>
                        void saveProgress({ completed: true })
                      }
                      className="min-h-12 rounded-xl bg-[#0e8988] px-6 py-3 text-xs font-extrabold text-white transition hover:bg-[#087b83] disabled:cursor-not-allowed disabled:bg-slate-300"
                    >
                      {isSavingProgress
                        ? "Saving..."
                        : isCompleted
                          ? "Lesson completed"
                          : "Complete lesson"}
                    </button>
                  </div>
                </>
              )}

              {/* Previous and Next Navigation */}
              <nav
                aria-label="Lesson navigation"
                className="grid gap-3 border-t border-slate-200 pt-6 sm:grid-cols-2"
              >
                {previousHref ? (
                  <Link
                    href={previousHref}
                    className="rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-[#0e8988] hover:bg-[#f0faf8]"
                  >
                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                      ← Previous lesson
                    </span>

                    <span className="mt-2 block text-sm font-extrabold text-[#102b4c]">
                      {navigation.previous_lesson?.title}
                    </span>
                  </Link>
                ) : (
                  <div className="rounded-2xl border border-dashed border-slate-200 p-4">
                    <p className="text-xs text-slate-400">
                      This is the first lesson.
                    </p>
                  </div>
                )}

                {nextHref ? (
                  <Link
                    href={nextHref}
                    className="rounded-2xl border border-slate-200 bg-white p-4 text-right transition hover:border-[#0e8988] hover:bg-[#f0faf8]"
                  >
                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                      Next lesson →
                    </span>

                    <span className="mt-2 block text-sm font-extrabold text-[#102b4c]">
                      {navigation.next_lesson?.title}
                    </span>
                  </Link>
                ) : (
                  <div className="rounded-2xl border border-dashed border-slate-200 p-4 text-right">
                    <p className="text-xs text-slate-400">
                      {isAdminPreview
                        ? "You have reached the last lesson in this course."
                        : "You have reached the last published lesson."}
                    </p>
                  </div>
                )}
              </nav>
            </section>
          </div>
        </main>
      </div>
    </div>
  );
}
