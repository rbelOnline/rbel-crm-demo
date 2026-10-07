<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** CSRF check that also runs under PHPUnit (Laravel's own one skips itself in tests). */
class StrictCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

/**
 * Every state-changing API request from the SPA must carry the XSRF token.
 * Sanctum applies the CSRF check to first-party (stateful) requests, so these
 * tests send the SPA's Origin and swap in a CSRF middleware that isn't skipped in tests.
 */
class CsrfTest extends TestCase
{
    private const ORIGIN = 'http://localhost';

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.middleware.validate_csrf_token' => StrictCsrfToken::class]);
    }

    private function spa(array $headers = []): static
    {
        return $this->withHeaders(['Origin' => self::ORIGIN, 'Referer' => self::ORIGIN.'/', 'Accept' => 'application/json'] + $headers);
    }

    public function test_every_api_write_route_rejects_requests_without_a_csrf_token(): void
    {
        $this->signIn('admin');
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $methods = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);
            if (! $methods || ! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

            foreach ($methods as $method) {
                $this->spa()->json($method, $uri)->assertStatus(419, "{$method} {$uri} accepted a request without a CSRF token.");
                $checked++;
            }
        }

        $this->assertGreaterThan(30, $checked);
    }

    public function test_login_needs_the_token_and_a_valid_token_is_accepted(): void
    {
        $this->spa()->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertStatus(419);
        $this->spa(['X-XSRF-TOKEN' => 'forged'])->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertStatus(419);

        // With the session's token (as the SPA sends it), the request reaches the controller.
        $this->withSession(['_token' => 'spa-token'])->spa(['X-CSRF-TOKEN' => 'spa-token'])
            ->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertStatus(422);
    }

    public function test_multipart_uploads_with_method_spoofing_need_the_token(): void
    {
        $this->signIn('advisor');

        $this->spa()->post('/api/document-templates/1', ['_method' => 'PUT', 'name' => 'X'])->assertStatus(419);
        $this->spa()->post('/api/policies/1/documents/1/pdf', ['_method' => 'PUT', 'fields' => '[]'])->assertStatus(419);
    }

    public function test_reads_do_not_need_the_token(): void
    {
        $this->signIn('advisor');

        $this->spa()->getJson('/api/user')->assertOk();
    }
}
