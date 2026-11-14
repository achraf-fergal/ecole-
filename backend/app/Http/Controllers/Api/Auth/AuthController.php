<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Auth\AuthCookieService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AuthCookieService $cookieService
    ) {}

    /**
     * Issue CSRF cookie for SPA mutation requests.
     */
    public function csrfCookie(): JsonResponse
    {
        $token = Str::random(40);
        $cookie = $this->cookieService->createCsrfCookie($token);

        return response()->json([
            'csrf_token' => $token,
        ])->withCookie($cookie);
    }

    /**
     * Register a new user account.
     * Prevents client role escalation and enforces verification email delivery.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        // Strictly extract valid registration attributes only.
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
            'status' => User::STATUS_ACTIVE,
            'token_version' => 1,
        ]);

        // Send Laravel native email verification notification.
        event(new Registered($user));

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => false,
                'created_at' => $user->created_at?->toISOString(),
            ],
        ], 201);
    }

    /**
     * Authenticate user credentials and issue secure JWT cookie.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            $request->hitRateLimit();

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->isActive()) {
            $request->hitRateLimit();

            return response()->json([
                'message' => 'Your account is disabled.',
            ], 403);
        }

        $request->clearRateLimit();

        // Issue JWT token with current token version claim.
        $token = auth('api')
            ->claims(['ver' => (int) $user->token_version])
            ->login($user);

        $authCookie = $this->cookieService->createAuthCookie($token);
        $csrfToken = Str::random(40);
        $csrfCookie = $this->cookieService->createCsrfCookie($csrfToken);

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->formatUserResponse($user),
            'email_verified' => $user->hasVerifiedEmail(),
        ])
            ->withCookie($authCookie)
            ->withCookie($csrfCookie);
    }

    /**
     * Invalidate current JWT token and clear authentication cookie.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            auth('api')->logout();
        } catch (\Throwable) {
            // Proceed with cookie clearance regardless of blacklist errors.
        }

        $clearCookie = $this->cookieService->createForgetCookie();

        return response()->json([
            'message' => 'Successfully logged out.',
        ])->withCookie($clearCookie);
    }

    /**
     * Refresh JWT token with automatic rotation and blacklist invalidation.
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $newToken = auth('api')->refresh();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to refresh token.',
            ], 401);
        }

        $authCookie = $this->cookieService->createAuthCookie($newToken);

        return response()->json([
            'message' => 'Token refreshed successfully.',
        ])->withCookie($authCookie);
    }

    /**
     * Revoke all active sessions and tokens across all devices.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Increment token version in database to instantly revoke all existing tokens.
        $user->increment('token_version');

        try {
            auth('api')->logout();
        } catch (\Throwable) {
            // Ignore if current token is already invalid
        }

        $clearCookie = $this->cookieService->createForgetCookie();

        return response()->json([
            'message' => 'Successfully logged out from all devices.',
        ])->withCookie($clearCookie);
    }

    /**
     * Return authenticated user profile and tenant authorizations.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->formatUserResponse($user),
        ]);
    }

    /**
     * Format clean and safe user response without sensitive attributes.
     */
    protected function formatUserResponse(User $user): array
    {
        // Eager load memberships with center and roles.
        $memberships = $user->centerMemberships()
            ->with(['center', 'roles'])
            ->where('status', 'active')
            ->get();

        $centers = $memberships->map(function ($membership) {
            return [
                'id' => $membership->center->id,
                'name' => $membership->center->name,
                'slug' => $membership->center->slug,
                'status' => $membership->status,
                'roles' => $membership->roles->map(function ($role) {
                    return [
                        'key' => $role->key,
                        'name' => $role->name,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'email_verified' => $user->hasVerifiedEmail(),
            'is_platform_admin' => $user->isPlatformAdmin(),
            'centers' => $centers,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
