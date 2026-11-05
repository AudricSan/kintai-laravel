<?php

namespace Tests\Feature\Settings;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        foreach (["frame-ancestors 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
        // CSP de base : aucune restriction de scripts ni de styles (Livewire, Alpine et Flux en ont besoin).
        $this->assertStringNotContainsString('script-src', $csp);
        $this->assertStringNotContainsString('default-src', $csp);
    }

    public function test_web_pages_carry_the_headers(): void
    {
        $this->assertSecurityHeaders($this->get(route('login')));
    }

    public function test_redirects_and_error_pages_carry_the_headers(): void
    {
        $this->assertSecurityHeaders($this->get(route('dashboard')));
        $this->assertSecurityHeaders($this->get('/does-not-exist'));
    }

    public function test_the_api_carries_the_headers(): void
    {
        $this->assertSecurityHeaders($this->getJson('/api/v1/ping'));
        $this->assertSecurityHeaders($this->getJson('/api/v1/auth/me'));
    }

    public function test_the_ical_feed_carries_the_headers_and_keeps_its_own(): void
    {
        $response = $this->get('/ical/'.str_repeat('a', 64).'/1/shifts.ics');

        $response->assertNotFound();
        $this->assertSecurityHeaders($response);
    }

    public function test_the_service_worker_keeps_its_own_headers(): void
    {
        $response = $this->get(route('pwa.sw'));

        $response->assertOk();
        $response->assertHeader('Service-Worker-Allowed', '/');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));
        $this->assertSecurityHeaders($response);
    }

    public function test_a_header_set_by_the_response_is_not_overwritten(): void
    {
        Route::get('/_test/framed', fn () => response('x', 200, ['X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "default-src 'none'"]));

        $response = $this->get('/_test/framed');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->getJson('http://localhost/api/v1/ping')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);
        $this->getJson('https://localhost/api/v1/ping')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
