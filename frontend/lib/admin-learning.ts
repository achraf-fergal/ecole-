import { authFetch } from "@/lib/auth";
import type {
  ExerciseAttemptData,
  LessonBlock,
  TipTapDocument,
  LearningLessonAsset,
  SaveAttemptAnswerPayload,
} from "@/lib/learning";

export interface AdminExerciseOption {
  id: number;
  title: string;
  instructions: string | null;
  status: string;
}

export interface AdminLessonRevision {
  id: number;
  version_number: number;
  status: "draft" | "published" | "superseded";
  title: string;
  description: string | null;
  content_locale: string;
  duration_minutes: number | null;
  created_at: string | null;
  published_at: string | null;
  blocks: LessonBlock[];
}

export interface AdminLessonRevisionHistoryItem {
  id: number;
  version_number: number;
  status: "draft" | "published" | "superseded";
  title: string;
  created_at: string | null;
  published_at: string | null;
  author: { id: number; name: string } | null;
}

export interface AdminLessonContent {
  course: {
    id: number;
    title: string;
    slug: string;
  };
  lesson: {
    id: number;
    title: string;
    description: string | null;
    content_type: string;
    status: string;
  };
  draft_revision: AdminLessonRevision | null;
  published_revision: AdminLessonRevision | null;
  available_exercises: AdminExerciseOption[];
}

export interface UpdateDraftMetadataPayload {
  title?: string;
  description?: string | null;
  content_locale?: string;
  duration_minutes?: number | null;
}

function buildContentBasePath(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string
): string {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  const lesson = encodeURIComponent(String(lessonId).trim());

  return `/api/centers/${center}/admin/courses/${course}/lessons/${lesson}/content`;
}

function buildAssetBasePath(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string
): string {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  const lesson = encodeURIComponent(String(lessonId).trim());

  return `/api/centers/${center}/admin/courses/${course}/lessons/${lesson}/assets`;
}

async function handleResponse<T>(response: Response, defaultMessage: string): Promise<T> {
  if (!response.ok) {
    const errorData = await response.json().catch(() => null);
    const message = errorData?.message || defaultMessage;
    throw new AdminLearningApiError(
      message,
      response.status,
      errorData && typeof errorData === "object" && "errors" in errorData
        ? (errorData.errors as Record<string, string[]>)
        : undefined
    );
  }

  const json = await response.json();
  return json.data ?? json;
}

export class AdminLearningApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors?: Record<string, string[]>
  ) {
    super(message);
    this.name = "AdminLearningApiError";
  }
}

/**
 * Retrieve current draft and published revisions for a lesson.
 */
export async function getAdminLessonContent(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string
): Promise<AdminLessonContent> {
  const endpoint = buildContentBasePath(centerSlug, courseSlug, lessonId);
  const response = await authFetch(endpoint, { method: "GET" });

  return handleResponse<AdminLessonContent>(response, "Failed to load lesson content.");
}

/**
 * Create or retrieve the current editable draft.
 */
export async function createAdminDraft(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string
): Promise<AdminLessonRevision> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions`;
  const response = await authFetch(endpoint, { method: "POST" });

  return handleResponse<AdminLessonRevision>(response, "Failed to create lesson draft.");
}

/** Retrieve immutable revision metadata for the lesson history panel. */
export async function getAdminLessonRevisionHistory(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string
): Promise<AdminLessonRevisionHistoryItem[]> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/history`;
  const response = await authFetch(endpoint, { method: "GET" });

  return handleResponse<AdminLessonRevisionHistoryItem[]>(
    response,
    "Failed to load lesson revision history."
  );
}

