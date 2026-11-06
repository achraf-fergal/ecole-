
import "server-only";
import { cacheLife } from "next/cache";

export type Locale = "en" | "fr";

export type StudyFormat =
  | "online"
  | "in_person"
  | "hybrid";

export type SortOption =
  | "recommended"
  | "name_asc";

export interface CourseOption {
  value: string;
  label: string;
}

export interface Course {
  id: string;
  slug: string;
  title: string;
  description: string;
  language: CourseOption;
  level: CourseOption | null;
  studyFormat: StudyFormat | null;
  durationLabel: string | null;
  coverImageUrl: string | null;
  coverImageAlt: string;
}

export interface CourseQuery {
  search: string;
  language: string;
  level: string;
  format: string;
  sort: SortOption;
  page: number;
  locale: Locale;
}

export interface CourseFilters {
  languages: CourseOption[];
  levels: CourseOption[];
  formats: CourseOption[];
}

export interface CourseResult {
  courses: Course[];
  filters: CourseFilters;
  pagination: {
    currentPage: number;
    perPage: number;
    total: number;
    lastPage: number;
  };
  source: "api" | "demo" | "error" | "not-configured";
}

const PER_PAGE = 12;

const DEMO_FILTERS: CourseFilters = {
  languages: [
    { value: "en", label: "English" },
    { value: "fr", label: "French" },
    { value: "de", label: "German" },
    { value: "es", label: "Spanish" },
  ],

  levels: [
    { value: "beginner", label: "Beginner" },
    { value: "elementary", label: "Elementary" },
    { value: "intermediate", label: "Intermediate" },
    { value: "advanced", label: "Advanced" },
  ],

  formats: [
    { value: "online", label: "Online" },
    { value: "in_person", label: "In-person" },
    { value: "hybrid", label: "Hybrid" },
  ],
};

const DEMO_COURSES: Course[] = [
  {
    id: "demo-1",
    slug: "general-english",
    title: "General English",
    description:
      "Build confidence in everyday communication and improve your English skills.",
    language: DEMO_FILTERS.languages[0],
    level: DEMO_FILTERS.levels[0],
    studyFormat: "hybrid",
    durationLabel: null,
    coverImageUrl: "/images/learning-classroom.svg",
    coverImageAlt: "Students learning English together",
  },
  {
    id: "demo-2",
    slug: "business-english",
    title: "Business English",
    description:
      "Develop communication skills for professional and business situations.",
    language: DEMO_FILTERS.languages[0],
    level: DEMO_FILTERS.levels[2],
    studyFormat: "online",
    durationLabel: null,
    coverImageUrl: "/images/learning-together.svg",
    coverImageAlt: "Professionals collaborating",
  },
  {
    id: "demo-3",
    slug: "academic-english",
    title: "Academic English",
    description:
      "Improve your academic reading, writing and communication skills.",
    language: DEMO_FILTERS.languages[0],
    level: DEMO_FILTERS.levels[2],
    studyFormat: "in_person",
    durationLabel: null,
    coverImageUrl: "/images/learning-books.svg",
    coverImageAlt: "Academic study materials",
  },
  {
    id: "demo-4",
    slug: "french-communication",
    title: "French Communication",
    description:
      "Build confidence in speaking French in everyday situations.",
    language: DEMO_FILTERS.languages[1],
    level: DEMO_FILTERS.levels[0],
    studyFormat: "hybrid",
    durationLabel: null,
    coverImageUrl: "/images/learning-city.svg",
    coverImageAlt: "Paris city architecture",
  },
  {
    id: "demo-5",
    slug: "academic-french",
    title: "Academic French",
    description:
      "Develop French skills for academic and professional contexts.",
    language: DEMO_FILTERS.languages[1],
    level: DEMO_FILTERS.levels[2],
    studyFormat: "in_person",
    durationLabel: null,
    coverImageUrl: "/images/learning-books.svg",
    coverImageAlt: "University learning environment",
  },
  {
    id: "demo-6",
    slug: "everyday-french",
    title: "Everyday French",
    description:
      "Improve your ability to communicate in common daily situations.",
    language: DEMO_FILTERS.languages[1],
    level: DEMO_FILTERS.levels[1],
    studyFormat: "online",
    durationLabel: null,
    coverImageUrl: "/images/learning-books.svg",
    coverImageAlt: "Student reading a book",
  },
  {
    id: "demo-7",
    slug: "german-for-beginners",
    title: "German for Beginners",
    description:
      "Start learning German with practical vocabulary and communication.",
    language: DEMO_FILTERS.languages[2],
    level: DEMO_FILTERS.levels[0],
    studyFormat: "in_person",
    durationLabel: null,
    coverImageUrl: "/images/learning-city.svg",
    coverImageAlt: "European city architecture",
  },
  {
    id: "demo-8",
    slug: "german-elementary",
    title: "German Elementary",
    description:
      "Build on your German foundations and improve everyday fluency.",
    language: DEMO_FILTERS.languages[2],
    level: DEMO_FILTERS.levels[1],
    studyFormat: "hybrid",
    durationLabel: null,
    coverImageUrl: null,
    coverImageAlt: "",
  },
  {
    id: "demo-9",
    slug: "everyday-spanish",
    title: "Everyday Spanish",
    description:
      "Learn useful Spanish expressions for travel and everyday life.",
    language: DEMO_FILTERS.languages[3],
    level: DEMO_FILTERS.levels[0],
    studyFormat: "online",
    durationLabel: null,
    coverImageUrl: null,
    coverImageAlt: "",
  },
  {
    id: "demo-10",
    slug: "spanish-intermediate",
    title: "Spanish Intermediate",
    description:
      "Improve your conversational Spanish and language confidence.",
    language: DEMO_FILTERS.languages[3],
    level: DEMO_FILTERS.levels[2],
    studyFormat: "in_person",
    durationLabel: null,
    coverImageUrl: null,
    coverImageAlt: "",
  },
  {
    id: "demo-11",
    slug: "english-speaking-skills",
    title: "English Speaking Skills",
    description:
      "Practise speaking and express your ideas more clearly.",
    language: DEMO_FILTERS.languages[0],
    level: DEMO_FILTERS.levels[1],
    studyFormat: "hybrid",
    durationLabel: null,
    coverImageUrl: null,
    coverImageAlt: "",
  },
  {
    id: "demo-12",
    slug: "french-intermediate",
    title: "French Intermediate",
    description:
      "Strengthen your vocabulary and communicate with greater ease.",
    language: DEMO_FILTERS.languages[1],
    level: DEMO_FILTERS.levels[2],
    studyFormat: "online",
    durationLabel: null,
    coverImageUrl: null,
    coverImageAlt: "",
  },
  {
    id: "demo-13",
    slug: "english-advanced",
    title: "Advanced English",
    description:
      "Refine your English for advanced academic and professional use.",
    language: DEMO_FILTERS.languages[0],
    level: DEMO_FILTERS.levels[3],
    studyFormat: "online",
    durationLabel: null,
    coverImageUrl: null,
    coverImageAlt: "",
  },
  {
    id: "demo-14",
    slug: "german-intermediate",
    title: "German Intermediate",
    description:
      "Continue developing your German communication skills.",
    language: DEMO_FILTERS.languages[2],
    level: DEMO_FILTERS.levels[2],
    studyFormat: "hybrid",
    durationLabel: null,
    coverImageUrl: null,
    coverImageAlt: "",
  },
  {
    id: "demo-15",
    slug: "spanish-advanced",
    title: "Advanced Spanish",
    description:
      "Improve advanced speaking, reading and writing skills.",
    language: DEMO_FILTERS.languages[3],
    level: DEMO_FILTERS.levels[3],
    studyFormat: "in_person",
    durationLabel: null,
    coverImageUrl: null,
    coverImageAlt: "",
  },
];

