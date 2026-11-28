import crypto from "node:crypto";
import { revalidatePath, revalidateTag } from "next/cache";
import { cookies } from "next/headers";
import { NextResponse } from "next/server";

const SLUG_REGEX = /^[a-z0-9][a-z0-9-]{0,148}[a-z0-9]?$/i;
const RATE_LIMIT_WINDOW_MS = 60_000;
const RATE_LIMIT_MAX_REQUESTS = 30;
const rateLimitStore = new Map<string, number[]>();

function isRateLimited(key: string): boolean {
  const now = Date.now();
  const timestamps = (rateLimitStore.get(key) ?? []).filter(
    (ts) => now - ts < RATE_LIMIT_WINDOW_MS
  );
  if (timestamps.length >= RATE_LIMIT_MAX_REQUESTS) {
    rateLimitStore.set(key, timestamps);
    return true;
  }
  timestamps.push(now);
  rateLimitStore.set(key, timestamps);
  return false;
}

function verifyHmacSignature(
  rawBody: string,
  signatureHeader: string | null,
  timestampHeader: string | null
): boolean {
  const secret = process.env.REVALIDATION_SECRET;
  if (!secret || !signatureHeader || !timestampHeader) {
    return false;
  }

  const ts = Number(timestampHeader);
  if (!Number.isFinite(ts) || Math.abs(Date.now() - ts * 1000) > 300_000) {
    return false;
  }

  const expected = crypto
    .createHmac("sha256", secret)
    .update(`${timestampHeader}.${rawBody}`)
    .digest("hex");

  const sigBuffer = Buffer.from(signatureHeader, "utf8");
  const expectedBuffer = Buffer.from(expected, "utf8");
  if (sigBuffer.length !== expectedBuffer.length) {
    return false;
  }

  return crypto.timingSafeEqual(sigBuffer, expectedBuffer);
}

async function verifyAdminCookie(): Promise<boolean> {
  const apiBase = process.env.LARAVEL_API_URL;
  if (!apiBase) return false;

  const cookieName = process.env.JWT_COOKIE_NAME || "jwt_token";
  const cookieStore = await cookies();
  const token = cookieStore.get(cookieName)?.value;
  if (!token) return false;

  try {
    const res = await fetch(`${apiBase.replace(/\/$/, "")}/api/me`, {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
      },
      cache: "no-store",
    });
    if (!res.ok) return false;
    const payload = (await res.json()) as {
      data?: { is_platform_admin?: boolean; roles?: unknown[] };
      is_platform_admin?: boolean;
    };
    const user = payload.data ?? payload;
    return Boolean(user?.is_platform_admin || (Array.isArray(user?.roles) && user.roles.length > 0));
  } catch {
    return false;
  }
}

export async function POST(request: Request) {
  const clientIp =
    request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() || "local";
  if (isRateLimited(clientIp)) {
    return NextResponse.json({ error: "Too many revalidation requests." }, { status: 429 });
  }

  const rawBody = await request.text();
  const hasValidHmac = verifyHmacSignature(
    rawBody,
    request.headers.get("x-revalidate-signature"),
    request.headers.get("x-revalidate-timestamp")
  );
  const hasValidCookie = hasValidHmac ? false : await verifyAdminCookie();

  if (!hasValidHmac && !hasValidCookie) {
    return NextResponse.json({ error: "Unauthorized revalidation request." }, { status: 401 });
  }

  let body: {
    centerSlug?: unknown;
    slug?: unknown;
    oldSlug?: unknown;
    invalidateListing?: unknown;
  };
  try {
    body = JSON.parse(rawBody || "{}") as typeof body;
  } catch {
    return NextResponse.json({ error: "Invalid JSON payload." }, { status: 422 });
  }

  const centerSlug =
    typeof body.centerSlug === "string" && body.centerSlug.trim()
      ? body.centerSlug.trim()
      : process.env.CENTER_SLUG || "";
  const slug = typeof body.slug === "string" ? body.slug.trim() : "";
  const oldSlug = typeof body.oldSlug === "string" ? body.oldSlug.trim() : "";
  const invalidateListing = Boolean(body.invalidateListing);

  if (!centerSlug || !SLUG_REGEX.test(centerSlug)) {
    return NextResponse.json({ error: "Invalid centerSlug." }, { status: 422 });
  }
  if (!slug || !SLUG_REGEX.test(slug)) {
    return NextResponse.json({ error: "Invalid course slug." }, { status: 422 });
  }
  if (oldSlug && !SLUG_REGEX.test(oldSlug)) {
    return NextResponse.json({ error: "Invalid oldSlug." }, { status: 422 });
  }

  // Targeted per-course tag invalidation
  revalidateTag(`course:${centerSlug}:${slug}`, "max");
  revalidatePath(`/courses/${encodeURIComponent(slug)}`);

  if (oldSlug && oldSlug !== slug) {
    revalidateTag(`course:${centerSlug}:${oldSlug}`, "max");
    revalidatePath(`/courses/${encodeURIComponent(oldSlug)}`);
  }

  if (invalidateListing) {
    revalidateTag("courses", "max");
    revalidatePath("/courses");
  }

  return NextResponse.json({
    revalidated: true,
    tags: [
      `course:${centerSlug}:${slug}`,
      ...(oldSlug && oldSlug !== slug ? [`course:${centerSlug}:${oldSlug}`] : []),
      ...(invalidateListing ? ["courses"] : []),
    ],
  });
}
