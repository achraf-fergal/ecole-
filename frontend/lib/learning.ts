
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

export type LearningAccessMode =
  | "student"
  | "admin_preview";

export type LearningContentStatus =
  | "draft"
  | "published"
  | "archived";

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
  status: LearningContentStatus;
  published_at: string | null;
}

export interface LearningLessonCourse {
  id: number;
  title: string;
  slug: string;
  status: LearningContentStatus;
  published_at: string | null;
}

export interface LearningModuleSummary {
  id: number;
  title: string;
  description: string | null;
  sort_order: number;
  status: LearningContentStatus;
  published_at: string | null;
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
  status: LearningContentStatus;
  published_at: string | null;
  progress: LessonProgress;
}

export interface LearningOverviewModule {
  id: number;
  title: string;
  description: string | null;
  sort_order: number;
  status: LearningContentStatus;
  published_at: string | null;
  lessons: LearningOverviewLesson[];
}

export interface LearningOverview {
  access_mode: LearningAccessMode;
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
  status: LearningContentStatus;
  published_at: string | null;
}

export interface LearningLessonModule {
  id: number;
  title: string;
  description: string | null;
  status: LearningContentStatus;
  published_at: string | null;
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
  status: "active" | "archived";
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
  is_correct?: boolean;
}

export interface LearningQuestion {
  id: number;
  question_type: QuestionType;
  prompt: string;
  explanation?: string | null;
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
  status: LearningContentStatus;
  published_at: string | null;
  questions: LearningQuestion[];
}

/*
|--------------------------------------------------------------------------
| Revision & Block Content Types
|--------------------------------------------------------------------------
*/

export interface LessonRevisionSummary {
  id: number;
  version_number: number;
  status: "draft" | "published" | "superseded";
  title: string;
  description: string | null;
  content_locale: string;
  duration_minutes: number | null;
  created_at: string | null;
  published_at: string | null;
}

export interface TipTapMark {
  type: string;
  attrs?: Record<string, unknown>;
}

export interface TipTapNode {
  type: string;
  attrs?: Record<string, unknown>;
  content?: TipTapNode[];
  marks?: TipTapMark[];
  text?: string;
}

export interface TipTapDocument {
  type: "doc";
  content?: TipTapNode[];
}

export type LessonBlockType = "text" | "media" | "exercise";

export interface LessonBlock {
  id: number;
  block_type: LessonBlockType;
  sort_order: number;
  content_json: TipTapDocument | null;
  settings?: Record<string, unknown> | null;
  asset: LearningLessonAsset | null;
  exercise: LearningExercise | null;
}

/*
|--------------------------------------------------------------------------
| Complete Lesson Response
|--------------------------------------------------------------------------
*/

export interface LearningLessonData {
  access_mode: LearningAccessMode;
  course: LearningLessonCourse;
  module: LearningLessonModule;
  lesson: LearningLesson;
  revision?: LessonRevisionSummary | null;
  blocks?: LessonBlock[];
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

/**
 * Perform an authenticated request against the learning API expecting paginated data.
 */
async function requestLearningPaginatedData<T>(
  endpoint: string,
  init: RequestInit = {}
): Promise<{ data: T[]; meta: PaginationMeta }> {
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

  if (!isRecord(body) || !("data" in body) || !("meta" in body)) {
    throw new LearningApiError(
      "Invalid learning API paginated response.",
      response.status
    );
  }

  return {
    data: body.data as T[],
    meta: body.meta as PaginationMeta,
  };
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

/*
|--------------------------------------------------------------------------
| Exercise Assessment API
|--------------------------------------------------------------------------
*/

export interface AttemptQuestionOption {
  id: number;
  option_text: string;
  sort_order: number;
  is_correct?: boolean;
}

export interface AttemptQuestion {
  id: number;
  question_type: QuestionType;
  prompt: string;
  points_possible: number;
  selected_option_id: number | null;
  options: AttemptQuestionOption[];
  is_correct?: boolean;
  points_awarded?: number;
  correct_option_id?: number;
  explanation?: string | null;
}

export interface ExerciseAttemptData {
  id: number;
  exercise_id: number;
  attempt_number: number;
  status: "in_progress" | "submitted" | "graded";
  passing_score: number;
  max_score: number;
  score: number | null;
  percentage: number | null;
  passed: boolean | null;
  started_at: string;
  submitted_at: string | null;
  graded_at: string | null;
  show_correct_answers: boolean;
  questions: AttemptQuestion[];
}

export interface ExerciseCurrentAttemptState {
  exercise: {
    id: number;
    title: string;
    instructions: string | null;
    passing_score: number;
    max_attempts: number | null;
    attempts_count: number;
    attempts_remaining: number | null;
    can_start_new_attempt: boolean;
    has_active_attempt: boolean;
  };
  active_attempt: ExerciseAttemptData | null;
  latest_completed_attempt: ExerciseAttemptData | null;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface PaginatedExerciseAttempts {
  data: ExerciseAttemptData[];
  meta: PaginationMeta;
}

export interface SaveAttemptAnswerPayload {
  question_id: number;
  selected_option_id: number | null;
}

/**
 * Retrieve the current attempt state and attempt counts for an exercise.
 */
export async function getExerciseCurrentAttempt(
  params: LearningRouteParams,
  exerciseId: number
): Promise<ExerciseCurrentAttemptState> {
  const endpoint = `${buildLessonPath(params)}/exercises/${exerciseId}/attempts/current`;

  return requestLearningData<ExerciseCurrentAttemptState>(endpoint, {
    method: "GET",
  });
}

/**
 * Start a new attempt or resume an active one.
 */
export async function startExerciseAttempt(
  params: LearningRouteParams,
  exerciseId: number
): Promise<ExerciseAttemptData> {
  const endpoint = `${buildLessonPath(params)}/exercises/${exerciseId}/attempts`;

  return requestLearningData<ExerciseAttemptData>(endpoint, {
    method: "POST",
  });
}

/**
 * Save draft answer selections for an in-progress attempt.
 */
export async function saveExerciseAttemptAnswers(
  params: LearningRouteParams,
  exerciseId: number,
  answers: SaveAttemptAnswerPayload[]
): Promise<ExerciseAttemptData> {
  const endpoint = `${buildLessonPath(params)}/exercises/${exerciseId}/attempts/current`;

  return requestLearningData<ExerciseAttemptData>(endpoint, {
    method: "PATCH",
    body: JSON.stringify({ answers }),
  });
}

/**
 * Submit an in-progress attempt for server-side grading.
 */
export async function submitExerciseAttempt(
  params: LearningRouteParams,
  exerciseId: number,
  answers?: SaveAttemptAnswerPayload[]
): Promise<ExerciseAttemptData> {
  const endpoint = `${buildLessonPath(params)}/exercises/${exerciseId}/attempts/current/submit`;

  return requestLearningData<ExerciseAttemptData>(endpoint, {
    method: "POST",
    body: JSON.stringify(answers ? { answers } : {}),
  });
}

/**
 * Retrieve completed attempt history with pagination metadata.
 */
export async function getExerciseAttemptHistory(
  params: LearningRouteParams,
  exerciseId: number,
  page: number = 1
): Promise<PaginatedExerciseAttempts> {
  const endpoint = `${buildLessonPath(params)}/exercises/${exerciseId}/attempts/history?page=${page}`;

  return requestLearningPaginatedData<ExerciseAttemptData>(endpoint, {
    method: "GET",
  });
}