/** Load a historical revision as read-only content for the editor preview. */
export async function getAdminHistoricalRevision(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number
): Promise<AdminLessonRevision> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/history/${revisionId}`;
  const response = await authFetch(endpoint, { method: "GET" });

  return handleResponse<AdminLessonRevision>(
    response,
    "Failed to load the selected lesson revision."
  );
}

/** Copy a published or superseded revision into a new current draft. */
export async function restoreAdminHistoricalRevision(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number
): Promise<AdminLessonRevision> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}/restore`;
  const response = await authFetch(endpoint, {
    method: "POST",
  });

  return handleResponse<AdminLessonRevision>(
    response,
    "Failed to restore the selected lesson revision."
  );
}

/**
 * Update metadata on the active draft revision.
 */
export async function updateAdminDraftMetadata(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  payload: UpdateDraftMetadataPayload
): Promise<AdminLessonRevision> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}`;
  const response = await authFetch(endpoint, {
    method: "PATCH",
    body: JSON.stringify(payload),
  });

  return handleResponse<AdminLessonRevision>(response, "Failed to update draft metadata.");
}

/**
 * Add a rich text block to the draft revision.
 */
export async function addAdminTextBlock(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  contentJson: TipTapDocument
): Promise<LessonBlock> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}/blocks/text`;
  const response = await authFetch(endpoint, {
    method: "POST",
    body: JSON.stringify({ content_json: contentJson }),
  });

  return handleResponse<LessonBlock>(response, "Failed to add text block.");
}

/**
 * Add a media block referencing an existing asset.
 */
export async function addAdminMediaBlock(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  assetId: number
): Promise<LessonBlock> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}/blocks/media`;
  const response = await authFetch(endpoint, {
    method: "POST",
    body: JSON.stringify({ asset_id: assetId }),
  });

  return handleResponse<LessonBlock>(response, "Failed to add media block.");
}

/**
 * Add an exercise block referencing an existing exercise.
 */
export async function addAdminExerciseBlock(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  exerciseId: number
): Promise<LessonBlock> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}/blocks/exercise`;
  const response = await authFetch(endpoint, {
    method: "POST",
    body: JSON.stringify({ exercise_id: exerciseId }),
  });

  return handleResponse<LessonBlock>(response, "Failed to add exercise block.");
}

/**
 * Atomically create, validate, publish, and insert a new exercise block into a draft revision.
 */
export async function createAndInsertAdminExerciseBlock(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  payload: CreateExercisePayload
): Promise<LessonBlock> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}/blocks/exercise/author-and-insert`;
  const response = await authFetch(endpoint, {
    method: "POST",
    body: JSON.stringify(payload),
  });

  return handleResponse<LessonBlock>(response, "Failed to create and insert exercise block.");
}

/**
 * Update an existing block's content or settings.
 */
export async function updateAdminBlock(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  blockId: number,
  data: { content_json?: TipTapDocument; settings?: Record<string, unknown> }
): Promise<LessonBlock> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}/blocks/${blockId}`;
  const response = await authFetch(endpoint, {
    method: "PATCH",
    body: JSON.stringify(data),
  });

  return handleResponse<LessonBlock>(response, "Failed to update block.");
}

/**
 * Delete a block from the draft revision.
 */
