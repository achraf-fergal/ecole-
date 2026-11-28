import { revalidatePath, revalidateTag } from "next/cache";
import { cookies } from "next/headers";
import { NextResponse } from "next/server";

const SLUG_REGEX = /^[a-z0-9]+(?:-[a-z0-9]+)*$/i;
const MAX_BODY_BYTES = 4_096;

type JsonRecord = Record<string, unknown>;

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

async function readBoundedBody(request: Request): Promise<string | null> {
  const contentLength = request.headers.get("content-length");
  if (contentLength !== null) {
    const declaredLength = Number(contentLength);
    if (!Number.isSafeInteger(declaredLength) || declaredLength < 0) {
      return null;
    }
    if (declaredLength > MAX_BODY_BYTES) {
      return null;
    }
  }

  if (!request.body) {
    return "";
  }

  const reader = request.body.getReader();
  const chunks: Uint8Array[] = [];
  let totalBytes = 0;

  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      totalBytes += value.byteLength;
      if (totalBytes > MAX_BODY_BYTES) {
        await reader.cancel();
        return null;
      }

      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }

  const body = new Uint8Array(totalBytes);
  let offset = 0;
  for (const chunk of chunks) {
    body.set(chunk, offset);
    offset += chunk.byteLength;
  }

  return new TextDecoder().decode(body);
}

async function authorizeForCenter(
  centerSlug: string
): Promise<"authorized" | "unauthenticated" | "forbidden" | "unavailable"> {
  const apiBase = process.env.LARAVEL_API_URL;
  if (!apiBase) return "unavailable";

  const cookieName = process.env.JWT_COOKIE_NAME || "jwt_token";
  const cookieStore = await cookies();
  const token = cookieStore.get(cookieName)?.value;
  if (!token) return "unauthenticated";

  try {
    const response = await fetch(
      `${apiBase.replace(/\/$/, "")}/api/auth/me`,
      {
        method: "GET",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        cache: "no-store",
        redirect: "error",
      }
    );
    if (response.status === 401) return "unauthenticated";
    if (response.status === 403) return "forbidden";
    if (!response.ok) return "unavailable";

    const payload: unknown = await response.json();
    if (!isRecord(payload) || !isRecord(payload.user)) return "unavailable";

    const user = payload.user;
    if (user.is_platform_admin === true) return "authorized";
    if (!Array.isArray(user.centers)) return "unavailable";

    const hasCenterPermission = user.centers.some((center) => {
      if (!isRecord(center) || center.slug !== centerSlug || center.status !== "active") {
        return false;
      }
      if (!Array.isArray(center.roles)) return false;

      return center.roles.some(
        (role) => isRecord(role) && role.key === "center_admin"
      );
    });
    return hasCenterPermission ? "authorized" : "forbidden";
  } catch {
    return "unavailable";
  }
}

function jsonError(error: string, status: number): NextResponse {
  return NextResponse.json(
    { error },
    { status, headers: { "Cache-Control": "no-store" } }
  );
}

export async function POST(request: Request): Promise<NextResponse> {
  const requestOrigin = new URL(request.url).origin;
  if (request.headers.get("origin") !== requestOrigin) {
    return jsonError("Cross-origin revalidation requests are not allowed.", 403);
  }
  const fetchSite = request.headers.get("sec-fetch-site");
  if (fetchSite !== null && fetchSite !== "same-origin") {
    return jsonError("Same-origin revalidation requests are required.", 403);
  }

  const contentType = request.headers.get("content-type") ?? "";
  if (!/^application\/json(?:\s*;|\s*$)/i.test(contentType)) {
    return jsonError("Content-Type must be application/json.", 415);
  }

  const rawBody = await readBoundedBody(request);
  if (rawBody === null) {
    return jsonError("Request body is invalid or too large.", 413);
  }

  let body: unknown;
  try {
    body = JSON.parse(rawBody || "{}");
  } catch {
    return jsonError("Invalid JSON payload.", 422);
  }

  if (!isRecord(body)) {
    return jsonError("Invalid JSON payload.", 422);
  }
  if (Object.keys(body).some((key) => !["centerSlug", "slug", "oldSlug", "invalidateListing"].includes(key))) {
    return jsonError("Unexpected request field.", 422);
  }

  const centerSlug = body.centerSlug;
  const slug = body.slug;
  const oldSlug = body.oldSlug;
  const invalidateListing = body.invalidateListing;

  if (typeof centerSlug !== "string" || !SLUG_REGEX.test(centerSlug)) {
    return jsonError("Invalid centerSlug.", 422);
  }
  if (typeof slug !== "string" || !SLUG_REGEX.test(slug)) {
    return jsonError("Invalid course slug.", 422);
  }
  if (
    oldSlug !== undefined &&
    (typeof oldSlug !== "string" || !SLUG_REGEX.test(oldSlug))
  ) {
    return jsonError("Invalid oldSlug.", 422);
  }
  if (
    invalidateListing !== undefined &&
    typeof invalidateListing !== "boolean"
  ) {
    return jsonError("invalidateListing must be a boolean.", 422);
  }

  const authorization = await authorizeForCenter(centerSlug);
  if (authorization === "unauthenticated") {
    return jsonError("Sign in to revalidate course pages.", 401);
  }
  if (authorization === "forbidden") {
    return jsonError("You do not have permission to revalidate this center's course pages.", 403);
  }
  if (authorization === "unavailable") {
    return jsonError("Could not verify course administration access.", 503);
  }

  revalidateTag(`course:${centerSlug}:${slug}`, { expire: 0 });
  revalidatePath(`/courses/${encodeURIComponent(slug)}`);

  if (typeof oldSlug === "string" && oldSlug !== slug) {
    revalidateTag(`course:${centerSlug}:${oldSlug}`, { expire: 0 });
    revalidatePath(`/courses/${encodeURIComponent(oldSlug)}`);
  }

  if (invalidateListing === true) {
    revalidateTag("courses", { expire: 0 });
    revalidatePath("/courses");
  }

  return NextResponse.json(
    {
      revalidated: true,
      tags: [
        `course:${centerSlug}:${slug}`,
        ...(typeof oldSlug === "string" && oldSlug !== slug
          ? [`course:${centerSlug}:${oldSlug}`]
          : []),
        ...(invalidateListing === true ? ["courses"] : []),
      ],
    },
    { headers: { "Cache-Control": "no-store" } }
  );
}
