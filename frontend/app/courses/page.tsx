
import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";

import CourseCard from "@/components/courses/course-card";
import CourseFilters from "@/components/courses/course-filters";
import Pagination from "@/components/courses/pagination";

import {
  getCourses,
  type CourseQuery,
  type Locale,
} from "@/lib/courses";

type SearchParams = Promise<{
  search?: string | string[];
  language?: string | string[];
  level?: string | string[];
  format?: string | string[];
  sort?: string | string[];
  page?: string | string[];
  lang?: string | string[];
}>;

interface PageProps {
  searchParams: SearchParams;
}

const translations = {
  en: {
    breadcrumb: "Home / Courses",
    eyebrow: "EXPLORE OUR PROGRAMMES",
    heroTitle: "Find a course that fits your goals.",
    heroDescription:
      "Explore our language programmes and find the right path for your personal or professional journey.",

    resultsTitle: "Available Courses",
    resultsDescription: "Explore published programmes",
    result: "courses",
    showing: "Showing",
    of: "of",

    search: "Search",
    searchPlaceholder: "Search courses or topics...",
    language: "Language",
    allLanguages: "All languages",
    level: "Level",
    allLevels: "All levels",
    format: "Study format",
    allFormats: "All formats",
    filters: "Filters",
    hideFilters: "Hide filters",
    clearAll: "Clear all",
    apply: "Apply filters",

    sort: "Sort by",
    recommended: "Recommended",
    nameAscending: "Name: A to Z",
    applySort: "Apply",

    noCourses: "No courses found",
    noCoursesDescription:
      "We couldn't find programmes matching your current search and filters.",
    resetFilters: "Clear filters",

    loadingError: "Unable to load courses",
    loadingErrorDescription:
      "The course catalogue is temporarily unavailable. Please try again later or contact the centre.",
    contact: "Contact the centre",

    demoNotice:
      "Preview mode: these sample courses are for development only and are not real centre offerings.",

    helpTitle: "Not sure which course is right for you?",
    helpDescription:
      "Our team can help you understand the available programmes and choose your next step.",
    helpButton: "Contact the centre",

    preRegister: "Pre-registration",

    footerDescription:
      "Discover language programmes and take your next learning step.",
    quickLinks: "Quick links",
    legal: "Legal",
    privacy: "Privacy policy",
    terms: "Terms and conditions",
    legalUnavailable: "Legal pages are being prepared.",

    pagination: "Course results",
  },

  fr: {
    breadcrumb: "Accueil / Cours",
    eyebrow: "DÉCOUVREZ NOS PROGRAMMES",
    heroTitle: "Trouvez le cours adapté à vos objectifs.",
    heroDescription:
      "Découvrez nos programmes de langues et trouvez le parcours adapté à vos objectifs personnels ou professionnels.",

    resultsTitle: "Cours disponibles",
    resultsDescription: "Découvrez les programmes publiés",
    result: "cours",
    showing: "Résultats",
    of: "sur",

    search: "Rechercher",
    searchPlaceholder: "Rechercher un cours ou un sujet...",
    language: "Langue",
    allLanguages: "Toutes les langues",
    level: "Niveau",
    allLevels: "Tous les niveaux",
    format: "Format d'étude",
    allFormats: "Tous les formats",
    filters: "Filtres",
    hideFilters: "Masquer les filtres",
    clearAll: "Tout effacer",
    apply: "Appliquer les filtres",

    sort: "Trier par",
    recommended: "Recommandé",
    nameAscending: "Nom : A à Z",
    applySort: "Appliquer",

    noCourses: "Aucun cours trouvé",
    noCoursesDescription:
      "Aucun programme ne correspond à votre recherche et aux filtres sélectionnés.",
    resetFilters: "Effacer les filtres",

    loadingError: "Impossible de charger les cours",
    loadingErrorDescription:
      "Le catalogue est temporairement indisponible. Réessayez plus tard ou contactez le centre.",
    contact: "Contacter le centre",

    demoNotice:
      "Mode aperçu : ces cours sont des exemples réservés au développement et ne représentent pas de vraies offres.",

    helpTitle: "Vous ne savez pas quel cours choisir ?",
    helpDescription:
      "Notre équipe peut vous aider à découvrir les programmes et à choisir la prochaine étape.",
    helpButton: "Contacter le centre",

    preRegister: "Préinscription",

    footerDescription:
      "Découvrez nos programmes de langues et avancez dans votre parcours.",
    quickLinks: "Liens rapides",
    legal: "Informations légales",
    privacy: "Politique de confidentialité",
    terms: "Conditions d'utilisation",
    legalUnavailable: "Les pages légales sont en préparation.",

    pagination: "Résultats des cours",
  },
} as const;

