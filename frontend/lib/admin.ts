import { authFetch } from "@/lib/auth";

export interface CenterRole {
  id: number;
  key: string;
  name: string;
  description?: string;
}

export interface CenterMember {
  id: number;
  name: string;
  email: string;
  global_status: string;
  membership_status: string;
  joined_at?: string;
  roles: CenterRole[];
}

export interface PlatformUserCenter {
  center_id: number;
  center_name: string;
  center_slug: string;
  status: string;
  roles: { key: string; name: string }[];
}

export interface PlatformUser {
  id: number;
  name: string;
  email: string;
  status: string;
  email_verified: boolean;
  is_platform_admin: boolean;
  created_at?: string;
  centers: PlatformUserCenter[];
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * Fetch all users belonging to a center.
 */
export async function apiGetCenterUsers(
  centerSlug: string,
  params: { search?: string; role?: string; page?: number } = {}
): Promise<PaginatedResponse<CenterMember>> {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.role) query.set("role", params.role);
  if (params.page) query.set("page", params.page.toString());

  const response = await authFetch(
    `/api/centers/${encodeURIComponent(centerSlug)}/admin/users?${query.toString()}`
  );

  if (!response.ok) {
    const errorData = await response.json().catch(() => null);
    throw new Error(errorData?.message || "Failed to load center members.");
  }

  return response.json();
}

/**
 * Fetch assignable roles in a center.
 */
export async function apiGetAvailableRoles(
  centerSlug: string
): Promise<CenterRole[]> {
  const response = await authFetch(
    `/api/centers/${encodeURIComponent(centerSlug)}/admin/roles`
  );

  if (!response.ok) {
    return [
      { id: 1, key: "center_admin", name: "Center Admin" },
      { id: 2, key: "teacher", name: "Teacher" },
      { id: 3, key: "student", name: "Student" },
    ];
  }

  const data = await response.json();
  return data.roles || [];
}

/**
 * Update a user's roles within a center.
 */
export async function apiUpdateUserRoles(
  centerSlug: string,
  userId: number,
  roles: string[]
): Promise<{ message: string; user: { roles: CenterRole[] } }> {
  const response = await authFetch(
    `/api/centers/${encodeURIComponent(centerSlug)}/admin/users/${userId}/roles`,
    {
      method: "PUT",
      body: JSON.stringify({ roles }),
    }
  );

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const errorMsg =
      data?.errors?.roles?.[0] || data?.message || "Failed to update roles.";
    throw new Error(errorMsg);
  }

  return data;
}

/**
 * Update a user's membership status (active/suspended).
 */
export async function apiUpdateUserMembershipStatus(
  centerSlug: string,
  userId: number,
  status: "active" | "suspended"
): Promise<{ message: string; status: string }> {
  const response = await authFetch(
    `/api/centers/${encodeURIComponent(centerSlug)}/admin/users/${userId}/status`,
    {
      method: "PUT",
      body: JSON.stringify({ status }),
    }
  );

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(data?.message || "Failed to update membership status.");
  }

  return data;
}

/**
 * Add a new or existing user to a center with assigned roles.
 */
export async function apiAddUserToCenter(
  centerSlug: string,
  payload: { email: string; name?: string; roles: string[] }
): Promise<CenterMember> {
  const response = await authFetch(
    `/api/centers/${encodeURIComponent(centerSlug)}/admin/users`,
    {
      method: "POST",
      body: JSON.stringify(payload),
    }
  );

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const errorMsg =
      data?.errors?.email?.[0] ||
      data?.errors?.roles?.[0] ||
      data?.message ||
      "Failed to add user to center.";
    throw new Error(errorMsg);
  }

  return data.user;
}

/**
 * Remove a user from a center.
 */
export async function apiRemoveUserFromCenter(
  centerSlug: string,
  userId: number
): Promise<void> {
  const response = await authFetch(
    `/api/centers/${encodeURIComponent(centerSlug)}/admin/users/${userId}`,
    {
      method: "DELETE",
    }
  );

  if (!response.ok) {
    const data = await response.json().catch(() => null);
    throw new Error(data?.message || "Failed to remove user from center.");
  }
}

/**
 * Toggle platform super admin status (Platform Admin only).
 */
export async function apiToggleSuperAdmin(
  userId: number,
  isSuperAdmin: boolean
): Promise<{ message: string; is_platform_admin: boolean }> {
  const response = await authFetch(
    `/api/platform-admin/users/${userId}/super-admin`,
    {
      method: "PUT",
      body: JSON.stringify({ is_super_admin: isSuperAdmin }),
    }
  );

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(data?.message || "Failed to update Super Admin status.");
  }

  return data;
}

