export interface UserCenterRole {
  key: string;
  name: string;
}

export interface UserCenterMembership {
  id: number;
  name: string;
  slug: string;
  status: string;
  roles: UserCenterRole[];
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  status: string;
  email_verified: boolean;
  is_platform_admin: boolean;
  centers: UserCenterMembership[];
  created_at?: string;
}

export interface AuthResponse {
  message?: string;
  user?: AuthUser;
  email_verified?: boolean;
}

/**
 * Sanitize redirect parameter to prevent open redirect vulnerabilities.
 * Only allows relative paths starting with a single forward slash.
 */
export function sanitizeRedirect(
  targetUrl: string | null | undefined,
  fallback = "/courses"
): string {
  if (!targetUrl || typeof targetUrl !== "string") {
    return fallback;
  }

  const trimmed = targetUrl.trim();

  // Disallow protocol-relative URLs (//evil.com), backslashes, and absolute URLs with schemes
  if (
    !trimmed.startsWith("/") ||
    trimmed.startsWith("//") ||
    trimmed.startsWith("/\\") ||
    trimmed.includes(":")
  ) {
    return fallback;
  }

  return trimmed;
}

/**
 * Resolve client-side or server-side API base URL.
 */
function getApiBaseUrl(): string {
  if (typeof window !== "undefined") {
    return process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000";
  }

  return process.env.LARAVEL_API_URL || "http://localhost:8000";
}

export function getGoogleAuthUrl(): string {
  return `${getApiBaseUrl()}/api/auth/google/redirect`;
}

/**
 * Extract a cookie by name from document.cookie in the browser.
 */
export function getClientCookie(name: string): string | null {
  if (typeof document === "undefined") {
    return null;
  }

  const match = document.cookie.match(
    new RegExp("(?:^|; )" + name.replace(/([.$?*|{}()[\]\\/+^])/g, "\\$1") + "=([^;]*)")
  );

  return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Request CSRF cookie from the backend for browser mutation requests.
 */
export async function getCsrfCookie(): Promise<string | null> {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    const baseUrl = getApiBaseUrl();
    const response = await fetch(`${baseUrl}/api/auth/csrf-cookie`, {
      method: "GET",
      headers: {
        Accept: "application/json",
      },
      credentials: "include",
    });

    if (response.ok) {
      const data = (await response.json().catch(() => null)) as { csrf_token?: string } | null;
      return data?.csrf_token || getClientCookie("XSRF-TOKEN");
    }
  } catch (err) {
    console.error("Failed to fetch CSRF cookie:", err);
  }

  return getClientCookie("XSRF-TOKEN");
}

/**
 * Fetch wrapper with credentials and automatic CSRF header attachment for state-changing requests.
 */
export async function authFetch(
  endpoint: string,
  init: RequestInit = {}
): Promise<Response> {
  const baseUrl = getApiBaseUrl();
  const url = endpoint.startsWith("http") ? endpoint : `${baseUrl}${endpoint}`;

  const headers = new Headers(init.headers || {});
  headers.set("Accept", "application/json");

  const method = (init.method || "GET").toUpperCase();
  const isMutation = ["POST", "PUT", "PATCH", "DELETE"].includes(method);

  if (isMutation && typeof window !== "undefined") {
    let csrfToken = getClientCookie("XSRF-TOKEN");

    if (!csrfToken) {
      csrfToken = await getCsrfCookie();
    }

    if (csrfToken) {
      headers.set("X-XSRF-TOKEN", csrfToken);
    }

    if (!headers.has("Content-Type") && !(init.body instanceof FormData)) {
      headers.set("Content-Type", "application/json");
    }
  }

  return fetch(url, {
    ...init,
    headers,
    credentials: "include",
  });
}

/**
 * Authenticate credentials.
 */
export async function apiLogin(credentials: {
  email: string;
  password: string;
}): Promise<AuthResponse> {
  const response = await authFetch("/api/auth/login", {
    method: "POST",
    body: JSON.stringify(credentials),
  });

  const data = await response.json();

  if (!response.ok) {
    const errorMsg =
      data?.errors?.email?.[0] ||
      data?.message ||
      "Invalid login credentials.";
    throw new Error(errorMsg);
  }

  return data as AuthResponse;
}

/**
 * Register a new user account.
 */
export async function apiRegister(payload: {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  terms: boolean;
}): Promise<AuthResponse> {
  const response = await authFetch("/api/auth/register", {
    method: "POST",
    body: JSON.stringify(payload),
  });

  const data = await response.json();

  if (!response.ok) {
    const validationErrors = data?.errors;
    let errorMsg = data?.message || "Registration failed.";

    if (validationErrors && typeof validationErrors === "object") {
      const firstField = Object.keys(validationErrors)[0];
      if (firstField && Array.isArray(validationErrors[firstField])) {
        errorMsg = validationErrors[firstField][0];
      }
    }

    throw new Error(errorMsg);
  }

  return data as AuthResponse;
}

/**
 * Logout current device session.
 */
export async function apiLogout(): Promise<void> {
  await authFetch("/api/auth/logout", {
    method: "POST",
  });
}

/**
 * Logout all devices.
 */
export async function apiLogoutAll(): Promise<void> {
  await authFetch("/api/auth/logout-all", {
    method: "POST",
  });
}

/**
 * Get current authenticated user profile.
 */
export async function apiGetMe(): Promise<AuthUser | null> {
  try {
    const response = await authFetch("/api/auth/me", {
      method: "GET",
      cache: "no-store",
    });

    if (!response.ok) {
      return null;
    }

    const data = await response.json();
    return (data?.user as AuthUser) || null;
  } catch {
    return null;
  }
}

/**
 * Refresh access token.
 */
export async function apiRefreshToken(): Promise<boolean> {
  try {
    const response = await authFetch("/api/auth/refresh", {
      method: "POST",
    });

    return response.ok;
  } catch {
    return false;
  }
}

/**
 * Request password reset link.
 */
export async function apiForgotPassword(email: string): Promise<string> {
  const response = await authFetch("/api/auth/forgot-password", {
    method: "POST",
    body: JSON.stringify({ email }),
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data?.message || "Failed to process request.");
  }

  return (
    data?.message ||
    "If your email is registered, you will receive a reset link shortly."
  );
}

/**
 * Reset password with token.
 */
export async function apiResetPassword(payload: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<string> {
  const response = await authFetch("/api/auth/reset-password", {
    method: "POST",
    body: JSON.stringify(payload),
  });

  const data = await response.json();

  if (!response.ok) {
    const validationErrors = data?.errors;
    let errorMsg = data?.message || "Failed to reset password.";

    if (validationErrors && typeof validationErrors === "object") {
      const firstField = Object.keys(validationErrors)[0];
      if (firstField && Array.isArray(validationErrors[firstField])) {
        errorMsg = validationErrors[firstField][0];
      }
    }

    throw new Error(errorMsg);
  }

  return data?.message || "Password reset successfully.";
}

/**
 * Resend email verification notification.
 */
export async function apiResendVerification(): Promise<string> {
  const response = await authFetch("/api/auth/email/verification-notification", {
    method: "POST",
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data?.message || "Failed to resend verification email.");
  }

  return data?.message || "Verification link sent.";
}
