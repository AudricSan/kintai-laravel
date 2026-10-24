<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Middleware;

use kintai\Core\Exceptions\HttpException;
use kintai\Core\Middleware\RateLimiterMiddleware;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Security\AttemptCounter;
use PHPUnit\Framework\TestCase;

/** Limiteur générique : 5 requêtes par 5 minutes, par IP et par route, succès compris. */
final class RateLimiterMiddlewareTest extends TestCase
{
    private string $dir;
    private RateLimiterMiddleware $middleware;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-ratelimit-' . bin2hex(random_bytes(4));
        $this->middleware = new RateLimiterMiddleware(new AttemptCounter($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function request(string $ip, string $uri, ?string $routeName = null): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => $ip];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $req = new Request();
        if ($routeName !== null) {
            $req->setAttribute('route_name', $routeName);
        }
        return $req;
    }

    private function pass(Request $req): Response
    {
        return $this->middleware->handle($req, static fn(Request $r): Response => Response::redirect('/ok'));
    }

    public function testAllowsTheFirstFiveRequestsThenBlocks(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(302, $this->pass($this->request('10.0.0.1', '/forgot-password'))->status());
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
            $this->assertSame(429, $e->statusCode);
            $this->assertGreaterThan(0, (int) ($e->headers['Retry-After'] ?? 0));
            $this->assertLessThanOrEqual(300, (int) $e->headers['Retry-After']);
        }
    }

    public function testDifferentIpsAreCountedSeparately(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pass($this->request('10.0.0.1', '/forgot-password'));
        }

        $this->assertSame(302, $this->pass($this->request('10.0.0.2', '/forgot-password'))->status());
    }

    public function testDifferentRoutesAreCountedSeparately(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pass($this->request('10.0.0.1', '/forgot-password', 'password.forgot.post'));
        }

        $this->assertSame(302, $this->pass($this->request('10.0.0.1', '/support/report-issue', 'support.report_issue'))->status());
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
