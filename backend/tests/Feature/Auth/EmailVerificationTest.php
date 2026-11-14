<?php

namespace Tests\Feature\Auth;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_email_is_sent_after_registration(): void
    {
        Notification::fake();

        $this->postJson(route('api.auth.register'), [
            'name' => 'Verify User',
            'email' => 'verify.user@example.com',
            'password' => 'StrongP@ssw0rd!123',
            'password_confirmation' => 'StrongP@ssw0rd!123',
            'terms' => true,
        ]);

        $user = User::where('email', 'verify.user@example.com')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_valid_signed_verification_link_verifies_email(): void
    {
        Event::fake([Verified::class]);

        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $response = $this->getJson($verificationUrl);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Email verified successfully.',
        ]);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_verification_rejects_invalid_signature(): void
    {
        $user = User::factory()->unverified()->create();

        $invalidUrl = route('verification.verify', [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]).'?signature=tampered_signature_123';

        $response = $this->getJson($invalidUrl);

        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_rejects_expired_link(): void
    {
        $user = User::factory()->unverified()->create();

        $expiredUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->subMinutes(10), // Expired 10 minutes ago
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $response = $this->getJson($expiredUrl);

        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_is_idempotent_for_already_verified_account(): void
    {
        $user = User::factory()->create(); // already verified

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $response = $this->getJson($verificationUrl);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Email already verified.',
        ]);
    }

    public function test_authenticated_unverified_user_can_resend_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.auth.email.verification-notification'));

        $response->assertOk();
        $response->assertJson([
            'message' => 'Verification link sent.',
        ]);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_user_cannot_access_tenant_verified_routes(): void
    {
        $center = Center::factory()->create();
        $role = Role::create(['key' => 'center_admin', 'name' => 'Center Admin']);

        $user = User::factory()->unverified()->create();

        $membership = CenterUser::create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach($role->id);

        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.centers.context', ['center' => $center->id]));

        $response->assertStatus(403);
        $response->assertJson([
            'code' => 'EMAIL_NOT_VERIFIED',
        ]);
    }
}