export async function deleteAdminBlock(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  blockId: number
): Promise<{ message: string }> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}/blocks/${blockId}`;
  const response = await authFetch(endpoint, { method: "DELETE" });

  return handleResponse<{ message: string }>(response, "Failed to delete block.");
}

/**
 * Reorder all blocks in the draft revision.

/**
 * Reorder all blocks in the current draft revision.
 * The payload key must match LessonContentController validation.
 */
export async function reorderAdminBlocks(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  blockIds: number[]
): Promise<LessonBlock[]> {
  if (
    !Array.isArray(blockIds) ||
    blockIds.length === 0 ||
    new Set(blockIds).size !== blockIds.length ||
    blockIds.some(
      (id) => !Number.isSafeInteger(id) || id <= 0
    )
  ) {
    throw new Error("Invalid lesson block ordering.");
  }

  const endpoint =
    `${buildContentBasePath(centerSlug, courseSlug, lessonId)}` +
    `/revisions/${revisionId}/blocks/order`;

  const response = await authFetch(endpoint, {
    method: "PUT",
    body: JSON.stringify({
      ordered_block_ids: blockIds,
    }),
  });

  return handleResponse<LessonBlock[]>(
    response,
    "Failed to reorder blocks."
  );
}


/**
 * Publish the current draft revision.
 */
export async function publishAdminLesson(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId?: number
): Promise<AdminLessonRevision> {
  const basePath = buildContentBasePath(centerSlug, courseSlug, lessonId);
  const endpoint = revisionId ? `${basePath}/revisions/${revisionId}/publish` : `${basePath}/publish`;
  const response = await authFetch(endpoint, { method: "POST" });

  return handleResponse<AdminLessonRevision>(response, "Failed to publish lesson.");
}

/**
 * Retrieve lesson assets.
 */
export async function getAdminLessonAssets(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string
): Promise<LearningLessonAsset[]> {
  const endpoint = buildAssetBasePath(centerSlug, courseSlug, lessonId);
  const response = await authFetch(endpoint, { method: "GET" });

  return handleResponse<LearningLessonAsset[]>(response, "Failed to load lesson assets.");
}

/**
 * Upload a media file or image to lesson assets.
 */
export async function uploadAdminLessonAsset(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  formData: FormData
): Promise<LearningLessonAsset> {
  const endpoint = buildAssetBasePath(centerSlug, courseSlug, lessonId);
  const response = await authFetch(endpoint, {
    method: "POST",
    body: formData,
  });

  return handleResponse<LearningLessonAsset>(response, "Failed to upload asset.");
}

/** Add a YouTube or Google Drive video to the lesson asset library. */
export async function createAdminExternalVideoAsset(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  payload: {
    provider: "youtube" | "google_drive";
    url: string;
    title?: string;
    caption?: string;
  }
): Promise<LearningLessonAsset> {
  const response = await authFetch(
    `${buildAssetBasePath(centerSlug, courseSlug, lessonId)}/external-video`,
    {
      method: "POST",
      body: JSON.stringify(payload),
    }
  );

  return handleResponse<LearningLessonAsset>(
    response,
    "Failed to add external video."
  );
}

export interface AdminVideoUploadProvision {
  asset: LearningLessonAsset;
  upload_url: string;
  protocol: "basic" | "tus";
  expires_at: string;
  chunk_size_bytes: number | null;
}

export interface AdminVideoStatus {
  asset: LearningLessonAsset;
  status: LearningLessonAsset["status"];
  progress: number | null;
  error_code: string | null;
}

/** Ask Laravel for a one-time upload capability; video bytes go to Cloudflare. */
export async function requestAdminVideoUpload(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  payload: {
    title: string;
    original_filename: string;
    mime_type: string;
    size_bytes: number;
    resumable: boolean;
  }
): Promise<AdminVideoUploadProvision> {
  const response = await authFetch(`${buildAssetBasePath(centerSlug, courseSlug, lessonId)}/video-upload`, {
    method: "POST",
    body: JSON.stringify(payload),
  });

  return handleResponse<AdminVideoUploadProvision>(response, "Failed to prepare video upload.");
}

/** Upload a small video directly to Cloudflare using its one-time Basic POST URL. */
export function uploadVideoBasic(
  uploadUrl: string,
  file: File,
  onProgress: (progress: number) => void,
  signal?: AbortSignal
): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const formData = new FormData();
    formData.append("file", file);

    xhr.open("POST", uploadUrl);
    xhr.withCredentials = false;
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) {
        onProgress(Math.min(100, (event.loaded / event.total) * 100));
      }
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress(100);
        resolve();
      } else {
        reject(new Error("Cloudflare rejected the video upload."));
      }
    };
    xhr.onerror = () => reject(new Error("Video upload was interrupted."));
    xhr.onabort = () => reject(new DOMException("Video upload cancelled.", "AbortError"));

    if (signal?.aborted) {
      xhr.abort();
      return;
    }

    signal?.addEventListener("abort", () => xhr.abort(), { once: true });
    xhr.send(formData);
  });
}

/** Poll Cloudflare's canonical processing status through the authorized backend. */
export async function getAdminVideoStatus(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  assetId: number
): Promise<AdminVideoStatus> {
  const response = await authFetch(`${buildAssetBasePath(centerSlug, courseSlug, lessonId)}/${assetId}/status`, {
    method: "GET",
  });

  return handleResponse<AdminVideoStatus>(response, "Failed to retrieve video status.");
}

/** Cancel a still-processing upload and clean up its Cloudflare video. */
export async function cancelAdminVideoUpload(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  assetId: number
): Promise<LearningLessonAsset> {
  const response = await authFetch(`${buildAssetBasePath(centerSlug, courseSlug, lessonId)}/${assetId}/cancel`, {
    method: "POST",
  });

  return handleResponse<LearningLessonAsset>(response, "Failed to cancel video upload.");
}

/*
|--------------------------------------------------------------------------
| Exercise Authoring API
|--------------------------------------------------------------------------
*/

export interface CreateExerciseOptionPayload {
  id?: number;
  option_text: string;
  is_correct: boolean;
  sort_order?: number;
}

export interface ShortAnswerNormalizationPayload {
  case_sensitive: boolean;
  trim_whitespace: boolean;
  collapse_internal_whitespace: boolean;
  punctuation_sensitive: boolean;
  diacritic_sensitive: boolean;
}

export interface ShortAnswerConfigPayload {
  accepted_answers: string[];
  normalization: ShortAnswerNormalizationPayload;
}

export interface BlankDefinitionPayload {
  id: string;
  points?: number;
  accepted_answers: string[];
  normalization?: Partial<ShortAnswerNormalizationPayload>;
}

export interface BlankAnswerConfigPayload {
  blanks: BlankDefinitionPayload[];
}

export type ExerciseAnswerConfigPayload = ShortAnswerConfigPayload | BlankAnswerConfigPayload;

export interface CreateQuestionPayload {
  id?: number;
  prompt: string;
  question_type: "single_choice" | "true_false" | "multiple_select" | "short_answer" | "fill_in_the_blanks";
  answer_config?: ExerciseAnswerConfigPayload | null;
  explanation?: string | null;
  points?: number;
  sort_order?: number;
  options: CreateExerciseOptionPayload[];
}

export type ResultReleaseTiming = "immediately" | "after_final_attempt";

export interface ExerciseResultSettingsPayload {
  show_score: boolean;
  show_percentage: boolean;
  show_pass_fail: boolean;
  show_question_results: boolean;
  show_student_answers: boolean;
  show_correct_answers: boolean;
  show_explanations: boolean;
  show_points_per_question: boolean;
  release_timing: ResultReleaseTiming;
}

export interface CreateExercisePayload {
  title: string;
  instructions?: string | null;
  max_attempts?: number | null;
  passing_score?: number;
  show_correct_answers_after_submission?: boolean;
  result_settings?: ExerciseResultSettingsPayload;
  sort_order?: number;
  questions?: CreateQuestionPayload[];
}

export interface AdminFullExercise {
  id: number;
  title: string;
  instructions: string | null;
  max_attempts: number | null;
  passing_score: number;
  show_correct_answers_after_submission: boolean;
  result_settings?: ExerciseResultSettingsPayload;
  has_incompatible_release_configuration?: boolean;
  incompatible_release_affected_attempts?: number;
  sort_order: number;
  status: "draft" | "published" | "archived";
  published_at: string | null;
  questions: {
    id: number;
    question_type: "single_choice" | "true_false" | "multiple_select" | "short_answer" | "fill_in_the_blanks";
    answer_config: ExerciseAnswerConfigPayload | null;
    prompt: string;
    explanation: string | null;
    points: number;
    sort_order: number;
    options: {
      id: number;
      option_text: string;
      is_correct: boolean;
      sort_order: number;
    }[];
  }[];
}

export function buildExerciseBasePath(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string
): string {
  return `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/../exercises`.replace("/content/..", "");
}

/**
 * List all exercises for a lesson.
 */
export async function getAdminExercises(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string
): Promise<AdminFullExercise[]> {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  const lesson = encodeURIComponent(String(lessonId).trim());
  const endpoint = `/api/centers/${center}/admin/courses/${course}/lessons/${lesson}/exercises`;

  const response = await authFetch(endpoint, { method: "GET" });
  return handleResponse<AdminFullExercise[]>(response, "Failed to load exercises.");
}

/**
 * Create a new exercise.
 */
export async function createAdminExercise(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  payload: CreateExercisePayload
): Promise<AdminFullExercise> {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  const lesson = encodeURIComponent(String(lessonId).trim());
  const endpoint = `/api/centers/${center}/admin/courses/${course}/lessons/${lesson}/exercises`;

  const response = await authFetch(endpoint, {
    method: "POST",
    body: JSON.stringify(payload),
  });

  return handleResponse<AdminFullExercise>(response, "Failed to create exercise.");
}

/**
 * Update an existing exercise.
 */
export async function updateAdminExercise(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  exerciseId: number,
  payload: Partial<CreateExercisePayload>
): Promise<AdminFullExercise> {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  const lesson = encodeURIComponent(String(lessonId).trim());
  const endpoint = `/api/centers/${center}/admin/courses/${course}/lessons/${lesson}/exercises/${exerciseId}`;

  const response = await authFetch(endpoint, {
    method: "PATCH",
    body: JSON.stringify(payload),
  });

  return handleResponse<AdminFullExercise>(response, "Failed to update exercise.");
}

/**
 * Publish an exercise.
 */
export async function publishAdminExercise(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  exerciseId: number
): Promise<AdminFullExercise> {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  const lesson = encodeURIComponent(String(lessonId).trim());
  const endpoint = `/api/centers/${center}/admin/courses/${course}/lessons/${lesson}/exercises/${exerciseId}/publish`;

  const response = await authFetch(endpoint, { method: "POST" });
  return handleResponse<AdminFullExercise>(response, "Failed to publish exercise.");
}

/** Delete a draft exercise that is not referenced by a protected revision. */
export async function deleteAdminExercise(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  exerciseId: number
): Promise<void> {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  const lesson = encodeURIComponent(String(lessonId).trim());
  const endpoint = `/api/centers/${center}/admin/courses/${course}/lessons/${lesson}/exercises/${exerciseId}`;

  const response = await authFetch(endpoint, { method: "DELETE" });
  await handleResponse<null>(response, "Failed to delete exercise.");
}

/**
 * Grade a simulated student attempt in memory without creating any database records.
 */
export async function simulateAdminExerciseGrade(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  exerciseId: number,
  answers: SaveAttemptAnswerPayload[],
  attemptScenario: "first" | "final" = "first"
): Promise<ExerciseAttemptData> {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  const lesson = encodeURIComponent(String(lessonId).trim());
  const endpoint = `/api/centers/${center}/admin/courses/${course}/lessons/${lesson}/exercises/${exerciseId}/simulate-grade`;

  const response = await authFetch(endpoint, {
    method: "POST",
    body: JSON.stringify({ answers, attempt_scenario: attemptScenario }),
  });

  return handleResponse<ExerciseAttemptData>(response, "Failed to grade simulated attempt.");
}

// ---------------------------------------------------------------------------
// Curriculum Management (Modules & Lessons)
// ---------------------------------------------------------------------------

export type CanonicalLessonKind = "video" | "reading" | "resources" | "exercise";
export type EffectiveLessonKind = CanonicalLessonKind | "legacy_mixed";

export interface CurriculumLessonSummary {
  has_video: boolean;
  video_duration_seconds: number | null;
  has_reading_content: boolean;
  resources_count: number;
  broken_media_count?: number;
  question_count: number;
  total_points: number;
}

export interface CurriculumLesson {
  id: number;
  course_module_id: number;
  title: string;
  description: string | null;
  content_type: string;
  effective_kind: EffectiveLessonKind;
  can_change_content_type: boolean;
  duration_minutes: number | null;
  sort_order: number;
  status: "draft" | "published" | "archived";
  published_at: string | null;
  updated_at: string | null;
  draft_revision_id: number | null;
  published_revision_id: number | null;
  has_draft_changes?: boolean;
  thumbnail_url?: string | null;
  thumbnail_asset_id?: number | null;
  thumbnail_alt?: string | null;
  assets_count: number;
  exercises_count: number;
  summary: CurriculumLessonSummary;
}

export interface CurriculumModule {
  id: number;
  title: string;
  description: string | null;
  sort_order: number;
  status: "draft" | "published" | "archived";
  published_at: string | null;
  lesson_count: number;
  published_lesson_count: number;
  lessons: CurriculumLesson[];
}

export interface CurriculumCourseOutcome {
  id?: number;
  locale?: string;
  content: string;
  is_highlight: boolean;
  sort_order?: number;
  status?: string;
}

export interface CurriculumCourseSummary {
  id: number;
  title: string;
  slug: string;
  short_description: string | null;
  description: string | null;
  prerequisites: string | null;
  target_audience: string | null;
  cover_image_url: string | null;
  cover_image_alt: string | null;
  source_locale: string;
  study_format: "in_person" | "online" | "hybrid";
  duration_hours: number | null;
  price: string | null;
  currency: string | null;
  pricing_mode: "fixed" | "from" | "upon_request" | "free";
  is_curriculum_public: boolean;
  meta_title: string | null;
  meta_description: string | null;
  status: "draft" | "published" | "archived";
  published_at: string | null;
  first_published_at?: string | null;
  updated_at: string | null;
  language: { id: number; code: string; name: string; native_name: string | null } | null;
  level: { id: number; code: string; name: string } | null;
  outcomes?: CurriculumCourseOutcome[];
}

export interface CurriculumResponse {
  center: { id: number; name: string; slug: string };
  course: CurriculumCourseSummary;
  language_options?: { id: number; code: string; name: string; native_name: string | null }[];
  level_options?: { id: number; code: string; name: string }[];
  statistics: {
    modules: number;
    lessons: number;
    published_lessons: number;
    draft_lessons: number;
    archived_lessons: number;
    exercises: number;
    assets: number;
  };
  modules: CurriculumModule[];
}

function curriculumBase(centerSlug: string, courseSlug: string): string {
  const center = encodeURIComponent(String(centerSlug).trim());
  const course = encodeURIComponent(String(courseSlug).trim());
  return `/api/centers/${center}/admin/courses/${course}`;
}

export async function getAdminCourseCurriculum(
  centerSlug: string,
  courseSlug: string
): Promise<CurriculumResponse> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/curriculum`);
  return handleResponse<CurriculumResponse>(response, "Failed to load course curriculum.");
}

