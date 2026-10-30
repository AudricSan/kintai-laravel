<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Exceptions\HttpException;
use App\Http\Middleware\RateLimiterMiddleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\TestCase;

/** Limiteur générique : 5 requêtes par 5 minutes, par IP et par route, succès compris. */
final class RateLimiterMiddlewareTest extends TestCase
{
    private RateLimiterMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new RateLimiterMiddleware(new \Illuminate\Cache\RateLimiter(new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore())));
    }

    protected function tearDown(): void
    {
    }

    private function request(string $ip, string $uri, ?string $routeName = null): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => $ip];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $req = Request::capture();
        if ($routeName !== null) {
            $req->attributes->set('route_name', $routeName);
        }
        return $req;
    }

    private function pass(Request $req): Response
    {
        return $this->middleware->handle($req, static fn(Request $r): Response => redirect('/ok'));
    }

    public function testAllowsTheFirstFiveRequestsThenBlocks(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(302, $this->pass($this->request('10.0.0.1', '/forgot-password'))->getStatusCode());
        }

        $this->expectException(HttpException::class);
        $this->pass($this->request('10.0.0.1', '/forgot-password'));
    }

    public function testBlockedResponseIsA429WithRetryAfter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pass($this->request('10.0.0.1', '/forgot-password'));
        }

        try {
            $this->pass($this->request('10.0.0.1', '/forgot-password'));
            $this->fail('429 attendu');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertGreaterThan(0, (int) ($e->getHeaders()['Retry-After'] ?? 0));
            $this->assertLessThanOrEqual(300, (int) $e->getHeaders()['Retry-After']);
        }
    }

    public function testDifferentIpsAreCountedSeparately(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pass($this->request('10.0.0.1', '/forgot-password'));
        }

        $this->assertSame(302, $this->pass($this->request('10.0.0.2', '/forgot-password'))->getStatusCode());
    }

    public function testDifferentRoutesAreCountedSeparately(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pass($this->request('10.0.0.1', '/forgot-password', 'password.forgot.post'));
        }

        $this->assertSame(302, $this->pass($this->request('10.0.0.1', '/support/report-issue', 'support.report_issue'))->getStatusCode());
    }

    public function testChangingTheResetTokenDoesNotDodgeTheLimit(): void
    {
        // /reset-password/{token} : l'URI change avec chaque jeton essayé, mais la route est la même.
        for ($i = 0; $i < 5; $i++) {
            $this->pass($this->request('10.0.0.1', "/reset-password/token-{$i}", 'password.reset.post'));
        }

        $this->expectException(HttpException::class);
        $this->pass($this->request('10.0.0.1', '/reset-password/yet-another-token', 'password.reset.post'));
    }
}
