<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    /** Sanctum treats requests from a stateful domain as SPA (session) requests. */
    private function spa(): static
    {
        return $this->withHeaders(['Referer' => 'http://localhost', 'Origin' => 'http://localhost']);
    }

    public function test_user_can_log_in_and_fetch_profile(): void
    {
        $user = User::factory()->create(['email' => 'rbel@example.test', 'password' => 'secret-pass1']);

        $this->spa()->postJson('/api/login', ['email' => 'rbel@example.test', 'password' => 'secret-pass1', 'remember' => true])
            ->assertOk()
            ->assertJsonPath('data.email', 'rbel@example.test')
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user);
        $this->spa()->getJson('/api/user')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertTrue(AuditLog::where('action', 'login')->where('record_id', $user->id)->exists());
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create(['email' => 'rbel@example.test']);

        $this->spa()->postJson('/api/login', ['email' => 'rbel@example.test', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_login_requires_valid_input(): void
    {
        $this->spa()->postJson('/api/login', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'rbel@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/login', ['email' => 'rbel@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->spa()->postJson('/api/login', ['email' => 'rbel@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_protected_routes_require_authentication(): void
    {
        foreach (['/api/dashboard', '/api/clients', '/api/policies', '/api/analytics', '/api/appointments', '/api/goals', '/api/email-templates', '/api/user'] as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass1']);
        $this->spa()->postJson('/api/login', ['email' => $user->email, 'password' => 'secret-pass1'])->assertOk();

        $this->spa()->postJson('/api/logout')->assertOk();

        $this->assertGuest('web');
    }

    public function test_profile_update_and_password_change(): void
    {
        $user = $this->signIn();

        $this->putJson('/api/profile', ['name' => 'Updated Name', 'email' => 'new@example.test', 'job_title' => 'Unit Manager'])
            ->assertOk()->assertJsonPath('data.name', 'Updated Name');

        $this->putJson('/api/profile/password', ['current_password' => 'wrong', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson('/api/profile/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson('/api/profile/password', ['current_password' => 'password', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertOk();

        $this->assertTrue(Hash::check('NewPass123', $user->fresh()->password));
    }
}