export async function createAdminModule(
  centerSlug: string,
  courseSlug: string,
  payload: { title: string; description?: string | null; status?: "draft" | "published" }
): Promise<CurriculumModule> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/modules`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
  return handleResponse<CurriculumModule>(response, "Failed to create module.");
}

export async function updateAdminModule(
  centerSlug: string,
  courseSlug: string,
  moduleId: number,
  payload: { title?: string; description?: string | null }
): Promise<CurriculumModule> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/modules/${moduleId}`, {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
  return handleResponse<CurriculumModule>(response, "Failed to update module.");
}

export async function publishAdminModule(
  centerSlug: string,
  courseSlug: string,
  moduleId: number
): Promise<void> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/modules/${moduleId}/publish`, {
    method: "POST",
  });
  await handleResponse<unknown>(response, "Failed to publish module.");
}

export async function restoreAdminModule(
  centerSlug: string,
  courseSlug: string,
  moduleId: number
): Promise<void> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/modules/${moduleId}/restore`, {
    method: "POST",
  });
  await handleResponse<unknown>(response, "Failed to restore module.");
}

export async function reorderAdminModules(
  centerSlug: string,
  courseSlug: string,
  moduleIds: number[]
): Promise<void> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/modules/order`, {
    method: "PUT",
    body: JSON.stringify({ module_ids: moduleIds }),
  });
  await handleResponse<null>(response, "Failed to reorder modules.");
}

export async function archiveAdminModule(
  centerSlug: string,
  courseSlug: string,
  moduleId: number
): Promise<void> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/modules/${moduleId}/archive`, {
    method: "PATCH",
  });
  await handleResponse<unknown>(response, "Failed to archive module.");
}

