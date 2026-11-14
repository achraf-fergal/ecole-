<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_notification_for_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'recover@example.com',
        ]);

        $response = $this->postJson(route('api.auth.forgot-password'), [
            'email' => 'recover@example.com',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'If your email address is registered, you will receive a password reset link shortly.',
        ]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_returns_identical_generic_response_for_nonexistent_email(): void
    {
        Notification::fake();

        $response = $this->postJson(route('api.auth.forgot-password'), [
            'email' => 'nobody@example.com',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'If your email address is registered, you will receive a password reset link shortly.',
        ]);

        Notification::assertNothingSent();
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'resetme@example.com',
            'password' => Hash::make('OldP@ssword!123'),
            'token_version' => 1,
        ]);

        $oldToken = auth('api')->claims(['ver' => 1])->login($user);

        // Generate native password broker token.
        $resetToken = Password::broker()->createToken($user);

        $response = $this->postJson(route('api.auth.reset-password'), [
            'token' => $resetToken,
            'email' => 'resetme@example.com',
            'password' => 'NewStrongP@ssw0rd!456',
            'password_confirmation' => 'NewStrongP@ssw0rd!456',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Password reset successfully. Please log in with your new password.',
        ]);

        $user->refresh();

        // Verify password changed.
        $this->assertTrue(Hash::check('NewStrongP@ssw0rd!456', $user->password));

        // Verify token_version incremented.
        $this->assertSame(2, $user->token_version);

        // Verify old JWT token issued before reset is now rejected.
        $oldTokenResponse = $this->withHeader('Authorization', "Bearer {$oldToken}")
            ->getJson(route('api.auth.me'));

        $oldTokenResponse->assertStatus(401);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'resetme@example.com',
        ]);

        $response = $this->postJson(route('api.auth.reset-password'), [
            'token' => 'invalid-token-string',
            'email' => 'resetme@example.com',
            'password' => 'NewStrongP@ssw0rd!456',
            'password_confirmation' => 'NewStrongP@ssw0rd!456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_rejects_expired_token(): void
    {
        $user = User::factory()->create([
            'email' => 'resetme@example.com',
        ]);

        // Create token in past.
        Carbon::setTestNow(now()->subMinutes(120));
        $resetToken = Password::broker()->createToken($user);

        Carbon::setTestNow(); // Reset time to present (token expired after 60 min)

        $response = $this->postJson(route('api.auth.reset-password'), [
            'token' => $resetToken,
            'email' => 'resetme@example.com',
            'password' => 'NewStrongP@ssw0rd!456',
            'password_confirmation' => 'NewStrongP@ssw0rd!456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create([
            'email' => 'resetme@example.com',
        ]);

        $resetToken = Password::broker()->createToken($user);

        // First reset succeeds.
        $firstResponse = $this->postJson(route('api.auth.reset-password'), [
            'token' => $resetToken,
            'email' => 'resetme@example.com',
            'password' => 'NewStrongP@ssw0rd!456',
            'password_confirmation' => 'NewStrongP@ssw0rd!456',
        ]);

        $firstResponse->assertOk();

        // Second reset with same token is rejected.
        $secondResponse = $this->postJson(route('api.auth.reset-password'), [
            'token' => $resetToken,
            'email' => 'resetme@example.com',
            'password' => 'AnotherStrongP@ssw0rd!789',
            'password_confirmation' => 'AnotherStrongP@ssw0rd!789',
        ]);

        $secondResponse->assertStatus(422);
        $secondResponse->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_enforces_strong_password_policy(): void
    {
        $user = User::factory()->create([
            'email' => 'resetme@example.com',
        ]);

        $resetToken = Password::broker()->createToken($user);

        $response = $this->postJson(route('api.auth.reset-password'), [
            'token' => $resetToken,
            'email' => 'resetme@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }
}
