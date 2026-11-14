<?php

namespace App\Services\Auth;

use Symfony\Component\HttpFoundation\Cookie;

class AuthCookieService
{
    public const DEFAULT_AUTH_COOKIE_NAME = 'jwt_token';

    public const DEFAULT_CSRF_COOKIE_NAME = 'XSRF-TOKEN';

    /**
     * Get the configured authentication cookie name.
     */
    public function getAuthCookieName(): string
    {
        return (string) env('JWT_COOKIE_NAME', self::DEFAULT_AUTH_COOKIE_NAME);
    }

    /**
     * Get the configured CSRF cookie name.
     */
    public function getCsrfCookieName(): string
    {
        return self::DEFAULT_CSRF_COOKIE_NAME;
    }

    /**
     * Create an HttpOnly, secure authentication cookie containing the JWT.
     */
    public function createAuthCookie(string $token): Cookie
    {
        $minutes = (int) config('jwt.ttl', 15);
        $expires = time() + ($minutes * 60);
        $secure = (bool) env('JWT_COOKIE_SECURE', app()->isProduction());
        $sameSite = (string) env('JWT_COOKIE_SAMESITE', 'lax');
        $domain = env('SESSION_DOMAIN');

        return Cookie::create(
            name: $this->getAuthCookieName(),
            value: $token,
            expire: $expires,
            path: '/',
            domain: $domain,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $sameSite
        );
    }

    /**
     * Create an expired authentication cookie to clear the token in the browser.
     */
    public function createForgetCookie(): Cookie
    {
        $domain = env('SESSION_DOMAIN');

        return Cookie::create(
            name: $this->getAuthCookieName(),
            value: '',
            expire: time() - 3600,
            path: '/',
            domain: $domain,
            secure: (bool) env('JWT_COOKIE_SECURE', app()->isProduction()),
            httpOnly: true,
            raw: false,
            sameSite: (string) env('JWT_COOKIE_SAMESITE', 'lax')
        );
    }

    /**
     * Create a readable CSRF cookie for Double-Submit protection.
     */
    public function createCsrfCookie(string $token): Cookie
    {
        $minutes = (int) config('session.lifetime', 120);
        $expires = time() + ($minutes * 60);
        $secure = (bool) env('JWT_COOKIE_SECURE', app()->isProduction());
        $sameSite = (string) env('JWT_COOKIE_SAMESITE', 'lax');
        $domain = env('SESSION_DOMAIN');

        return Cookie::create(
            name: $this->getCsrfCookieName(),
            value: $token,
            expire: $expires,
            path: '/',
            domain: $domain,
            secure: $secure,
            httpOnly: false, // Must be readable by JavaScript to attach to mutation request headers
            raw: false,
            sameSite: $sameSite
        );
    }

    /**
     * Clear the CSRF cookie.
     */
    public function createClearCsrfCookie(): Cookie
    {
        $domain = env('SESSION_DOMAIN');

        return Cookie::create(
            name: $this->getCsrfCookieName(),
            value: '',
            expire: time() - 3600,
            path: '/',
            domain: $domain,
            secure: (bool) env('JWT_COOKIE_SECURE', app()->isProduction()),
            httpOnly: false,
            raw: false,
            sameSite: (string) env('JWT_COOKIE_SAMESITE', 'lax')
        );
    }
}