function firstValue(
  value: string | string[] | undefined
): string {
  return Array.isArray(value)
    ? (value[0] ?? "")
    : value ?? "";
}

function cleanValue(
  value: string | string[] | undefined,
  maxLength = 80
): string {
  return firstValue(value).trim().slice(0, maxLength);
}

function parsePage(value: string | string[] | undefined): number {
  const number = Number.parseInt(firstValue(value), 10);

  if (!Number.isInteger(number) || number < 1) {
    return 1;
  }

  return Math.min(number, 10000);
}

function parseLocale(
  value: string | string[] | undefined
): Locale {
  return firstValue(value).toLowerCase() === "fr"
    ? "fr"
    : "en";
}

function parseSort(
  value: string | string[] | undefined
): CourseQuery["sort"] {
  return firstValue(value) === "name_asc"
    ? "name_asc"
    : "recommended";
}

function parseQuery(params: Awaited<SearchParams>): CourseQuery {
  const allowedFormats = ["online", "in_person", "hybrid"];

  const requestedFormat = cleanValue(params.format, 20);

  return {
    search: cleanValue(params.search, 80),
    language: cleanValue(params.language, 32),
    level: cleanValue(params.level, 32),

    format: allowedFormats.includes(requestedFormat)
      ? requestedFormat
      : "",

    sort: parseSort(params.sort),
    page: parsePage(params.page),
    locale: parseLocale(params.lang),
  };
}

function buildCoursesUrl(
  query: CourseQuery,
  changes: Partial<CourseQuery> = {}
): string {
  const next = {
    ...query,
    ...changes,
  };

  const params = new URLSearchParams();

  if (next.locale === "fr") {
    params.set("lang", "fr");
  }

  if (next.search) params.set("search", next.search);
  if (next.language) params.set("language", next.language);
  if (next.level) params.set("level", next.level);
  if (next.format) params.set("format", next.format);

  if (next.sort !== "recommended") {
    params.set("sort", next.sort);
  }

  if (next.page > 1) {
    params.set("page", String(next.page));
  }

  const queryString = params.toString();

  return queryString
    ? `/courses?${queryString}`
    : "/courses";
}

export async function generateMetadata({
  searchParams,
}: PageProps): Promise<Metadata> {
  const params = await searchParams;
  const query = parseQuery(params);
  const t = translations[query.locale];

  const hasFilters = Boolean(
    query.search ||
    query.language ||
    query.level ||
    query.format ||
    query.sort !== "recommended"
  );
  
  const canonical = hasFilters
    ? query.locale === "fr"
      ? "/courses?lang=fr"
      : "/courses"
    : buildCoursesUrl(query);

  return {
    title:
      query.locale === "en"
        ? "Language Courses | Explore Our Programmes"
        : "Cours de langues | Découvrez nos programmes",

    description:
      query.locale === "en"
        ? "Explore published English, French, German and other language courses. Filter by language, level and study format."
        : "Découvrez nos cours de langues et filtrez les programmes par langue, niveau et format d'étude.",

    alternates: {
      canonical,
      languages: {
        en: "/courses",
        fr: "/courses?lang=fr",
      },
    },

    robots: {
      index: !hasFilters,
      follow: true,
    },

    openGraph: {
      title:
        query.locale === "en"
          ? "Explore Language Courses"
          : "Découvrez nos cours de langues",
      description: t.heroDescription,
      type: "website",
    },
  };
}

