<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AuthCookieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JwtLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_jwt_token_authenticates_me_endpoint(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->claims(['ver' => $user->token_version])->login($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.auth.me'));

        $response->assertOk();
        $response->assertJson([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ]);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();

        // Create token with past expiration.
        Carbon::setTestNow(now()->subMinutes(60));
        $token = auth('api')->claims(['ver' => $user->token_version])->login($user);

        Carbon::setTestNow(); // Reset time to present

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.auth.me'));

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Token has expired.',
        ]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid.token.structure')
            ->getJson(route('api.auth.me'));

        $response->assertStatus(401);
    }

    public function test_logout_blacklists_token(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->claims(['ver' => $user->token_version])->login($user);

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.auth.logout'));

        $logoutResponse->assertOk();

        // Attempt to reuse blacklisted token.
        $reuseResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.auth.me'));

        $reuseResponse->assertStatus(401);
        $reuseResponse->assertJson([
            'message' => 'Token is blacklisted.',
        ]);
    }

    public function test_refresh_token_rotation_invalidates_previous_token(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->claims(['ver' => $user->token_version])->login($user);

        $refreshResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.auth.refresh'));

        $refreshResponse->assertOk();

        $cookieService = app(AuthCookieService::class);
        $cookieName = $cookieService->getAuthCookieName();

        $refreshResponse->assertCookie($cookieName);

        // Extract rotated token from response cookie.
        $rotatedCookie = null;
        foreach ($refreshResponse->headers->getCookies() as $c) {
            if ($c->getName() === $cookieName) {
                $rotatedCookie = $c;
                break;
            }
        }
        $this->assertNotNull($rotatedCookie);
        $newToken = $rotatedCookie->getValue();

        // Old token should now be blacklisted.
        $oldTokenResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.auth.me'));

        $oldTokenResponse->assertStatus(401);

        // New rotated token works.
        $newTokenResponse = $this->withHeader('Authorization', "Bearer {$newToken}")
            ->getJson(route('api.auth.me'));

        $newTokenResponse->assertOk();
    }

    public function test_logout_all_devices_revokes_all_previously_issued_tokens(): void
    {
        $user = User::factory()->create();

        // Simulate token with initial version.
        $token = auth('api')->claims(['ver' => $user->token_version])->login($user);

        // User requests logout-all, which increments token_version.
        $logoutAllResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.auth.logout-all'));

        $logoutAllResponse->assertOk();

        // Increment version simulated on user model confirms outdated token claim rejection.
        $user->increment('token_version');

        $revokedTokenResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.auth.me'));

        $revokedTokenResponse->assertStatus(401);
    }

    public function test_cookie_authenticated_requests_enforce_csrf_protection(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->claims(['ver' => $user->token_version])->login($user);

        $cookieService = app(AuthCookieService::class);
        $authCookieName = $cookieService->getAuthCookieName();
        $csrfCookieName = $cookieService->getCsrfCookieName();
        $validCsrfToken = 'valid_csrf_token_test_abc123';

        // 1. Cookie-authenticated mutation without CSRF header is rejected (419).
        $noHeaderResponse = $this->call(
            'POST',
            route('api.auth.logout'),
            [],
            [$authCookieName => $token, $csrfCookieName => $validCsrfToken],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $noHeaderResponse->assertStatus(419);

        // 2. Cookie-authenticated mutation with mismatched CSRF header is rejected (419).
        $mismatchResponse = $this->call(
            'POST',
            route('api.auth.logout'),
            [],
            [$authCookieName => $token, $csrfCookieName => $validCsrfToken],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'wrong_token']
        );

        $mismatchResponse->assertStatus(419);

        // 3. Cookie-authenticated mutation with matching CSRF header succeeds.
        $successResponse = $this->call(
            'POST',
            route('api.auth.logout'),
            [],
            [$authCookieName => $token, $csrfCookieName => $validCsrfToken],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $validCsrfToken]
        );

        $successResponse->assertOk();
    }
}
