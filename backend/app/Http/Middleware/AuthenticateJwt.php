<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AuthCookieService;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwt
{
    public function __construct(
        protected AuthCookieService $cookieService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            $cookieName = $this->cookieService->getAuthCookieName();
            $token = $request->cookie($cookieName);
            if (! $token && $request->headers->has('cookie')) {
                // Also parse from cookie header if not parsed
                $cookies = [];
                parse_str(str_replace('; ', '&', (string) $request->header('cookie')), $cookies);
                $token = $cookies[$cookieName] ?? null;
            }
        }

        if (! $token) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            JWTAuth::setToken($token);

            /** @var User|null $user */
            $user = JWTAuth::authenticate();

            if (! $user) {
                return response()->json([
                    'message' => 'User not found.',
                ], 401);
            }

            // Verify token version against user's current token version in database.
            $payload = JWTAuth::getPayload();
            $tokenVersion = $payload->get('ver');

            if ($tokenVersion !== null && (int) $tokenVersion !== (int) $user->token_version) {
                return response()->json([
                    'message' => 'Token has been revoked.',
                ], 401);
            }

            // Verify account status.
            if (! $user->isActive()) {
                return response()->json([
                    'message' => 'Your account is disabled.',
                ], 403);
            }

            // Bind user to request and guards.
            $request->setUserResolver(fn () => $user);
            auth('api')->setUser($user);
            auth()->setUser($user);

        } catch (TokenExpiredException $e) {
            return response()->json([
                'message' => 'Token has expired.',
            ], 401);
        } catch (TokenBlacklistedException $e) {
            return response()->json([
                'message' => 'Token is blacklisted.',
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'message' => 'Invalid token.',
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
