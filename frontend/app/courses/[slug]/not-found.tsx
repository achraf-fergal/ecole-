
import Link from "next/link";

export default function CourseNotFound() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f8fafc] px-5 py-16">
      <div className="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm sm:p-10">
        <span className="text-6xl font-black text-[#0e8988]">
          404
        </span>

        <h1 className="mt-5 text-2xl font-black text-[#102b4c]">
          Course not found
        </h1>

        <p className="mt-3 text-sm leading-7 text-slate-600">
          This course does not exist or is no longer available.
        </p>

        <p className="mt-2 text-xs leading-6 text-slate-400">
          Ce cours n&apos;existe pas ou n&apos;est plus disponible.
        </p>

        <Link
          href="/courses"
          className="mt-7 inline-flex min-h-12 items-center justify-center rounded-full bg-[#f6a23b] px-7 py-3 text-sm font-extrabold text-[#102b4c] transition hover:bg-[#ffb451]"
        >
          Explore courses
        </Link>
      </div>
    </main>
  );
}
