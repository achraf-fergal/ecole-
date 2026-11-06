
"use client";

import { useState } from "react";
import type {
  CourseOption,
  CourseQuery,
} from "@/lib/courses";

interface FilterLabels {
  searchPlaceholder: string;
  language: string;
  allLanguages: string;
  level: string;
  allLevels: string;
  format: string;
  allFormats: string;
  filters: string;
  clearAll: string;
  search: string;
  apply: string;
  hideFilters: string;
}

interface Props {
  query: CourseQuery;
  languages: CourseOption[];
  levels: CourseOption[];
  formats: CourseOption[];
  labels: FilterLabels;
  clearAllHref: string;
}

const selectClass =
  "min-h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-[#173655] outline-none transition hover:border-[#9bbbc3] focus:border-[#0e8988] focus:ring-2 focus:ring-[#0e8988]/15";

export default function CourseFilters({
  query,
  languages,
  levels,
  formats,
  labels,
  clearAllHref,
}: Props) {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <form
      action="/courses"
      method="get"
      className="grid grid-cols-2 gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_10px_35px_rgba(16,43,76,0.06)] md:grid-cols-[minmax(220px,1.5fr)_repeat(3,minmax(130px,1fr))_auto_auto] md:items-end"
    >
      <input
        type="hidden"
        name="lang"
        value={query.locale}
      />

      <input
        type="hidden"
        name="sort"
        value={query.sort}
      />

      {/* Search */}
      <div className="col-span-2 md:col-span-1">
        <label
          htmlFor="course-search"
          className="mb-2 block text-xs font-bold text-slate-600"
        >
          {labels.search}
        </label>

        <div className="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 transition focus-within:border-[#0e8988] focus-within:ring-2 focus-within:ring-[#0e8988]/15">
          <svg
            width="19"
            height="19"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            className="shrink-0 text-slate-400"
            aria-hidden="true"
          >
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-4-4" />
          </svg>

          <input
            id="course-search"
            type="search"
            name="search"
            defaultValue={query.search}
            maxLength={80}
            placeholder={labels.searchPlaceholder}
            className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-slate-400"
          />
        </div>
      </div>

      {/* Mobile filter toggle */}
      <button
        type="button"
        onClick={() => setMobileOpen((current) => !current)}
        className="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-bold text-[#173655] md:hidden"
        aria-expanded={mobileOpen}
        aria-controls="course-filter-fields"
      >
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
          aria-hidden="true"
        >
          <path d="M4 7h16M7 12h10m-7 5h4" />
        </svg>

        {mobileOpen ? labels.hideFilters : labels.filters}
      </button>

      {/* Filters */}
      <div
        id="course-filter-fields"
        className={`${
          mobileOpen ? "grid" : "hidden"
        } col-span-2 grid-cols-2 gap-3 md:contents`}
      >
        <div>
          <label
            htmlFor="course-language"
            className="mb-2 block text-xs font-bold text-slate-600"
          >
            {labels.language}
          </label>

          <select
            id="course-language"
            name="language"
            defaultValue={query.language}
            className={selectClass}
          >
            <option value="">{labels.allLanguages}</option>

            {languages.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label
            htmlFor="course-level"
            className="mb-2 block text-xs font-bold text-slate-600"
          >
            {labels.level}
          </label>

          <select
            id="course-level"
            name="level"
            defaultValue={query.level}
            className={selectClass}
          >
            <option value="">{labels.allLevels}</option>

            {levels.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <div className="col-span-2 md:col-span-1">
          <label
            htmlFor="course-format"
            className="mb-2 block text-xs font-bold text-slate-600"
          >
            {labels.format}
          </label>

          <select
            id="course-format"
            name="format"
            defaultValue={query.format}
            className={selectClass}
          >
            <option value="">{labels.allFormats}</option>

            {formats.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        {/* Mobile apply button */}
        <button
          type="submit"
          className="col-span-2 min-h-12 rounded-xl bg-[#092b4d] px-5 text-sm font-bold text-white hover:bg-[#15476e] md:hidden"
        >
          {labels.apply}
        </button>
      </div>

      {/* Clear filters */}
      <a
        href={clearAllHref}
        className="flex min-h-12 items-center justify-center whitespace-nowrap rounded-xl px-3 text-sm font-bold text-[#0e8988] transition hover:bg-[#e9f7f5]"
      >
        {labels.clearAll}
      </a>

      {/* Desktop search / apply */}
      <button
        type="submit"
        className="col-span-2 min-h-12 rounded-xl bg-[#092b4d] px-5 text-sm font-extrabold text-white transition hover:bg-[#15476e] md:col-span-1"
      >
        {labels.apply}
      </button>
    </form>
  );
}