function isRecord(
  value: unknown
): value is Record<string, unknown> {
  return (
    typeof value === "object" &&
    value !== null &&
    !Array.isArray(value)
  );
}

function getString(
  value: unknown,
  fallback = ""
): string {
  return typeof value === "string" ? value.trim() : fallback;
}

function getNullableString(value: unknown): string | null {
  return typeof value === "string" && value.trim()
    ? value.trim()
    : null;
}

function getNumber(
  value: unknown,
  fallback: number
): number {
  const number = Number(value);

  return Number.isFinite(number) && number >= 0
    ? number
    : fallback;
}

function normalizeOption(
  value: unknown
): CourseOption | null {
  if (!isRecord(value)) return null;

  const optionValue = getString(value.code || value.value);
  const label = getString(value.name || value.label);

  if (!optionValue || !label) return null;

  return {
    value: optionValue,
    label,
  };
}

function normalizeOptions(value: unknown): CourseOption[] {
  if (!Array.isArray(value)) return [];

  return value
    .map(normalizeOption)
    .filter((item): item is CourseOption => item !== null);
}

function isAllowedImageUrl(value: unknown): string | null {
  const raw = getNullableString(value);

  if (!raw) return null;

  try {
    const imageUrl = new URL(raw);

    // API images must use the configured media origin.
    const mediaBase = process.env.MEDIA_BASE_URL;

    if (!mediaBase) return null;

    const allowedBase = new URL(mediaBase);

    const basePath = allowedBase.pathname.replace(/\/+$/, "");

    const validOrigin = imageUrl.origin === allowedBase.origin;

    const validPath =
      !basePath ||
      imageUrl.pathname.startsWith(`${basePath}/`) ||
      imageUrl.pathname === basePath;

    if (!validOrigin || !validPath) return null;

    if (
      imageUrl.protocol !== "https:" &&
      !(process.env.NODE_ENV === "development" &&
        imageUrl.protocol === "http:")
    ) {
      return null;
    }

    return imageUrl.toString();
  } catch {
    return null;
  }
}

