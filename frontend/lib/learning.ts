
import { authFetch } from "@/lib/auth";

/*
|--------------------------------------------------------------------------
| Learning Domain Types
|--------------------------------------------------------------------------
*/

export type LessonContentType =
  | "text"
  | "video"
  | "image"
  | "file"
  | "quiz"
  | "mixed";

export type LessonProgressStatus =
  | "not_started"
  | "in_progress"
  | "completed";

export type LessonAssetType =
  | "file"
  | "image"
  | "video"
  | "external_link";

export type QuestionType =
  | "single_choice"
  | "true_false";

/*
|--------------------------------------------------------------------------
| Shared API Types
|--------------------------------------------------------------------------
*/

export interface ApiResource<T> {
  data: T;
}

export interface LearningRouteParams {
  centerSlug: string;
  courseSlug: string;
  lessonId: number | string;
}

export interface LearningCourseSummary {
  id: number;
  title: string;
  slug: string;
  short_description: string | null;
  duration_hours: number | string | null;
  cover_image_url: string | null;
}

export interface LearningLessonCourse {
  id: number;
  title: string;
  slug: string;
}

export interface LearningModuleSummary {
  id: number;
  title: string;
  description: string | null;
  sort_order: number;
}

/*
|--------------------------------------------------------------------------
| Progress Types
|--------------------------------------------------------------------------
*/

export interface LessonProgress {
  status: LessonProgressStatus;
  last_position_seconds: number;
  started_at: string | null;
  completed_at: string | null;
  last_accessed_at: string | null;
}

export interface LearningProgressSummary {
  total_lessons: number;
  completed_lessons: number;
  in_progress_lessons: number;
  not_started_lessons: number;
  completion_percentage: number;
}

/*
|--------------------------------------------------------------------------
| Learning Overview
|--------------------------------------------------------------------------
*/

export interface LearningOverviewLesson {
  id: number;
  title: string;
  description: string | null;
  content_type: LessonContentType;
  duration_minutes: number | null;
  sort_order: number;
  progress: LessonProgress;
}

export interface LearningOverviewModule {
  id: number;
  title: string;
  description: string | null;
  sort_order: number;
  lessons: LearningOverviewLesson[];
}

export interface LearningOverview {
  course: LearningCourseSummary;
  progress_summary: LearningProgressSummary;
  modules: LearningOverviewModule[];
}

/*
|--------------------------------------------------------------------------
| Lesson Content
|--------------------------------------------------------------------------
*/

export interface LearningLesson {
  id: number;
  title: string;
  description: string | null;
  content_type: LessonContentType;
  body: string | null;
  content_locale: string | null;
  duration_minutes: number | null;
  sort_order: number;
}

export interface LearningLessonModule {
  id: number;
  title: string;
  description: string | null;
}

export interface LearningNavigationLesson {
  id: number;
  title: string;
}

export interface LearningLessonNavigation {
  previous_lesson: LearningNavigationLesson | null;
  next_lesson: LearningNavigationLesson | null;
}

/*
|--------------------------------------------------------------------------
| Lesson Assets
|--------------------------------------------------------------------------
*/

export interface LearningLessonAsset {
  id: number;
  title: string | null;
  asset_type: LessonAssetType;
  original_filename: string | null;
  mime_type: string | null;
  size_bytes: number | null;
  duration_seconds: number | null;
  alt_text: string | null;
  caption: string | null;
  is_downloadable: boolean;
  sort_order: number;
}

/*
|--------------------------------------------------------------------------
| Exercise Types
|--------------------------------------------------------------------------
*/

export interface LearningExerciseOption {
  id: number;
  option_text: string;
  sort_order: number;
}

export interface LearningQuestion {
  id: number;
  question_type: QuestionType;
  prompt: string;
  points: number | string;
  sort_order: number;
  options: LearningExerciseOption[];
}

export interface LearningExercise {
  id: number;
  title: string;
  instructions: string | null;
  max_attempts: number | null;
  passing_score: number | string;
  sort_order: number;
  questions: LearningQuestion[];
}

/*
|--------------------------------------------------------------------------
| Complete Lesson Response
|--------------------------------------------------------------------------
*/

export interface LearningLessonData {
  course: LearningLessonCourse;
  module: LearningLessonModule;
  lesson: LearningLesson;
  progress: LessonProgress;
  assets: LearningLessonAsset[];
  exercises: LearningExercise[];
  navigation: LearningLessonNavigation;
}

/*
|--------------------------------------------------------------------------
| Progress Update
|--------------------------------------------------------------------------
*/

export interface UpdateLessonProgressPayload {
  last_position_seconds?: number;
  completed?: boolean;
}