export async function createAdminLesson(
  centerSlug: string,
  courseSlug: string,
  moduleId: number,
  payload: {
    title: string;
    description?: string | null;
    content_type: CanonicalLessonKind;
    duration_minutes?: number | null;
  }
): Promise<CurriculumLesson> {
  const response = await authFetch(
    `${curriculumBase(centerSlug, courseSlug)}/modules/${moduleId}/lessons`,
    {
      method: "POST",
      body: JSON.stringify(payload),
    }
  );
  return handleResponse<CurriculumLesson>(response, "Failed to create lesson.");
}

export async function updateAdminLesson(
  centerSlug: string,
  courseSlug: string,
  lessonId: number,
  payload: {
    title?: string;
    description?: string | null;
    content_type?: EffectiveLessonKind;
    duration_minutes?: number | null;
  }
): Promise<CurriculumLesson> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/lessons/${lessonId}`, {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
  return handleResponse<CurriculumLesson>(response, "Failed to update lesson.");
}

export async function moveAdminLesson(
  centerSlug: string,
  courseSlug: string,
  lessonId: number,
  targetModuleId: number,
  options?: { placement?: "first" | "last" | "before" | "after"; anchorLessonId?: number | null }
): Promise<CurriculumLesson> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/lessons/${lessonId}/move`, {
    method: "POST",
    body: JSON.stringify({
      target_module_id: targetModuleId,
      placement: options?.placement ?? "last",
      anchor_lesson_id: options?.anchorLessonId ?? null,
    }),
  });
  return handleResponse<CurriculumLesson>(response, "Failed to move lesson.");
}