function normalizeCourse(value: unknown): Course | null {
  if (!isRecord(value)) return null;

  const id = value.id;
  const slug = getString(value.slug);
  const title = getString(value.title);
  const language = normalizeOption(value.language);

  if (
    (typeof id !== "string" && typeof id !== "number") ||
    !slug ||
    !title ||
    !language
  ) {
    return null;
  }

  const level = normalizeOption(value.level);

  const format = getString(value.study_format);

  const studyFormat: StudyFormat | null =
    format === "online" ||
    format === "in_person" ||
    format === "hybrid"
      ? format
      : null;

  return {
    id: String(id),
    slug,
    title,
    description: getString(value.short_description),
    language,
    level,
    studyFormat,
    durationLabel: getNullableString(value.duration_label),
    coverImageUrl: isAllowedImageUrl(value.cover_image_url),
    coverImageAlt: getString(value.cover_image_alt, title),
  };
}

function getDemoResult(query: CourseQuery): CourseResult {
  const normalizedSearch = query.search.toLowerCase();

  let filtered = DEMO_COURSES.filter((course) => {
    const matchesSearch =
      !normalizedSearch ||
      course.title.toLowerCase().includes(normalizedSearch) ||
      course.description.toLowerCase().includes(normalizedSearch) ||
      course.language.label.toLowerCase().includes(normalizedSearch);

    const matchesLanguage =
      !query.language ||
      course.language.value === query.language;

    const matchesLevel =
      !query.level ||
      course.level?.value === query.level;

    const matchesFormat =
      !query.format ||
      course.studyFormat === query.format;

    return (
      matchesSearch &&
      matchesLanguage &&
      matchesLevel &&
      matchesFormat
    );
  });

  if (query.sort === "name_asc") {
    filtered = [...filtered].sort((a, b) =>
      a.title.localeCompare(b.title)
    );
  }

  const total = filtered.length;
  const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));
  const currentPage = Math.min(query.page, lastPage);

  const start = (currentPage - 1) * PER_PAGE;

  return {
    courses: filtered.slice(start, start + PER_PAGE),
    filters: DEMO_FILTERS,
    pagination: {
      currentPage,
      perPage: PER_PAGE,
      total,
      lastPage,
    },
    source: "demo",
  };
}

function parseApiResponse(payload: unknown): CourseResult {
  if (!isRecord(payload)) {
    throw new Error("Invalid API response.");
  }

  if (!Array.isArray(payload.data) || !isRecord(payload.meta)) {
    throw new Error("Missing paginated course data.");
  }

  const meta = payload.meta;

  const courses = payload.data
    .map(normalizeCourse)
    .filter((course): course is Course => course !== null);

  const currentPage = getNumber(meta.current_page, 1);
  const perPage = getNumber(meta.per_page, PER_PAGE);
  const total = getNumber(meta.total, courses.length);
  const lastPage = Math.max(
    1,
    getNumber(meta.last_page, Math.ceil(total / PER_PAGE))
  );

  const filters = isRecord(payload.filters)
    ? payload.filters
    : {};

  return {
    courses,
    filters: {
      languages: normalizeOptions(filters.languages),
      levels: normalizeOptions(filters.levels),
      formats: normalizeOptions(filters.formats),
    },
    pagination: {
      currentPage,
      perPage,
      total,
      lastPage,
    },
    source: "api",
  };
}

export async function getCourses(
  query: CourseQuery
): Promise<CourseResult> {
  "use cache";
  cacheLife({ revalidate: 60 });

  const apiBase = process.env.LARAVEL_API_URL;
  const centerSlug = process.env.CENTER_SLUG;

  if (!apiBase || !centerSlug) {
    if (process.env.NODE_ENV === "development") {
      return getDemoResult(query);
    }

    return {
      courses: [],
      filters: {
        languages: [],
        levels: [],
        formats: [],
      },
      pagination: {
        currentPage: 1,
        perPage: PER_PAGE,
        total: 0,
        lastPage: 1,
      },
      source: "not-configured",
    };
  }

  try {
    const baseUrl = new URL(apiBase);

    if (
      baseUrl.protocol !== "https:" &&
      !(process.env.NODE_ENV === "development" &&
        baseUrl.protocol === "http:")
    ) {
      throw new Error("Invalid API protocol.");
    }

    const endpoint = new URL(
      `/api/public/centers/${encodeURIComponent(centerSlug)}/courses`,
      baseUrl
    );

    const params = new URLSearchParams();

    params.set("locale", query.locale);
    params.set("per_page", String(PER_PAGE));
    params.set("page", String(query.page));
    params.set("sort", query.sort);

    if (query.search) params.set("search", query.search);
    if (query.language) params.set("language", query.language);
    if (query.level) params.set("level", query.level);
    if (query.format) params.set("format", query.format);

    endpoint.search = params.toString();

    const response = await fetch(endpoint.toString(), {
      method: "GET",
      headers: {
        Accept: "application/json",
      },
    });

    if (!response.ok) {
      throw new Error(`Laravel API returned ${response.status}`);
    }

    const payload: unknown = await response.json();

    return parseApiResponse(payload);
  } catch (error) {
    console.error("Courses API error:", error);

    return {
      courses: [],
      filters: {
        languages: [],
        levels: [],
        formats: [],
      },
      pagination: {
        currentPage: query.page,
        perPage: PER_PAGE,
        total: 0,
        lastPage: 1,
      },
      source: "error",
    };
  }
}
