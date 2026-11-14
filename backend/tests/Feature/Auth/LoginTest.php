<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AuthCookieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('StrongP@ssw0rd!123'),
        ]);

        $response = $this->postJson(route('api.auth.login'), [
            'email' => 'jane@example.com',
            'password' => 'StrongP@ssw0rd!123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'message',
            'user' => ['id', 'name', 'email', 'status', 'email_verified', 'is_platform_admin', 'centers'],
            'email_verified',
        ]);

        $cookieService = app(AuthCookieService::class);
        $cookieName = $cookieService->getAuthCookieName();

        $response->assertCookie($cookieName);

        $cookie = null;
        foreach ($response->headers->getCookies() as $c) {
            if ($c->getName() === $cookieName) {
                $cookie = $c;
                break;
            }
        }

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNotEmpty($cookie->getValue());
    }

    public function test_login_rejects_invalid_password_with_generic_message(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('StrongP@ssw0rd!123'),
        ]);

        $response = $this->postJson(route('api.auth.login'), [
            'email' => 'jane@example.com',
            'password' => 'WrongPassword!999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertStringContainsString('credentials do not match', $response->json('errors.email.0'));
    }

    public function test_login_rejects_nonexistent_email_with_identical_generic_message(): void
    {
        $response = $this->postJson(route('api.auth.login'), [
            'email' => 'nonexistent@example.com',
            'password' => 'WrongPassword!999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertStringContainsString('credentials do not match', $response->json('errors.email.0'));
    }

    public function test_unverified_account_can_login_with_verification_flag_false(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => Hash::make('StrongP@ssw0rd!123'),
        ]);

        $response = $this->postJson(route('api.auth.login'), [
            'email' => 'unverified@example.com',
            'password' => 'StrongP@ssw0rd!123',
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('email_verified'));
        $this->assertFalse($response->json('user.email_verified'));
    }

    public function test_disabled_account_cannot_login(): void
    {
        $user = User::factory()->disabled()->create([
            'email' => 'disabled@example.com',
            'password' => Hash::make('StrongP@ssw0rd!123'),
        ]);

        $response = $this->postJson(route('api.auth.login'), [
            'email' => 'disabled@example.com',
            'password' => 'StrongP@ssw0rd!123',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Your account is disabled.',
        ]);
    }

    public function test_login_rate_limiting_triggers_after_repeated_failures(): void
    {
        $user = User::factory()->create([
            'email' => 'ratelimit@example.com',
            'password' => Hash::make('StrongP@ssw0rd!123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('api.auth.login'), [
                'email' => 'ratelimit@example.com',
                'password' => 'WrongP@ssword!1',
            ]);
        }

        $response = $this->postJson(route('api.auth.login'), [
            'email' => 'ratelimit@example.com',
            'password' => 'WrongP@ssword!1',
        ]);

        $response->assertStatus(429);
    }
}
