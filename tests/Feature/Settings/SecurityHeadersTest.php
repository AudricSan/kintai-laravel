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
        // Scripts : même origine + nonce par requête ; styles non restreints (style="" en ligne).
        $this->assertMatchesRegularExpression("/script-src 'self' 'unsafe-eval' 'nonce-[A-Za-z0-9]{40}'/", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString('style-src', $csp);
        $this->assertStringNotContainsString('default-src', $csp);
    }

    public function test_every_script_of_a_page_carries_the_nonce_of_the_policy(): void
    {
        // Livewire garde en mémoire (statique) le HTML des `@assets` déjà rendus, donc avec le nonce d'un test précédent.
        \Livewire\trigger('flush-state');

        $response = $this->get(route('login'));

        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $found);
        $this->assertNotEmpty($found);

        $html = $response->getContent();
        preg_match_all('/<script\b[^>]*>/i', $html, $tags);
        $this->assertNotEmpty($tags[0]);
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$found[1].'"', $tag, "Script sans nonce : {$tag}");
        }
        $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|load)=/i', $html);
    }

    public function test_the_nonce_changes_on_every_request(): void
    {
        $first = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');
        $second = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
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