function Header({
  query,
}: {
  query: CourseQuery;
}) {
  const nextLocale = query.locale === "en" ? "fr" : "en";

  return (
    <header className="sticky top-0 z-50 border-b border-white/10 bg-[#092443] text-white shadow-lg">
      <div className="mx-auto flex max-w-[1440px] items-center justify-between gap-4 px-5 py-3 sm:px-8 lg:px-12">

        <Link href="/" className="flex shrink-0 items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#f6a23b] text-[#092443]">
            <svg
              width="25"
              height="25"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              aria-hidden="true"
            >
              <path d="M3 5.5A2.5 2.5 0 0 1 5.5 3H21v16H5.5A2.5 2.5 0 0 0 3 21.5z" />
              <path d="M7 8h9M7 12h8" />
            </svg>
          </span>

          <span>
            <span className="block text-lg font-extrabold leading-tight">
              Language Center
            </span>
            <span className="block text-[10px] tracking-wider text-slate-300">
              Learn. Progress. Achieve.
            </span>
          </span>
        </Link>

        <nav
          aria-label="Main navigation"
          className="hidden items-center gap-7 lg:flex"
        >
          <Link href="/" className="text-sm font-semibold text-slate-200 hover:text-[#ffb451]">
            Home
          </Link>

          <Link href="/about" className="text-sm font-semibold text-slate-200 hover:text-[#ffb451]">
            About Us
          </Link>

          <Link
            href="/courses"
            aria-current="page"
            className="border-b-2 border-[#0e8988] pb-2 text-sm font-extrabold text-white"
          >
            Courses
          </Link>

          <Link href="/#contact" className="text-sm font-semibold text-slate-200 hover:text-[#ffb451]">
            Contact
          </Link>
        </nav>

        <div className="flex items-center gap-2">
          <Link
            href={buildCoursesUrl(query, { locale: nextLocale })}
            hrefLang={nextLocale}
            aria-label={
              nextLocale === "fr"
                ? "Switch to French"
                : "Switch to English"
            }
            className="rounded-full border border-white/25 px-3 py-2 text-xs font-bold transition hover:bg-white/10"
          >
            {query.locale.toUpperCase()} / {nextLocale.toUpperCase()}
          </Link>

          <Link
            href="/#contact"
            className="rounded-full bg-[#f6a23b] px-4 py-3 text-xs font-extrabold text-[#102b4c] transition hover:bg-[#ffb451] sm:px-5"
          >
            {query.locale === "en" ? "Pre-registration" : "Préinscription"}
          </Link>
        </div>
      </div>
    </header>
  );
}

function ActiveFilter({
  label,
  href,
}: {
  label: string;
  href: string;
}) {
  return (
    <Link
      href={href}
      className="inline-flex items-center gap-2 rounded-full border border-[#b6deda] bg-[#e9f7f5] px-3 py-2 text-xs font-bold text-[#087b83] transition hover:bg-[#d8f0ec]"
    >
      {label}
      <span aria-hidden="true">×</span>
    </Link>
  );
}

function Footer({
  query,
}: {
  query: CourseQuery;
}) {
  const t = translations[query.locale];

  return (
    <footer className="bg-[#071d36] text-white">
      <div className="mx-auto grid max-w-[1440px] gap-10 px-5 py-12 sm:px-8 md:grid-cols-3 lg:px-12">

        <div>
          <Link href="/" className="text-xl font-extrabold">
            Language Center
          </Link>

          <p className="mt-4 max-w-xs text-sm leading-7 text-slate-400">
            {t.footerDescription}
          </p>
        </div>

        <div>
          <h2 className="font-extrabold">{t.quickLinks}</h2>

          <div className="mt-4 flex flex-col gap-3 text-sm text-slate-400">
            <Link href="/" className="hover:text-white">Home</Link>
            <Link href="/about" className="hover:text-white">About Us</Link>
            <Link href="/courses" className="hover:text-white">Courses</Link>
            <Link href="/#contact" className="hover:text-white">Contact</Link>
          </div>
        </div>

        <div>
          <h2 className="font-extrabold">{t.legal}</h2>

          <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
            <span
              aria-disabled="true"
              title={t.legalUnavailable}
              className="cursor-not-allowed opacity-60"
            >
              {t.privacy}
            </span>
            <span
              aria-disabled="true"
              title={t.legalUnavailable}
              className="cursor-not-allowed opacity-60"
            >
              {t.terms}
            </span>
          </div>
        </div>
      </div>

      <div className="border-t border-white/10">
        <div className="mx-auto max-w-[1440px] px-5 py-5 text-xs text-slate-500 sm:px-8 lg:px-12">
          © {query.locale === "en" ? "Language Center. All rights reserved." : "Language Center. Tous droits réservés."}
        </div>
      </div>
    </footer>
  );
}

