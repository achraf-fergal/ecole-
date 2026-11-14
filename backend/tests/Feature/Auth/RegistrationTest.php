<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_successfully(): void
    {
        Event::fake([Registered::class]);

        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'StrongP@ssw0rd!123',
            'password_confirmation' => 'StrongP@ssw0rd!123',
            'terms' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'message',
            'user' => ['id', 'name', 'email', 'email_verified', 'created_at'],
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john.doe@example.com',
            'status' => 'active',
            'token_version' => 1,
        ]);

        $createdUser = User::where('email', 'john.doe@example.com')->first();
        $this->assertSame('user', $createdUser->getPrimaryRole());
        $this->assertCount(0, $createdUser->centerMemberships);

        Event::assertDispatched(Registered::class);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'Duplicate User',
            'email' => 'existing@example.com',
            'password' => 'StrongP@ssw0rd!123',
            'password_confirmation' => 'StrongP@ssw0rd!123',
            'terms' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_rejects_case_insensitive_duplicate_email(): void
    {
        User::factory()->create(['email' => 'case.user@example.com']);

        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'Case User',
            'email' => 'CASE.USER@example.com',
            'password' => 'StrongP@ssw0rd!123',
            'password_confirmation' => 'StrongP@ssw0rd!123',
            'terms' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_rejects_invalid_email_format(): void
    {
        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'Invalid Email',
            'email' => 'not-an-email',
            'password' => 'StrongP@ssw0rd!123',
            'password_confirmation' => 'StrongP@ssw0rd!123',
            'terms' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_rejects_weak_password(): void
    {
        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'Weak Password',
            'email' => 'weak@example.com',
            'password' => 'short1!',
            'password_confirmation' => 'short1!',
            'terms' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_registration_rejects_password_confirmation_mismatch(): void
    {
        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'Mismatch Password',
            'email' => 'mismatch@example.com',
            'password' => 'StrongP@ssw0rd!123',
            'password_confirmation' => 'DifferentP@ssw0rd!456',
            'terms' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_registration_rejects_unaccepted_terms(): void
    {
        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'No Terms',
            'email' => 'noterms@example.com',
            'password' => 'StrongP@ssw0rd!123',
            'password_confirmation' => 'StrongP@ssw0rd!123',
            'terms' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['terms']);
    }

    public function test_registration_ignores_unauthorized_role_assignment_attempts(): void
    {
        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'Hacker Try',
            'email' => 'hacker@example.com',
            'password' => 'StrongP@ssw0rd!123',
            'password_confirmation' => 'StrongP@ssw0rd!123',
            'terms' => true,
            'is_admin' => true,
            'role' => 'super_admin',
            'role_id' => 1,
            'center_id' => 999,
        ]);

        $response->assertCreated();

        $user = User::where('email', 'hacker@example.com')->firstOrFail();

        $this->assertFalse($user->isPlatformAdmin());
        $this->assertCount(0, $user->centerMemberships);
    }
}