/**
 * Update global account status (active, disabled, suspended).
 */
export async function apiUpdateGlobalUserStatus(
  userId: number,
  status: "active" | "disabled" | "suspended"
): Promise<{ message: string; status: string }> {
  const response = await authFetch(
    `/api/platform-admin/users/${userId}/status`,
    {
      method: "PUT",
      body: JSON.stringify({ status }),
    }
  );

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(data?.message || "Failed to update user status.");
  }

  return data;
}


/*
|--------------------------------------------------------------------------
| Platform Learning Center Selection
|--------------------------------------------------------------------------
*/

export interface PlatformCenter {
  id: number;
  name: string;
  slug: string;
  status: string;
  logo_url: string | null;
  default_locale: string;
  courses_count: number;
  members_count: number;
}

export interface PlatformLearningCourse {
  id: number;
  title: string;
  slug: string;
  short_description: string | null;
  cover_image_url: string | null;
  duration_hours: number | null;
  status: "draft" | "published" | "archived";
  published_at: string | null;
  modules_count: number;
}

export interface AdminLearningLesson {
  id: number;
  title: string;
  description: string | null;
  content_type: string;
  duration_minutes: number | null;
  sort_order: number;
  status: "draft" | "published" | "archived";
  published_at: string | null;
}

export interface AdminLearningModule {
  id: number;
  title: string;
  description: string | null;
  sort_order: number;
  status: "draft" | "published" | "archived";
  published_at: string | null;
  lessons: AdminLearningLesson[];
}

export interface AdminLearningOverview {
  access_mode: "admin_preview";
  course: {
    id: number;
    title: string;
    slug: string;
    status: "draft" | "published" | "archived";
    published_at: string | null;
  };
  progress_summary: {
    total_lessons: number;
    completed_lessons: number;
    in_progress_lessons: number;
    not_started_lessons: number;
    completion_percentage: number;
  };
  modules: AdminLearningModule[];
}

/**
 * Fetch all platform centers independently of user memberships.
 */
export async function apiGetPlatformCenters(
  params: {
    search?: string;
    status?: string;
    page?: number;
    per_page?: number;
  } = {}
): Promise<PaginatedResponse<PlatformCenter>> {
  const query = new URLSearchParams();

  if (params.search?.trim()) {
    query.set("search", params.search.trim());
  }

  if (params.status && params.status !== "all") {
    query.set("status", params.status);
  }

  if (params.page) {
    query.set("page", String(params.page));
  }

  if (params.per_page) {
    query.set("per_page", String(params.per_page));
  }

  const suffix = query.toString();

  const response = await authFetch(
    `/api/platform-admin/centers${suffix ? `?${suffix}` : ""}`
  );

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(
      data?.message || "Unable to retrieve platform centers."
    );
  }

  return data as PaginatedResponse<PlatformCenter>;
}

/**
 * Fetch all course states for one selected platform center.
 */
export async function apiGetPlatformCenterCourses(
  centerSlug: string,
  params: {
    search?: string;
    status?: string;
    page?: number;
    per_page?: number;
  } = {}
): Promise<{
  center: Omit<PlatformCenter, "courses_count" | "members_count">;
  courses: PaginatedResponse<PlatformLearningCourse>;
}> {
  const query = new URLSearchParams();

  if (params.search?.trim()) {
    query.set("search", params.search.trim());
  }

  if (params.status && params.status !== "all") {
    query.set("status", params.status);
  }

  if (params.page) {
    query.set("page", String(params.page));
  }

  if (params.per_page) {
    query.set("per_page", String(params.per_page));
  }

  const suffix = query.toString();

  const response = await authFetch(
    `/api/platform-admin/centers/${encodeURIComponent(centerSlug)}/courses${suffix ? `?${suffix}` : ""}`
  );

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(
      data?.message || "Unable to retrieve center courses."
    );
  }

  return data;
}

/**
 * Retrieve the administrative learning overview for one course.
 *
 * Laravel determines admin preview permissions from the authenticated user.
 */
export async function apiGetPlatformLearningOverview(
  centerSlug: string,
  courseSlug: string
): Promise<AdminLearningOverview> {
  const response = await authFetch(
    `/api/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(courseSlug)}/learn`,
    {
      method: "GET",
      cache: "no-store",
    }
  );

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(
      data?.message || "Unable to retrieve the course curriculum."
    );
  }

  const overview = data?.data as AdminLearningOverview | undefined;

  if (!overview || overview.access_mode !== "admin_preview") {
    throw new Error(
      "The server did not authorize administrative preview."
    );
  }

  return overview;
}