
export default function LearningPageSkeleton() {
  return (
    <main
      aria-label="Loading learning content"
      aria-busy="true"
      className="min-h-screen animate-pulse bg-[#f8fafc]"
    >
      {/* Header */}
      <header className="flex min-h-[72px] items-center justify-between border-b border-slate-200 bg-white px-5 sm:px-8">
        <div className="flex items-center gap-3">
          <div className="h-10 w-10 rounded-xl bg-slate-100" />
          <div>
            <div className="h-2 w-24 rounded bg-slate-100" />
            <div className="mt-2 h-4 w-48 rounded bg-slate-200" />
          </div>
        </div>

        <div className="h-10 w-10 rounded-full bg-slate-100" />
      </header>

      <div className="mx-auto flex max-w-[1600px]">
        {/* Desktop Sidebar */}
        <aside className="hidden w-[310px] shrink-0 border-r border-slate-200 bg-white p-5 md:block">
          <div className="h-3 w-24 rounded bg-slate-100" />
          <div className="mt-3 h-5 w-48 rounded bg-slate-200" />
          <div className="mt-7 h-2 rounded-full bg-slate-100" />

          <div className="mt-8 space-y-7">
            {Array.from({ length: 3 }).map((_, index) => (
              <div key={index}>
                <div className="h-3 w-32 rounded bg-slate-200" />

                <div className="mt-4 space-y-4">
                  {Array.from({ length: 3 }).map((__, lessonIndex) => (
                    <div
                      key={lessonIndex}
                      className="flex items-center gap-3"
                    >
                      <div className="h-5 w-5 rounded-full bg-slate-100" />
                      <div className="h-3 flex-1 rounded bg-slate-100" />
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </aside>

        {/* Main Content */}
        <div className="min-w-0 flex-1 p-4 sm:p-8">
          <div className="mb-6 h-3 w-56 rounded bg-slate-100" />
          <div className="h-8 w-2/3 max-w-xl rounded bg-slate-200" />
          <div className="mt-4 h-3 w-48 rounded bg-slate-100" />

          <div className="mt-8 aspect-video rounded-2xl bg-slate-200" />

          <div className="mt-7 rounded-2xl border border-slate-200 bg-white p-6">
            <div className="h-4 w-36 rounded bg-slate-200" />

            <div className="mt-5 space-y-3">
              <div className="h-3 w-full rounded bg-slate-100" />
              <div className="h-3 w-11/12 rounded bg-slate-100" />
              <div className="h-3 w-4/5 rounded bg-slate-100" />
            </div>
          </div>

          <div className="mt-5 h-24 rounded-2xl bg-slate-200" />
        </div>
      </div>
    </main>
  );
}