export async function reorderAdminLessons(
  centerSlug: string,
  courseSlug: string,
  moduleId: number,
  lessonIds: number[]
): Promise<void> {
  const response = await authFetch(
    `${curriculumBase(centerSlug, courseSlug)}/modules/${moduleId}/lessons/order`,
    {
      method: "PUT",
      body: JSON.stringify({ lesson_ids: lessonIds }),
    }
  );
  await handleResponse<null>(response, "Failed to reorder lessons.");
}

export async function duplicateAdminLesson(
  centerSlug: string,
  courseSlug: string,
  lessonId: number
): Promise<CurriculumLesson> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/lessons/${lessonId}/duplicate`, {
    method: "POST",
  });
  return handleResponse<CurriculumLesson>(response, "Failed to duplicate lesson.");
}

export async function archiveAdminLesson(
  centerSlug: string,
  courseSlug: string,
  lessonId: number
): Promise<void> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/lessons/${lessonId}/archive`, {
    method: "PATCH",
  });
  await handleResponse<unknown>(response, "Failed to archive lesson.");
}

export async function restoreAdminLesson(
  centerSlug: string,
  courseSlug: string,
  lessonId: number
): Promise<CurriculumLesson> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/lessons/${lessonId}/restore`, {
    method: "POST",
  });
  return handleResponse<CurriculumLesson>(response, "Failed to restore lesson.");
}

export interface AdminCoursePreviewData {
  preview_version: "draft" | "published";
  is_never_published: boolean;
  has_draft_changes: boolean;
  course: {
    id: number;
    title: string;
    slug: string;
    short_description: string | null;
    description: string | null;
    prerequisites: string | null;
    target_audience: string | null;
    study_format: "in_person" | "online" | "hybrid";
    duration_hours: number | null;
    cover_image_url: string | null;
    cover_image_alt: string | null;
    price: string | null;
    currency: string | null;
    pricing_mode: "fixed" | "from" | "upon_request" | "free";
    is_curriculum_public: boolean;
    status: "draft" | "published" | "archived";
    language: { id: number; code: string; name: string; native_name: string | null } | null;
    level: { id: number; code: string; name: string } | null;
    outcomes: CurriculumCourseOutcome[];
  };
  modules: {
    id: number;
    title: string;
    description: string | null;
    status: string;
    lessons: {
      id: number;
      title: string;
      description: string | null;
      content_type: string;
      effective_kind: EffectiveLessonKind;
      duration_minutes: number | null;
      status: string;
      has_draft: boolean;
      revision: { id: number; version_number: number; status: string } | null;
      blocks: import("@/lib/learning").LessonBlock[];
    }[];
  }[];
}

export async function getAdminCoursePreview(
  centerSlug: string,
  courseSlug: string,
  version: "draft" | "published" = "draft"
): Promise<AdminCoursePreviewData> {
  const response = await authFetch(
    `${curriculumBase(centerSlug, courseSlug)}/preview?version=${encodeURIComponent(version)}`
  );
  return handleResponse<AdminCoursePreviewData>(response, "Failed to load course preview.");
}

export async function updateAdminCourseSettings(
  centerSlug: string,
  courseSlug: string,
  payload: Record<string, unknown>
): Promise<CurriculumCourseSummary> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/settings`, {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
  return handleResponse<CurriculumCourseSummary>(response, "Failed to update course settings.");
}