export interface LessonProgressResponse {
  lesson_id: number;
  status: Exclude<LessonProgressStatus, "not_started">;
  last_position_seconds: number;
  started_at: string | null;
  completed_at: string | null;
  last_accessed_at: string | null;
}

/*
|--------------------------------------------------------------------------
| Media Access Types
|--------------------------------------------------------------------------
*/

export interface StoredAssetAccess {
  type: "stored_asset";
  asset_id: number;
  asset_type: "file" | "image";
  url: string;
  expires_at: string;
  mime_type: string | null;
  can_download: boolean;
  alt_text: string | null;
  caption: string | null;
}

export interface ExternalAssetAccess {
  type: "external_link";
  asset_id: number;
  url: string;
  expires_at: null;
  can_download: false;
  title: string | null;
}

export interface CloudflareVideoAccess {
  type?: "cloudflare_stream";
  provider: "cloudflare_stream";
  player_url: string;
  hls_url: string;
  dash_url: string;
  expires_at: string;
}

export type LessonAssetAccess =
  | StoredAssetAccess
  | ExternalAssetAccess
  | CloudflareVideoAccess;

/*
|--------------------------------------------------------------------------
| API Error Handling
|--------------------------------------------------------------------------
*/

export type LearningValidationErrors = Record<string, string[]>;

export class LearningApiError extends Error {
  readonly status: number;
  readonly errors?: LearningValidationErrors;

  constructor(
    message: string,
    status: number,
    errors?: LearningValidationErrors
  ) {
    super(message);

    this.name = "LearningApiError";
    this.status = status;
    this.errors = errors;
  }
}

/**
 * Safely read an API response as JSON.
 */
async function readResponseBody(
  response: Response
): Promise<unknown> {
  const text = await response.text();

  if (!text) {
    return null;
  }

  try {
    return JSON.parse(text) as unknown;
  } catch {
    return null;
  }
}

function isRecord(
  value: unknown
): value is Record<string, unknown> {
  return (
    typeof value === "object" &&
    value !== null &&
    !Array.isArray(value)
  );
}

function getApiErrorMessage(
  body: unknown,
  fallback: string
): string {
  if (!isRecord(body)) {
    return fallback;
  }

  if (
    typeof body.message === "string" &&
    body.message.trim()
  ) {
    return body.message;
  }

  return fallback;
}

function getValidationErrors(
  body: unknown
): LearningValidationErrors | undefined {
  if (!isRecord(body) || !isRecord(body.errors)) {
    return undefined;
  }

  const errors: LearningValidationErrors = {};

  for (const [key, value] of Object.entries(body.errors)) {
    if (
      Array.isArray(value) &&
      value.every((item) => typeof item === "string")
    ) {
      errors[key] = value;
    }
  }

  return Object.keys(errors).length > 0
    ? errors
    : undefined;
}

/*
|--------------------------------------------------------------------------
| Route Validation
|--------------------------------------------------------------------------
*/

/**
 * Encode route segments safely.
 * Path traversal and empty values are rejected.
 */
function encodeRouteSegment(
  value: string | number,
  name: string
): string {
  const normalized = String(value).trim();

  if (
    !normalized ||
    normalized.length > 180 ||
    normalized.includes("/") ||
    normalized.includes("\\") ||
    normalized === "." ||
    normalized === ".." ||
    normalized.includes("\0")
  ) {
    throw new Error(`Invalid ${name}.`);
  }

  return encodeURIComponent(normalized);
}

function buildLearningBasePath(
  centerSlug: string,
  courseSlug: string
): string {
  const center = encodeRouteSegment(
    centerSlug,
    "center slug"
  );

  const course = encodeRouteSegment(
    courseSlug,
    "course slug"
  );

  return `/api/centers/${center}/courses/${course}/learn`;
}

function buildLessonPath(
  params: LearningRouteParams
): string {
  const basePath = buildLearningBasePath(
    params.centerSlug,
    params.courseSlug
  );

  const lessonId = String(params.lessonId);

  if (!/^\d+$/.test(lessonId)) {
    throw new Error("Invalid lesson ID.");
  }

  return `${basePath}/lessons/${lessonId}`;
}

/*
|--------------------------------------------------------------------------
| Shared Authenticated Request
|--------------------------------------------------------------------------
*/

/**
 * Perform an authenticated learning API request.
 *
 * All learning data is fetched without public caching.
 * HttpOnly cookies and CSRF are handled by authFetch.
 */
