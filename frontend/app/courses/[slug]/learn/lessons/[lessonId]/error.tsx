
"use client";

interface LearningErrorProps {
  error: Error & {
    digest?: string;
  };
  reset: () => void;
}

export default function LearningError({
  reset,
}: LearningErrorProps) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f8fafc] p-6">
      <div className="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50 text-red-600">
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            className="h-7 w-7"
            aria-hidden="true"
          >
            <path d="M12 3 2.8 20h18.4L12 3Z" />
            <path d="M12 9v5M12 17h.01" />
          </svg>
        </div>

        <h1 className="mt-5 text-xl font-black text-[#102b4c]">
          Something went wrong
        </h1>

        <p className="mt-3 text-sm leading-7 text-slate-600">
          The learning page encountered an unexpected error.
          You can try loading it again.
        </p>

        <button
          type="button"
          onClick={reset}
          className="mt-7 min-h-12 w-full rounded-xl bg-[#092443] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#123b64]"
        >
          Reload learning page
        </button>
      </div>
    </main>
  );
}
