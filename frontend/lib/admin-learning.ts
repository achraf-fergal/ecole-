import { authFetch } from "@/lib/auth";
import type {
  LessonBlock,
  TipTapDocument,
  LearningLessonAsset,
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
    throw new Error(message);
  }

  const json = await response.json();
  return json.data ?? json;
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
 */
export async function reorderAdminBlocks(
  centerSlug: string,
  courseSlug: string,
  lessonId: number | string,
  revisionId: number,
  blockIds: number[]
): Promise<LessonBlock[]> {
  const endpoint = `${buildContentBasePath(centerSlug, courseSlug, lessonId)}/revisions/${revisionId}/blocks/order`;
  const response = await authFetch(endpoint, {
    method: "PUT",
    body: JSON.stringify({ block_ids: blockIds }),
  });

  return handleResponse<LessonBlock[]>(response, "Failed to reorder blocks.");
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

export interface CreateQuestionPayload {
  id?: number;
  prompt: string;
  question_type: "single_choice" | "true_false";
  explanation?: string | null;
  points?: number;
  sort_order?: number;
  options: CreateExerciseOptionPayload[];
}

export interface CreateExercisePayload {
  title: string;
  instructions?: string | null;
  max_attempts?: number | null;
  passing_score?: number;
  show_correct_answers_after_submission?: boolean;
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
  sort_order: number;
  status: "draft" | "published" | "archived";
  published_at: string | null;
  questions: {
    id: number;
    question_type: "single_choice" | "true_false";
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

