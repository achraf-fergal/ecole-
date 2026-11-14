<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthCookieService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiCsrf
{
    public function __construct(
        protected AuthCookieService $cookieService
    ) {}

    /**
     * Handle an incoming request.
     * Enforces Double-Submit CSRF protection on browser cookie-authenticated requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Safe HTTP methods do not mutate state.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $authCookie = $this->getCookie($request, $this->cookieService->getAuthCookieName());
        $csrfCookie = $this->getCookie($request, $this->cookieService->getCsrfCookieName());

        // Stateless API / mobile clients with Bearer tokens (or requests without ambient cookies)
        // are not vulnerable to ambient browser cookie CSRF.
        if (! $authCookie && ! $csrfCookie) {
            return $next($request);
        }

        // When browser cookies are present, require matching CSRF header.
        $headerToken = $request->header('X-XSRF-TOKEN') ?: $request->header('X-CSRF-TOKEN');

        if (! $csrfCookie || ! $headerToken || ! hash_equals((string) $csrfCookie, (string) $headerToken)) {
            return response()->json([
                'message' => 'CSRF token mismatch.',
            ], 419);
        }

        return $next($request);
    }

    /**
     * Retrieve cookie from request or raw cookie header.
     */
    protected function getCookie(Request $request, string $name): ?string
    {
        $value = $request->cookie($name);

        if ($value) {
            return (string) $value;
        }

        if ($request->headers->has('cookie')) {
            $cookies = [];
            parse_str(str_replace('; ', '&', (string) $request->header('cookie')), $cookies);

            return isset($cookies[$name]) ? (string) $cookies[$name] : null;
        }

        return null;
    }
}