async function requestLearningData<T>(
  endpoint: string,
  init: RequestInit = {}
): Promise<T> {
  if (typeof window === "undefined") {
    throw new Error(
      "Learning API requests must run in the browser."
    );
  }

  let response: Response;

  try {
    response = await authFetch(endpoint, {
      ...init,
      cache: "no-store",
    });
  } catch {
    throw new LearningApiError(
      "Unable to connect to the learning service.",
      0
    );
  }

  const body = await readResponseBody(response);

  if (!response.ok) {
    throw new LearningApiError(
      getApiErrorMessage(
        body,
        "Learning request failed."
      ),
      response.status,
      getValidationErrors(body)
    );
  }

  if (!isRecord(body) || !("data" in body)) {
    throw new LearningApiError(
      "Invalid learning API response.",
      response.status
    );
  }

  return body.data as T;
}

/*
|--------------------------------------------------------------------------
| Learning Overview API
|--------------------------------------------------------------------------
*/

/**
 * Retrieve the complete published course curriculum
 * and the current student's progress.
 */
export async function getLearningOverview(
  centerSlug: string,
  courseSlug: string,
  signal?: AbortSignal
): Promise<LearningOverview> {
  const endpoint = buildLearningBasePath(
    centerSlug,
    courseSlug
  );

  return requestLearningData<LearningOverview>(
    endpoint,
    {
      method: "GET",
      signal,
    }
  );
}

/*
|--------------------------------------------------------------------------
| Lesson Details API
|--------------------------------------------------------------------------
*/

/**
 * Retrieve one published lesson and its resources.
 */
export async function getLearningLesson(
  params: LearningRouteParams,
  signal?: AbortSignal
): Promise<LearningLessonData> {
  const endpoint = buildLessonPath(params);

  return requestLearningData<LearningLessonData>(
    endpoint,
    {
      method: "GET",
      signal,
    }
  );
}

/*
|--------------------------------------------------------------------------
| Lesson Progress API
|--------------------------------------------------------------------------
*/

/**
 * Save video position or explicitly complete a lesson.
 */
export async function updateLessonProgress(
  params: LearningRouteParams,
  payload: UpdateLessonProgressPayload
): Promise<LessonProgressResponse> {
  if (
    payload.last_position_seconds !== undefined &&
    (
      !Number.isInteger(payload.last_position_seconds) ||
      payload.last_position_seconds < 0
    )
  ) {
    throw new Error("Invalid lesson playback position.");
  }

  if (
    payload.completed !== undefined &&
    typeof payload.completed !== "boolean"
  ) {
    throw new Error("Invalid lesson completion value.");
  }

  if (
    payload.last_position_seconds === undefined &&
    payload.completed === undefined
  ) {
    throw new Error("No lesson progress data provided.");
  }

  const endpoint = `${buildLessonPath(params)}/progress`;

  return requestLearningData<LessonProgressResponse>(
    endpoint,
    {
      method: "PATCH",
      body: JSON.stringify(payload),
    }
  );
}

/*
|--------------------------------------------------------------------------
| Lesson Asset Access API
|--------------------------------------------------------------------------
*/

/**
 * Request short-lived access to a protected lesson asset.
 */
export async function getLessonAssetAccess(
  params: LearningRouteParams,
  assetId: number
): Promise<LessonAssetAccess> {
  if (!Number.isSafeInteger(assetId) || assetId <= 0) {
    throw new Error("Invalid asset ID.");
  }

  const endpoint =
    `${buildLessonPath(params)}/assets/${assetId}/access`;

  return requestLearningData<LessonAssetAccess>(
    endpoint,
    {
      method: "GET",
    }
  );
}

/**
 * Download an authorized asset through the authenticated API.
 *
 * The filename is extracted from Content-Disposition when available.
 */
export async function downloadLessonAsset(
  params: LearningRouteParams,
  assetId: number
): Promise<{
  blob: Blob;
  filename: string;
}> {
  if (!Number.isSafeInteger(assetId) || assetId <= 0) {
    throw new Error("Invalid asset ID.");
  }

  const endpoint =
    `${buildLessonPath(params)}/assets/${assetId}/download`;

  let response: Response;

  try {
    response = await authFetch(endpoint, {
      method: "GET",
      cache: "no-store",
    });
  } catch {
    throw new LearningApiError(
      "Unable to download the learning resource.",
      0
    );
  }

  if (!response.ok) {
    const body = await readResponseBody(response);

    throw new LearningApiError(
      getApiErrorMessage(
        body,
        "Unable to download the learning resource."
      ),
      response.status,
      getValidationErrors(body)
    );
  }

  const disposition = response.headers.get(
    "Content-Disposition"
  );

  const filenameMatch = disposition?.match(
    /filename\*?=(?:UTF-8'')?["']?([^;"']+)["']?/i
  );

  const filename = filenameMatch?.[1]
    ? decodeURIComponent(filenameMatch[1].trim())
    : `learning-resource-${assetId}`;

  return {
    blob: await response.blob(),
    filename,
  };
}
