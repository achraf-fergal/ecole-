
export default function CoursesLoading() {
  return (
    <main
      aria-label="Loading courses"
      aria-busy="true"
      className="min-h-screen bg-[#f8fafc]"
    >
      <div className="h-16 bg-[#092443]" />

      <div className="mx-auto max-w-[1440px] animate-pulse px-5 py-10 sm:px-8 lg:px-12">

        <div className="mb-8 h-4 w-32 rounded bg-slate-200" />

        <div className="mb-4 h-10 max-w-lg rounded-lg bg-slate-200" />

        <div className="mb-10 h-5 max-w-xl rounded bg-slate-200" />

        {/* Search and filters */}
        <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-4">
          <div className="h-12 rounded-xl bg-slate-100" />
          <div className="h-12 rounded-xl bg-slate-100" />
          <div className="h-12 rounded-xl bg-slate-100" />
          <div className="h-12 rounded-xl bg-slate-100" />
        </div>

        <div className="mb-8 mt-10 h-8 w-64 rounded bg-slate-200" />

        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 6 }).map((_, index) => (
            <div
              key={index}
              className="overflow-hidden rounded-2xl border border-slate-200 bg-white"
            >
              <div className="aspect-[16/9] bg-slate-200" />

              <div className="space-y-4 p-6">
                <div className="h-5 w-28 rounded bg-slate-200" />
                <div className="h-7 w-3/4 rounded bg-slate-200" />
                <div className="h-4 w-full rounded bg-slate-100" />
                <div className="h-4 w-2/3 rounded bg-slate-100" />
                <div className="h-11 w-full rounded-xl bg-slate-200" />
              </div>
            </div>
          ))}
        </div>
      </div>
    </main>
  );
}
