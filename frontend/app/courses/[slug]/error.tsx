
"use client";

import Link from "next/link";

interface Props {
  error: Error & {
    digest?: string;
  };
  reset: () => void;
}

export default function CourseDetailsError({
  error,
  reset,
}: Props) {
  return (
    <main
      role="alert"
      className="flex min-h-screen items-center justify-center bg-[#f8fafc] px-5 py-16"
    >
      <div className="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm sm:p-10">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50 text-red-600">
          <svg
            width="27"
            height="27"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v6M12 17h.01" />
          </svg>
        </div>

        <h1 className="mt-6 text-2xl font-black text-[#102b4c]">
          Unable to load this course
        </h1>

        <p className="mt-3 text-sm leading-7 text-slate-600">
          The course information could not be loaded.
          Please try again or return to the course catalogue.
        </p>

        {process.env.NODE_ENV === "development" && (
          <p className="mt-4 break-words rounded-lg bg-slate-50 p-3 text-left text-xs text-slate-500">
            {error.message}
          </p>
        )}

        <button
          type="button"
          onClick={reset}
          className="mt-7 min-h-12 w-full rounded-xl bg-[#092443] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#15476e]"
        >
          Try Again
        </button>

        <Link
          href="/courses"
          className="mt-5 inline-flex text-sm font-bold text-[#0e8988] hover:underline"
        >
          Back to courses
        </Link>

        {error.digest && (
          <p className="mt-6 text-[10px] text-slate-400">
            Reference: {error.digest}
          </p>
        )}
      </div>
    </main>
  );
}
