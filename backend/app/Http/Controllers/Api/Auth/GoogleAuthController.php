<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthCookieService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class GoogleAuthController extends Controller
{
    public function __construct(
        protected AuthCookieService $cookieService
    ) {}

    /**
     * Redirect the user to the Google OAuth consent screen or return the authorization URL.
     */
    public function redirect(Request $request): JsonResponse|RedirectResponse
    {
        $targetUrl = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();

        if ($request->wantsJson()) {
            return response()->json([
                'url' => $targetUrl,
            ]);
        }

        return redirect()->away($targetUrl);
    }

    /**
     * Handle the callback returned from Google OAuth.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = (string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        $loginUrl = rtrim($frontendUrl, '/').'/login';

        if ($request->has('error')) {
            return redirect()->to($loginUrl.'?error=google_auth_cancelled');
        }

        try {
            /** @var \Laravel\Socialite\Two\User $googleUser */
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Exception $e) {
            return redirect()->to($loginUrl.'?error=google_auth_failed');
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));
        $googleId = (string) $googleUser->getId();
        $name = trim((string) $googleUser->getName()) ?: 'Google User';

        if (empty($email) || empty($googleId)) {
            return redirect()->to($loginUrl.'?error=google_email_missing');
        }

        // Find by google_id or match by registered email
        $user = User::where('google_id', $googleId)->first();

        if (! $user) {
            $user = User::where('email', $email)->first();

            if ($user) {
                // Link Google account to existing user
                $user->google_id = $googleId;
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }
                $user->save();
            } else {
                // Register new user without assigning any tenant roles or centers (multi-tenant safety)
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => null,
                    'status' => User::STATUS_ACTIVE,
                    'token_version' => 1,
                ]);

                $user->markEmailAsVerified();
            }
        }

        if (! $user->isActive()) {
            return redirect()->to($loginUrl.'?error=account_disabled');
        }

        // Issue JWT token and create secure HttpOnly cookie
        /** @var JWTGuard $authGuard */
        $authGuard = auth('api');
        $token = $authGuard->login($user);
        $cookie = $this->cookieService->createAuthCookie($token);

        $redirectPath = rtrim($frontendUrl, '/').'/courses';

        return redirect()->to($redirectPath)->withCookie($cookie);
    }
}
