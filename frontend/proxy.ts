import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

/**
 * Next.js 16 Proxy (formerly middleware)
 *
 * Provides initial edge-level route protection for sensitive routes.
 * Full authorization is continuously enforced by the Laravel API backend.
 */

// Route prefixes requiring authentication
const PROTECTED_PREFIXES = [
  "/learn",
  "/student",
  "/teacher",
  "/admin",
  "/platform-admin",
];

// Cookie name used for JWT storage (HttpOnly cookie set by backend)
const AUTH_COOKIE_NAME = process.env.JWT_COOKIE_NAME || "jwt_token";

export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;

  const isProtected = PROTECTED_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`)
  );

  if (isProtected) {
    const token = request.cookies.get(AUTH_COOKIE_NAME)?.value;

    if (!token) {
      const loginUrl = new URL("/login", request.url);
      const targetPath = `${pathname}${search}`;
      loginUrl.searchParams.set("redirect", targetPath);

      return NextResponse.redirect(loginUrl);
    }
  }

  return NextResponse.next();
}

// Export default for full compatibility
export default proxy;

export const config = {
  matcher: [
    "/learn/:path*",
    "/student/:path*",
    "/teacher/:path*",
    "/admin/:path*",
    "/platform-admin/:path*",
  ],
};
