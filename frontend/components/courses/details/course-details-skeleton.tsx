
function SkeletonBlock({
  className = "",
}: {
  className?: string;
}) {
  return (
    <div
      aria-hidden="true"
      className={`animate-pulse motion-reduce:animate-none rounded-xl bg-slate-200 ${className}`}
    />
  );
}

export default function CourseDetailsSkeleton() {
  return (
    <main
      aria-busy="true"
      aria-label="Loading course details"
      className="min-h-screen bg-[#f8fafc]"
    >
      {/* Header Skeleton */}
      <header className="border-b border-slate-200 bg-[#092443]">
        <div className="mx-auto flex max-w-[1440px] items-center justify-between px-5 py-4 sm:px-8 lg:px-12">
          <div className="flex items-center gap-3">
            <SkeletonBlock className="h-11 w-11 bg-slate-600" />

            <div className="space-y-2">
              <SkeletonBlock className="h-4 w-36 bg-slate-600" />
              <SkeletonBlock className="h-2.5 w-24 bg-slate-700" />
            </div>
          </div>

          <SkeletonBlock className="h-10 w-28 bg-slate-600" />
        </div>
      </header>

      {/* Hero Skeleton */}
      <section className="bg-gradient-to-br from-white via-[#f1f7f9] to-[#eaf4f5]">
        <div className="mx-auto grid max-w-[1440px] items-center gap-10 px-5 py-12 sm:px-8 lg:grid-cols-2 lg:px-12 lg:py-16">
          <div className="space-y-6">
            <SkeletonBlock className="h-4 w-36" />
            <SkeletonBlock className="h-5 w-48" />

            <SkeletonBlock className="h-12 w-full max-w-xl" />
            <SkeletonBlock className="h-12 w-4/5 max-w-lg" />

            <div className="space-y-3">
              <SkeletonBlock className="h-4 w-full max-w-lg" />
              <SkeletonBlock className="h-4 w-11/12 max-w-md" />
            </div>

            <SkeletonBlock className="h-12 w-48 rounded-full" />

            <SkeletonBlock className="h-4 w-40" />
          </div>

          <SkeletonBlock className="aspect-[4/3] w-full rounded-3xl sm:aspect-[5/4]" />
        </div>
      </section>

      {/* Course Facts Skeleton */}
      <section className="mx-auto grid max-w-[1440px] grid-cols-2 gap-3 px-5 py-8 sm:px-8 md:grid-cols-4 lg:px-12">
        {Array.from({ length: 4 }).map((_, index) => (
          <div
            key={index}
            className="rounded-2xl border border-slate-200 bg-white p-5"
          >
            <SkeletonBlock className="h-9 w-9" />
            <SkeletonBlock className="mt-4 h-3 w-20" />
            <SkeletonBlock className="mt-2 h-5 w-28" />
          </div>
        ))}
      </section>

      {/* Main Content Skeleton */}
      <section className="mx-auto max-w-[1440px] px-5 py-10 sm:px-8 lg:px-12">
        <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_350px]">
          <div className="order-2 space-y-12 lg:order-1">
            <div className="space-y-4">
              <SkeletonBlock className="h-8 w-64" />
              <SkeletonBlock className="h-4 w-full" />
              <SkeletonBlock className="h-4 w-11/12" />
              <SkeletonBlock className="h-4 w-4/5" />
            </div>

            <div className="space-y-4">
              <SkeletonBlock className="h-8 w-64" />

              <div className="grid gap-4 sm:grid-cols-2">
                {Array.from({ length: 4 }).map((_, index) => (
                  <SkeletonBlock
                    key={index}
                    className="h-24 rounded-2xl"
                  />
                ))}
              </div>
            </div>

            <div className="space-y-4">
              <SkeletonBlock className="h-8 w-64" />

              {Array.from({ length: 3 }).map((_, index) => (
                <SkeletonBlock
                  key={index}
                  className="h-20 rounded-2xl"
                />
              ))}
            </div>
          </div>

          {/* Pricing Card Skeleton */}
          <aside className="order-1 lg:order-2">
            <div className="rounded-2xl border border-slate-200 bg-white p-6">
              <SkeletonBlock className="h-5 w-36" />
              <SkeletonBlock className="mt-5 h-10 w-48" />
              <SkeletonBlock className="mt-3 h-4 w-32" />

              <div className="my-6 space-y-4">
                {Array.from({ length: 4 }).map((_, index) => (
                  <SkeletonBlock
                    key={index}
                    className="h-4 w-full"
                  />
                ))}
              </div>

              <SkeletonBlock className="h-12 w-full rounded-xl" />
            </div>
          </aside>
        </div>
      </section>
    </main>
  );
}
