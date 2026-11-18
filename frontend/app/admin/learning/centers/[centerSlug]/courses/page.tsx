
"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { Suspense, useEffect, useRef, useState } from "react";
import DashboardLayout from "@/components/dashboard/dashboard-layout";
import { apiGetMe, type AuthUser } from "@/lib/auth";

import {
  apiGetPlatformCenterCourses,
  apiGetPlatformLearningOverview,
  type PlatformCenter,
  type PlatformLearningCourse,
  type AdminLearningOverview,
} from "@/lib/admin";

function StatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    draft: "bg-amber-50 text-amber-700",
    published: "bg-emerald-50 text-emerald-700",
    archived: "bg-slate-100 text-slate-600",
    active: "bg-emerald-50 text-emerald-700",
    suspended: "bg-red-50 text-red-700",
  };

  return (
    <span
      className={`rounded-full px-3 py-1 text-[10px] font-extrabold uppercase ${
        styles[status] || "bg-slate-100 text-slate-600"
      }`}
    >
      {status}
    </span>
  );
}

function PlatformCenterCoursesContent() {  const params = useParams<{ centerSlug: string }>();
  const centerSlug = params.centerSlug;

  const [user, setUser] = useState<AuthUser | null>(null);
  const [isCheckingAuth, setIsCheckingAuth] = useState(true);

  const [center, setCenter] = useState<PlatformCenter | null>(null);
  const [courses, setCourses] = useState<PlatformLearningCourse[]>([]);
  const [pagination, setPagination] = useState<{
    current_page: number;
    last_page: number;
    total: number;
  } | null>(null);

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [page, setPage] = useState(1);

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");

  const [selectedCourse, setSelectedCourse] =
    useState<PlatformLearningCourse | null>(null);

  const [overview, setOverview] =
    useState<AdminLearningOverview | null>(null);

  const [isLoadingPreview, setIsLoadingPreview] =
    useState(false);

  const [previewError, setPreviewError] = useState("");
  // Reference to the curriculum preview section.
  const previewSectionRef = useRef<HTMLElement | null>(null);
  useEffect(() => {
    let mounted = true;

    async function verifyUser() {
      try {
        const authenticatedUser = await apiGetMe();

        if (mounted) {
          setUser(authenticatedUser);
        }
      } finally {
        if (mounted) {
          setIsCheckingAuth(false);
        }
      }
    }

    void verifyUser();

    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    if (!user?.is_platform_admin) {
      return;
    }

    let mounted = true;

    async function loadCourses() {
      setIsLoading(true);
      setError("");
      setOverview(null);
      setSelectedCourse(null);

      try {
        const result = await apiGetPlatformCenterCourses(
          centerSlug,
          {
            search,
            status,
            page,
            per_page: 20,
          }
        );

        if (!mounted) return;

        setCenter({
          id: result.center.id,
          name: result.center.name,
          slug: result.center.slug,
          status: result.center.status,
          logo_url: result.center.logo_url,
          default_locale: result.center.default_locale,
          courses_count: result.courses.total,
          members_count: 0,
        });

        setCourses(result.courses.data);

        setPagination({
          current_page: result.courses.current_page,
          last_page: result.courses.last_page,
          total: result.courses.total,
        });
      } catch (err: unknown) {
        if (!mounted) return;

        setError(
          err instanceof Error
            ? err.message
            : "Unable to load center courses."
        );
      } finally {
        if (mounted) {
          setIsLoading(false);
        }
      }
    }

    void loadCourses();

    return () => {
      mounted = false;
    };
  }, [user, centerSlug, search, status, page]);

  async function handleInspectCourse(
    course: PlatformLearningCourse
  ) {
    if (!center || center.status !== "active") {
      return;
    }

    setSelectedCourse(course);
    setOverview(null);
    setPreviewError("");
    setIsLoadingPreview(true);

    try {
      const result = await apiGetPlatformLearningOverview(
        center.slug,
        course.slug
      );

      setOverview(result);
    } catch (err: unknown) {
      console.error(
        "Administrative curriculum preview failed:",
        err
      );
    
      setPreviewError(
        err instanceof Error
          ? err.message
          : "Unable to inspect this course."
      );
    }finally {
      setIsLoadingPreview(false);
    }
  }
  // Automatically bring the curriculum preview into view after loading.
  useEffect(() => {
    if (isLoadingPreview || !selectedCourse) {
      return;
    }
  
    const frame = window.requestAnimationFrame(() => {
      previewSectionRef.current?.scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
    });
  
    return () => {
      window.cancelAnimationFrame(frame);
    };
  }, [
    isLoadingPreview,
    selectedCourse,
    overview,
    previewError,
  ]);
  if (isCheckingAuth) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <p className="text-sm font-bold text-slate-500">
          Verifying platform access...
        </p>
      </div>
    );
  }

  if (!user || !user.is_platform_admin) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <div className="rounded-2xl border border-red-200 bg-white p-8 text-center">
          <h1 className="font-black text-slate-900">
            Access Restricted
          </h1>

          <Link
            href="/courses"
            className="mt-5 inline-block text-sm font-bold text-teal-700"
          >
            Return to courses
          </Link>
        </div>
      </main>
    );
  }

  return (
    <DashboardLayout
      user={user}
      showCenterSwitcher={false}
    >
      <div className="space-y-7">
        <header>
          <Link
            href="/admin/learning"
            className="text-xs font-bold text-[#0e8988] hover:underline"
          >
            ← Change Learning Center
          </Link>

          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-2xl font-black text-[#092443]">
                {center?.name || "Learning Center"}
              </h1>

              <p className="mt-2 text-xs text-slate-500">
                {centerSlug}
              </p>
            </div>

            {center && <StatusBadge status={center.status} />}
          </div>
        </header>

        {center?.status !== "active" && center && (
          <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            This center is suspended. Learning previews are currently unavailable.
          </div>
        )}

        <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_200px]">
          <input
            type="search"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            placeholder="Search course title or slug..."
            className="min-h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-teal-500"
          />

          <select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setPage(1);
            }}
            className="min-h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold outline-none focus:border-teal-500"
          >
            <option value="all">All statuses</option>
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="archived">Archived</option>
          </select>
        </section>

        {error && (
          <div
            role="alert"
            className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
          >
            {error}
          </div>
        )}

        <section className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-black text-[#092443]">
              Courses
            </h2>

            <span className="text-xs text-slate-500">
              {pagination?.total ?? 0} courses
            </span>
          </div>

          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 4 }).map((_, index) => (
                <div
                  key={index}
                  className="h-24 animate-pulse rounded-2xl bg-white"
                />
              ))}
            </div>
          ) : courses.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
              <h3 className="font-black text-slate-800">
                No courses found
              </h3>
            </div>
          ) : (
            <div className="space-y-3">
              {courses.map((course) => (
                <article
                  key={course.id}
                  className={`rounded-2xl border bg-white p-5 transition ${
                    selectedCourse?.id === course.id
                      ? "border-teal-400 ring-2 ring-teal-50"
                      : "border-slate-200"
                  }`}
                >
                  <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-base font-black text-[#102b4c]">
                          {course.title}
                        </h3>

                        <StatusBadge status={course.status} />
                      </div>

                      <p className="mt-1 text-xs text-slate-500">
                        {course.slug}
                      </p>

                      {course.short_description && (
                        <p className="mt-3 text-xs leading-6 text-slate-600">
                          {course.short_description}
                        </p>
                      )}

                      <div className="mt-3 flex flex-wrap gap-4 text-[11px] text-slate-500">
                        <span>
                          {course.modules_count} modules
                        </span>

                        {course.duration_hours !== null && (
                          <span>
                            {course.duration_hours} hours
                          </span>
                        )}
                      </div>
                    </div>

                    <button
                      type="button"
                      disabled={
                        center?.status !== "active" ||
                        isLoadingPreview
                      }
                      onClick={() => void handleInspectCourse(course)}
                      className="min-h-11 shrink-0 rounded-xl bg-[#092443] px-5 text-xs font-extrabold text-white transition hover:bg-[#123b64] disabled:cursor-not-allowed disabled:bg-slate-300"
                    >
                      {selectedCourse?.id === course.id &&
                      isLoadingPreview
                        ? "Loading..."
                        : "Inspect Curriculum"}
                    </button>
                  </div>
                </article>
              ))}
            </div>
          )}
        </section>

        {pagination && pagination.last_page > 1 && (
          <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
            <span className="text-xs text-slate-500">
              Page {pagination.current_page} of {pagination.last_page}
            </span>

            <div className="flex gap-2">
              <button
                type="button"
                disabled={page <= 1 || isLoading}
                onClick={() => setPage((value) => value - 1)}
                className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-bold disabled:opacity-40"
              >
                Previous
              </button>

              <button
                type="button"
                disabled={
                  page >= pagination.last_page || isLoading
                }
                onClick={() => setPage((value) => value + 1)}
                className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-bold disabled:opacity-40"
              >
                Next
              </button>
            </div>
          </div>
        )}

        {/* Administrative curriculum preview */}
        {selectedCourse && (
  <section
    ref={previewSectionRef}
    aria-live="polite"
    className="scroll-mt-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-7"
  >         
     <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <span className="text-[10px] font-extrabold uppercase tracking-wider text-purple-700">
                  Administrative Preview
                </span>

                <h2 className="mt-2 text-xl font-black text-[#092443]">
                  {selectedCourse.title}
                </h2>
              </div>

              <StatusBadge status={selectedCourse.status} />
            </div>

            {previewError && (
              <div
                role="alert"
                className="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
              >
                {previewError}
              </div>
            )}

            {isLoadingPreview && (
              <p className="mt-5 text-sm text-slate-500">
                Loading curriculum...
              </p>
            )}

            {overview && (
              <div className="mt-6 space-y-5">
                <p className="text-xs text-slate-500">
                  {overview.modules.length} modules ·{" "}
                  {overview.progress_summary.total_lessons} lessons
                </p>

                {overview.modules.map((module, index) => (
                  <div
                    key={module.id}
                    className="rounded-xl border border-slate-200 p-4"
                  >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <p className="text-[10px] font-extrabold uppercase text-teal-700">
                          Module {index + 1}
                        </p>

                        <h3 className="mt-1 text-sm font-black text-[#102b4c]">
                          {module.title}
                        </h3>
                      </div>

                      <StatusBadge status={module.status} />
                    </div>

                    {module.description && (
                      <p className="mt-2 text-xs leading-6 text-slate-500">
                        {module.description}
                      </p>
                    )}

                    <div className="mt-4 divide-y divide-slate-100">
                      {module.lessons.map((lesson) => {
                        const lessonPreviewHref =
                          `/admin/learning/centers/${encodeURIComponent(centerSlug)}` +
                          `/courses/${encodeURIComponent(selectedCourse.slug)}` +
                          `/lessons/${lesson.id}`;

                        return (
                          <div
                            key={lesson.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-3"
                          >
                            <div className="min-w-0">
                              <Link
                                href={lessonPreviewHref}
                                className="text-xs font-bold text-slate-700 transition hover:text-[#0e8988] hover:underline"
                              >
                                {lesson.title}
                              </Link>

                              <p className="mt-1 text-[10px] capitalize text-slate-500">
                                {lesson.content_type}
                                {lesson.duration_minutes
                                  ? ` · ${lesson.duration_minutes} min`
                                  : ""}
                              </p>
                            </div>

                            <div className="flex items-center gap-3">
                              <StatusBadge status={lesson.status} />

                              <Link
                                href={lessonPreviewHref}
                                aria-label={`Preview ${lesson.title}`}
                                className="text-[10px] font-extrabold text-[#0e8988] hover:underline"
                              >
                                Open lesson →
                              </Link>
                            </div>
                          </div>
                        );
                      })}

                      {module.lessons.length === 0 && (
                        <p className="py-4 text-xs text-slate-400">
                          No lessons in this module.
                        </p>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </section>
        )}
      </div>
    </DashboardLayout>
  );
}
export default function PlatformCenterCoursesPage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center bg-slate-50">
          <p className="text-sm font-bold text-slate-500">
            Loading learning center...
          </p>
        </div>
      }
    >
      <PlatformCenterCoursesContent />
    </Suspense>
  );
}