export default async function CoursesPage({
  searchParams,
}: PageProps) {
  const params = await searchParams;
  const query = parseQuery(params);
  const t = translations[query.locale];

  const result = await getCourses(query);

  // If filters change, the form intentionally returns to page 1.
  const clearAllHref = buildCoursesUrl(query, {
    search: "",
    language: "",
    level: "",
    format: "",
    sort: "recommended",
    page: 1,
  });

  const activeFilters: {
    label: string;
    href: string;
  }[] = [];

  if (query.search) {
    activeFilters.push({
      label: `${t.search}: ${query.search}`,
      href: buildCoursesUrl(query, {
        search: "",
        page: 1,
      }),
    });
  }

  if (query.language) {
    const selected = result.filters.languages.find(
      (item) => item.value === query.language
    );

    activeFilters.push({
      label: selected?.label ?? query.language,
      href: buildCoursesUrl(query, {
        language: "",
        page: 1,
      }),
    });
  }

  if (query.level) {
    const selected = result.filters.levels.find(
      (item) => item.value === query.level
    );

    activeFilters.push({
      label: selected?.label ?? query.level,
      href: buildCoursesUrl(query, {
        level: "",
        page: 1,
      }),
    });
  }

  if (query.format) {
    const selected = result.filters.formats.find(
      (item) => item.value === query.format
    );

    activeFilters.push({
      label: selected?.label ?? query.format,
      href: buildCoursesUrl(query, {
        format: "",
        page: 1,
      }),
    });
  }

  const start =
    result.pagination.total === 0
      ? 0
      : (result.pagination.currentPage - 1) *
          result.pagination.perPage +
        1;

  const end = Math.min(
    result.pagination.currentPage * result.pagination.perPage,
    result.pagination.total
  );

  const hasError =
    result.source === "error" ||
    result.source === "not-configured";

  return (
    <main className="min-h-screen bg-[#f8fafc] text-[#102b4c]">

      <Header query={query} />

      {/* COMPACT HERO */}
      <section className="bg-gradient-to-br from-white via-[#f1f7f9] to-[#eaf4f5]">
        <div className="mx-auto grid max-w-[1440px] items-center gap-7 px-5 py-9 sm:px-8 md:grid-cols-[1fr_0.8fr] lg:px-12 lg:py-12">

          <div>
            <nav
              aria-label="Breadcrumb"
              className="mb-5 text-xs font-medium text-slate-500"
            >
              <Link href="/" className="hover:text-[#0e8988]">
                {t.breadcrumb.split(" / ")[0]}
              </Link>

              <span className="mx-2">/</span>
              <span aria-current="page">
                {t.breadcrumb.split(" / ")[1]}
              </span>
            </nav>

            <span className="text-xs font-extrabold tracking-[0.18em] text-[#0e8988]">
              {t.eyebrow}
            </span>

            <h1 className="mt-3 max-w-2xl text-3xl font-black leading-tight tracking-tight sm:text-4xl lg:text-5xl">
              {t.heroTitle}
            </h1>

            <p className="mt-4 max-w-xl text-sm leading-7 text-slate-600 sm:text-base">
              {t.heroDescription}
            </p>
          </div>

          <div className="relative hidden h-[220px] overflow-hidden rounded-2xl shadow-md md:block lg:h-[245px]">
            <Image
              src="/images/learning-classroom.svg"
              alt="Students learning in a language classroom"
              fill
              priority
              sizes="(max-width: 1280px) 40vw, 500px"
              className="object-cover"
            />

            <div className="absolute inset-0 bg-gradient-to-t from-[#092443]/35 to-transparent" />
          </div>
        </div>
      </section>

      {/* MAIN CATALOG */}
      <section className="mx-auto max-w-[1440px] px-5 py-10 sm:px-8 lg:px-12 lg:py-14">

        <CourseFilters
          query={query}
          languages={result.filters.languages}
          levels={result.filters.levels}
          formats={result.filters.formats}
          clearAllHref={clearAllHref}
          labels={{
            searchPlaceholder: t.searchPlaceholder,
            language: t.language,
            allLanguages: t.allLanguages,
            level: t.level,
            allLevels: t.allLevels,
            format: t.format,
            allFormats: t.allFormats,
            filters: t.filters,
            hideFilters: t.hideFilters,
            clearAll: t.clearAll,
            search: t.search,
            apply: t.apply,
          }}
        />

        {/* ACTIVE FILTERS */}
        {activeFilters.length > 0 && (
          <div className="mt-4 flex flex-wrap items-center gap-2">
            {activeFilters.map((filter) => (
              <ActiveFilter
                key={`${filter.label}-${filter.href}`}
                label={filter.label}
                href={filter.href}
              />
            ))}

            <Link
              href={clearAllHref}
              className="px-2 text-xs font-bold text-slate-500 underline decoration-slate-300 underline-offset-4 hover:text-[#0e8988]"
            >
              {t.clearAll}
            </Link>
          </div>
        )}

        {/* DEMO WARNING */}
        {result.source === "demo" && (
          <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-900">
            {t.demoNotice}
          </div>
        )}

        {/* RESULTS HEADER */}
        <div className="mb-7 mt-9 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">

          <div>
            <h2 className="text-2xl font-black tracking-tight text-[#102b4c] sm:text-3xl">
              {t.resultsTitle}
            </h2>

            <p className="mt-2 text-sm text-slate-500">
              {t.resultsDescription}
            </p>

            {!hasError && (
              <p className="mt-2 text-xs font-semibold text-slate-500">
                {t.showing} {start}–{end} {t.of}{" "}
                {result.pagination.total} {t.result}
              </p>
            )}
          </div>

          {/* SORT */}
          {!hasError && (
            <form
              action="/courses"
              method="get"
              className="flex items-center gap-2"
            >
              <input
                type="hidden"
                name="lang"
                value={query.locale}
              />

              <input type="hidden" name="search" value={query.search} />
              <input type="hidden" name="language" value={query.language} />
              <input type="hidden" name="level" value={query.level} />
              <input type="hidden" name="format" value={query.format} />

              <label
                htmlFor="course-sort"
                className="whitespace-nowrap text-xs font-semibold text-slate-500"
              >
                {t.sort}
              </label>

              <select
                id="course-sort"
                name="sort"
                defaultValue={query.sort}
                className="min-h-11 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-[#173655] outline-none focus:border-[#0e8988]"
              >
                <option value="recommended">{t.recommended}</option>
                <option value="name_asc">{t.nameAscending}</option>
              </select>

              <button
                type="submit"
                className="min-h-11 rounded-xl bg-[#e8f5f3] px-3 text-xs font-extrabold text-[#087b83] hover:bg-[#d8efea]"
              >
                {t.applySort}
              </button>
            </form>
          )}
        </div>

        {/* API ERROR */}
        {hasError && (
          <div
            role="alert"
            className="rounded-2xl border border-red-200 bg-red-50 px-6 py-10 text-center"
          >
            <h3 className="text-xl font-extrabold text-red-900">
              {t.loadingError}
            </h3>

            <p className="mx-auto mt-3 max-w-xl text-sm leading-7 text-red-800">
              {t.loadingErrorDescription}
            </p>

            <Link
              href="/#contact"
              className="mt-6 inline-flex min-h-11 items-center justify-center rounded-xl bg-[#092b4d] px-6 py-3 text-sm font-bold text-white hover:bg-[#15476e]"
            >
              {t.contact}
            </Link>
          </div>
        )}

        {/* EMPTY STATE */}
        {!hasError && result.courses.length === 0 && (
          <div className="rounded-2xl border border-slate-200 bg-white px-6 py-16 text-center">
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[#e8f5f3] text-[#0e8988]">
              <svg
                width="30"
                height="30"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.7"
                aria-hidden="true"
              >
                <circle cx="11" cy="11" r="7" />
                <path d="m20 20-4-4" />
              </svg>
            </div>

            <h3 className="mt-5 text-xl font-extrabold">
              {t.noCourses}
            </h3>

            <p className="mx-auto mt-3 max-w-md text-sm leading-7 text-slate-500">
              {t.noCoursesDescription}
            </p>

            <Link
              href={clearAllHref}
              className="mt-6 inline-flex min-h-11 items-center justify-center rounded-xl bg-[#092b4d] px-6 py-3 text-sm font-bold text-white hover:bg-[#15476e]"
            >
              {t.resetFilters}
            </Link>
          </div>
        )}

        {/* COURSE GRID */}
        {!hasError && result.courses.length > 0 && (
          <>
            <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
              {result.courses.map((course, index) => (
                <CourseCard
                  key={course.id}
                  course={course}
                  locale={query.locale}
                  priority={
                    query.page === 1 && index === 0
                  }
                />
              ))}
            </div>

            <Pagination
              currentPage={result.pagination.currentPage}
              lastPage={result.pagination.lastPage}
              total={result.pagination.total}
              locale={query.locale}
              hrefForPage={(page) =>
                buildCoursesUrl(query, { page })
              }
            />
          </>
        )}
      </section>

      {/* CONTACT CTA */}
      <section className="mx-auto max-w-[1440px] px-5 pb-16 sm:px-8 lg:px-12">
        <div className="flex flex-col justify-between gap-6 rounded-2xl bg-[#e8f4f5] p-7 sm:p-9 md:flex-row md:items-center">

          <div className="max-w-2xl">
            <h2 className="text-xl font-black text-[#102b4c] sm:text-2xl">
              {t.helpTitle}
            </h2>

            <p className="mt-3 text-sm leading-7 text-slate-600">
              {t.helpDescription}
            </p>
          </div>

          <Link
            href="/#contact"
            className="inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-full bg-[#f6a23b] px-7 py-3 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451]"
          >
            {t.helpButton}
            <span aria-hidden="true">→</span>
          </Link>
        </div>
      </section>

      <Footer query={query} />
    </main>
  );
}
