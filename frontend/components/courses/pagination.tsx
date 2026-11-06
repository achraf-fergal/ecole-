
import Link from "next/link";

interface Props {
  currentPage: number;
  lastPage: number;
  total: number;
  locale: "en" | "fr";
  hrefForPage: (page: number) => string;
}

function getVisiblePages(
  current: number,
  last: number
): Array<number | "ellipsis"> {
  if (last <= 5) {
    return Array.from({ length: last }, (_, index) => index + 1);
  }

  const pages = new Set<number>([
    1,
    last,
    current - 1,
    current,
    current + 1,
  ]);

  const validPages = [...pages]
    .filter((page) => page >= 1 && page <= last)
    .sort((a, b) => a - b);

  const result: Array<number | "ellipsis"> = [];

  validPages.forEach((page, index) => {
    if (index > 0) {
      const previous = validPages[index - 1];

      if (page - previous > 1) {
        result.push("ellipsis");
      }
    }

    result.push(page);
  });

  return result;
}

export default function Pagination({
  currentPage,
  lastPage,
  total,
  locale,
  hrefForPage,
}: Props) {
  if (total === 0 || lastPage <= 1) {
    return null;
  }

  const pages = getVisiblePages(currentPage, lastPage);

  const labels =
    locale === "en"
      ? {
          previous: "Previous page",
          next: "Next page",
          page: "Page",
        }
      : {
          previous: "Page précédente",
          next: "Page suivante",
          page: "Page",
        };

  return (
    <nav
      aria-label="Course pagination"
      className="mt-12 flex flex-wrap items-center justify-center gap-2"
    >
      {/* Previous */}
      {currentPage > 1 ? (
        <Link
          href={hrefForPage(currentPage - 1)}
          rel="prev"
          aria-label={labels.previous}
          className="flex h-11 min-w-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold text-[#173655] transition hover:border-[#0e8988] hover:bg-[#e9f7f5]"
        >
          ←
        </Link>
      ) : (
        <span
          aria-disabled="true"
          className="flex h-11 min-w-11 items-center justify-center rounded-xl border border-slate-100 bg-slate-50 px-3 text-slate-300"
        >
          ←
        </span>
      )}

      {/* Page numbers */}
      {pages.map((page, index) => {
        if (page === "ellipsis") {
          return (
            <span
              key={`ellipsis-${index}`}
              className="px-2 text-slate-400"
              aria-hidden="true"
            >
              ...
            </span>
          );
        }

        const active = page === currentPage;

        return (
          <Link
            key={page}
            href={hrefForPage(page)}
            aria-current={active ? "page" : undefined}
            aria-label={`${labels.page} ${page}`}
            className={`flex h-11 min-w-11 items-center justify-center rounded-xl border px-3 text-sm font-bold transition ${
              active
                ? "border-[#0e8988] bg-[#0e766f] text-white"
                : "border-slate-200 bg-white text-[#173655] hover:border-[#0e8988] hover:bg-[#e9f7f5]"
            }`}
          >
            {page}
          </Link>
        );
      })}

      {/* Next */}
      {currentPage < lastPage ? (
        <Link
          href={hrefForPage(currentPage + 1)}
          rel="next"
          aria-label={labels.next}
          className="flex h-11 min-w-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold text-[#173655] transition hover:border-[#0e8988] hover:bg-[#e9f7f5]"
        >
          →
        </Link>
      ) : (
        <span
          aria-disabled="true"
          className="flex h-11 min-w-11 items-center justify-center rounded-xl border border-slate-100 bg-slate-50 px-3 text-slate-300"
        >
          →
        </span>
      )}
    </nav>
  );
}
