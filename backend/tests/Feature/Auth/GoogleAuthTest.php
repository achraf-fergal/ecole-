<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_google_redirect_returns_authorization_url_for_json_requests(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirect->getTargetUrl')
            ->andReturn('https://accounts.google.com/o/oauth2/v2/auth?mock=true');

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->getJson('/api/auth/google/redirect');

        $response->assertOk();
        $response->assertJson([
            'url' => 'https://accounts.google.com/o/oauth2/v2/auth?mock=true',
        ]);
    }

    public function test_google_callback_creates_new_user_and_sets_auth_cookie(): void
    {
        $socialiteUser = new SocialiteUser;
        $socialiteUser->id = 'google-id-12345';
        $socialiteUser->name = 'Google Student';
        $socialiteUser->email = 'student.google@example.com';

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('http://localhost:3000/courses');
        $response->assertCookie('jwt_token');

        $this->assertDatabaseHas('users', [
            'email' => 'student.google@example.com',
            'google_id' => 'google-id-12345',
            'name' => 'Google Student',
            'status' => 'active',
        ]);

        $user = User::where('email', 'student.google@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);

        // Verify multi-tenant isolation: new user must not have any center memberships or roles
        $this->assertCount(0, $user->centers);
    }

    public function test_google_callback_links_existing_user_by_email(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
            'google_id' => null,
            'email_verified_at' => null,
        ]);

        $socialiteUser = new SocialiteUser;
        $socialiteUser->id = 'google-id-999';
        $socialiteUser->name = 'Existing User';
        $socialiteUser->email = 'existing@example.com';

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('http://localhost:3000/courses');
        $response->assertCookie('jwt_token');

        $existingUser->refresh();
        $this->assertSame('google-id-999', $existingUser->google_id);
        $this->assertNotNull($existingUser->email_verified_at);
    }

    public function test_google_callback_rejects_disabled_account(): void
    {
        $disabledUser = User::factory()->disabled()->create([
            'email' => 'blocked@example.com',
            'google_id' => 'google-id-blocked',
        ]);

        $socialiteUser = new SocialiteUser;
        $socialiteUser->id = 'google-id-blocked';
        $socialiteUser->name = 'Blocked User';
        $socialiteUser->email = 'blocked@example.com';

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('http://localhost:3000/login?error=account_disabled');
        $response->assertCookieMissing('jwt_token');
    }

    public function test_google_callback_handles_cancellation_or_error_query_parameter(): void
    {
        $response = $this->get('/api/auth/google/callback?error=access_denied');

        $response->assertRedirect('http://localhost:3000/login?error=google_auth_cancelled');
    }
}