export async function publishAdminCourse(
  centerSlug: string,
  courseSlug: string
): Promise<CurriculumCourseSummary> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/publish`, {
    method: "POST",
  });
  return handleResponse<CurriculumCourseSummary>(response, "Failed to publish course.");
}

export async function unpublishAdminCourse(
  centerSlug: string,
  courseSlug: string
): Promise<CurriculumCourseSummary> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/unpublish`, {
    method: "POST",
  });
  return handleResponse<CurriculumCourseSummary>(response, "Failed to unpublish course.");
}

export async function archiveAdminCourse(
  centerSlug: string,
  courseSlug: string
): Promise<CurriculumCourseSummary> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/archive`, {
    method: "PATCH",
  });
  return handleResponse<CurriculumCourseSummary>(response, "Failed to archive course.");
}

export async function restoreAdminCourse(
  centerSlug: string,
  courseSlug: string
): Promise<CurriculumCourseSummary> {
  const response = await authFetch(`${curriculumBase(centerSlug, courseSlug)}/restore`, {
    method: "POST",
  });
  return handleResponse<CurriculumCourseSummary>(response, "Failed to restore course.");
}

export async function triggerCourseRevalidation(
  centerSlug: string,
  courseSlug: string,
  options?: { oldSlug?: string; invalidateListing?: boolean }
): Promise<{ ok: true } | { ok: false; message: string }> {
  try {
    const response = await fetch("/api/revalidate/courses", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        centerSlug,
        slug: courseSlug,
        oldSlug: options?.oldSlug,
        invalidateListing: options?.invalidateListing === true,
      }),
    });

    if (response.ok) {
      return { ok: true };
    }

    const payload: unknown = await response.json().catch(() => null);
    const message =
      typeof payload === "object" && payload !== null && "error" in payload &&
      typeof payload.error === "string"
        ? payload.error
        : `Course cache revalidation failed (HTTP ${response.status}).`;

    return { ok: false, message };
  } catch (error: unknown) {
    return {
      ok: false,
      message: error instanceof Error
        ? error.message
        : "Course cache revalidation could not be reached.",
    };
  }
}

