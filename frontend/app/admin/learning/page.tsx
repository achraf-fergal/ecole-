
"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import DashboardLayout from "@/components/dashboard/dashboard-layout";
import { apiGetMe, type AuthUser } from "@/lib/auth";
import {
  apiGetPlatformCenters,
  type PlatformCenter,
  type PaginatedResponse,
} from "@/lib/admin";

function StatusBadge({ status }: { status: string }) {
  const active = status === "active";

  return (
    <span
      className={`rounded-full px-3 py-1 text-[10px] font-extrabold uppercase ${
        active
          ? "bg-emerald-50 text-emerald-700"
          : "bg-slate-100 text-slate-600"
      }`}
    >
      {status}
    </span>
  );
}

export default function PlatformLearningCentersPage() {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [isCheckingAuth, setIsCheckingAuth] = useState(true);

  const [centers, setCenters] = useState<PlatformCenter[]>([]);
  const [pagination, setPagination] =
    useState<PaginatedResponse<PlatformCenter> | null>(null);

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [page, setPage] = useState(1);

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");

  // Verify the authenticated platform administrator.
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

  // Load centers only after platform administrator verification.
  useEffect(() => {
    if (!user?.is_platform_admin) {
      return;
    }

    let mounted = true;

    async function loadCenters() {
      setIsLoading(true);
      setError("");

      try {
        const result = await apiGetPlatformCenters({
          search,
          status,
          page,
          per_page: 20,
        });

        if (!mounted) return;

        setCenters(result.data);
        setPagination(result);
      } catch (err: unknown) {
        if (!mounted) return;

        setError(
          err instanceof Error
            ? err.message
            : "Unable to load centers."
        );
      } finally {
        if (mounted) {
          setIsLoading(false);
        }
      }
    }

    void loadCenters();

    return () => {
      mounted = false;
    };
  }, [user, search, status, page]);

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
        <div className="max-w-md rounded-2xl border border-red-200 bg-white p-8 text-center">
          <h1 className="text-xl font-black text-slate-900">
            Access Restricted
          </h1>

          <p className="mt-3 text-sm leading-7 text-slate-600">
            Platform Super Admin permissions are required to
            browse all language centers.
          </p>

          <Link
            href="/courses"
            className="mt-6 inline-flex rounded-xl bg-[#092443] px-6 py-3 text-xs font-bold text-white"
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
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded-lg bg-purple-50 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-purple-700">
              Platform Administration
            </span>

            <span className="text-xs text-slate-400">
              / Learning Centers
            </span>
          </div>

          <h1 className="mt-4 text-2xl font-black text-[#092443]">
            Select a Learning Center
          </h1>

          <p className="mt-2 text-sm leading-7 text-slate-500">
            Browse institutes and inspect their courses,
            modules, lessons and educational content.
          </p>
        </header>

        <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_200px]">
          <input
            type="search"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            placeholder="Search center name or slug..."
            className="min-h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-teal-500"
          />

          <select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setPage(1);
            }}
            className="min-h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-700 outline-none focus:border-teal-500"
          >
            <option value="all">All center statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
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

        <div className="flex items-center justify-between">
          <h2 className="text-sm font-black text-[#092443]">
            Available institutes
          </h2>

          <span className="text-xs text-slate-500">
            {pagination?.total ?? 0} centers
          </span>
        </div>

        {isLoading ? (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {Array.from({ length: 6 }).map((_, index) => (
              <div
                key={index}
                className="h-48 animate-pulse rounded-2xl border border-slate-200 bg-white"
              />
            ))}
          </div>
        ) : centers.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center">
            <h3 className="font-black text-slate-800">
              No institutes found
            </h3>

            <p className="mt-2 text-xs text-slate-500">
              Try changing the search or status filter.
            </p>
          </div>
        ) : (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {centers.map((center) => (
              <article
                key={center.id}
                className="rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-teal-300 hover:shadow-sm"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#092443] text-sm font-black text-white">
                    {center.name.charAt(0).toUpperCase()}
                  </div>

                  <StatusBadge status={center.status} />
                </div>

                <h3 className="mt-4 text-base font-black text-[#102b4c]">
                  {center.name}
                </h3>

                <p className="mt-1 text-xs text-slate-500">
                  {center.slug}
                </p>

                <div className="mt-5 grid grid-cols-2 gap-3 border-y border-slate-100 py-4">
                  <div>
                    <p className="text-lg font-black text-[#092443]">
                      {center.courses_count}
                    </p>
                    <p className="text-[10px] font-semibold text-slate-500">
                      Courses
                    </p>
                  </div>

                  <div>
                    <p className="text-lg font-black text-[#092443]">
                      {center.members_count}
                    </p>
                    <p className="text-[10px] font-semibold text-slate-500">
                      Memberships
                    </p>
                  </div>
                </div>

                {center.status === "active" ? (
                  <Link
                    href={`/admin/learning/centers/${encodeURIComponent(center.slug)}/courses`}
                    className="mt-4 flex min-h-11 items-center justify-center rounded-xl bg-[#092443] px-4 text-xs font-extrabold text-white transition hover:bg-[#123b64]"
                  >
                    Select Center
                    <span className="ml-2">→</span>
                  </Link>
                ) : (
                  <div
                    className="mt-4 rounded-xl bg-slate-100 p-3 text-center text-[11px] font-semibold text-slate-500"
                    title="Learning preview is unavailable for inactive centers."
                  >
                    Preview unavailable — center suspended
                  </div>
                )}
              </article>
            ))}
          </div>
        )}

        {pagination && pagination.last_page > 1 && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
            <span className="text-xs text-slate-500">
              Page {pagination.current_page} of {pagination.last_page}
            </span>

            <div className="flex gap-2">
              <button
                type="button"
                disabled={page <= 1 || isLoading}
                onClick={() => setPage((current) => current - 1)}
                className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-bold disabled:opacity-40"
              >
                Previous
              </button>

              <button
                type="button"
                disabled={
                  page >= pagination.last_page || isLoading
                }
                onClick={() => setPage((current) => current + 1)}
                className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-bold disabled:opacity-40"
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
