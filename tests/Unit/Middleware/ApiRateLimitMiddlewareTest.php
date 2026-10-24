<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Middleware;

use kintai\Core\Container;
use kintai\Core\Exceptions\HttpException;
use kintai\Core\Middleware\ApiRateLimitMiddleware;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Security\AttemptCounter;
use kintai\Core\Services\AppSettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 13/10/2026 (M9) : l'API n'avait aucune limitation de débit hors connexion. Limite par jeton
 * (repli par IP), réglable par l'Owner, plafond par IP pour les rafales de jetons différents.
 */
final class ApiRateLimitMiddlewareTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-api-ratelimit-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        $_SERVER = [];
    }

    private function middleware(?string $setting): ApiRateLimitMiddleware
    {
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn($setting === null ? [] : [ApiRateLimitMiddleware::SETTING_KEY => $setting]);

        $container = new Container();
        $container->instance(AppSettingsService::class, new AppSettingsService($repo));

        return new ApiRateLimitMiddleware($container, new AttemptCounter($this->dir));
    }

    private function request(string $uri, ?string $token = null, string $ip = '10.0.0.1'): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => $ip];
        if ($token !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        return new Request();
    }

    private function pass(ApiRateLimitMiddleware $mw, Request $req): Response
    {
        return $mw->handle($req, static fn(Request $r): Response => Response::json(['ok' => true]));
    }

    public function testBlocksATokenAboveTheConfiguredLimitWithRetryAfter(): void
    {
        $mw = $this->middleware('3');
        for ($i = 0; $i < 3; $i++) {
            $response = $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));
            $this->assertSame(200, $response->status());
        }
        $this->assertSame('0', $response->headers()['X-RateLimit-Remaining']);

        try {
            $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));
            $this->fail('La quatrième requête aurait dû être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertGreaterThan(0, (int) $e->headers['Retry-After']);
        }
    }

    public function testEachTokenHasItsOwnBudget(): void
    {
        $mw = $this->middleware('2');
        $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));
        $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));

        $response = $this->pass($mw, $this->request('/api/v1/shifts', 'token-b'));

        $this->assertSame(200, $response->status());
        $this->assertSame('2', $response->headers()['X-RateLimit-Limit']);
        $this->assertSame('1', $response->headers()['X-RateLimit-Remaining']);
    }

    public function testWithoutTokenTheLimitAppliesPerIp(): void
    {
        $mw = $this->middleware('1');
        $this->pass($mw, $this->request('/api/v1/auth/ping', null, '10.0.0.1'));
        $this->assertSame(200, $this->pass($mw, $this->request('/api/v1/auth/ping', null, '10.0.0.2'))->status());

        $this->expectException(HttpException::class);
        $this->pass($mw, $this->request('/api/v1/auth/ping', null, '10.0.0.1'));
    }

    /** Un script qui change de jeton à chaque requête finit bloqué par le plafond de l'IP. */
    public function testRotatingTokensHitThePerIpCeiling(): void
    {
        $mw = $this->middleware('1');
        for ($i = 0; $i < ApiRateLimitMiddleware::IP_FACTOR; $i++) {
            $this->pass($mw, $this->request('/api/v1/shifts', 'token-' . $i));
        }

        $this->expectException(HttpException::class);
        $this->pass($mw, $this->request('/api/v1/shifts', 'token-new'));
    }

    public function testZeroDisablesTheLimit(): void
    {
        $mw = $this->middleware('0');
        for ($i = 0; $i < 20; $i++) {
            $response = $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));
        }

        $this->assertSame(200, $response->status());
        $this->assertArrayNotHasKey('X-RateLimit-Limit', $response->headers());
    }

    public function testDefaultLimitAppliesWhenNotConfigured(): void
    {
        $response = $this->pass($this->middleware(null), $this->request('/api/v1/shifts', 'token-a'));

        $this->assertSame((string) ApiRateLimitMiddleware::DEFAULT_PER_MINUTE, $response->headers()['X-RateLimit-Limit']);
    }

    public function testWebRoutesAreNotLimited(): void
    {
        $mw = $this->middleware('1');
        for ($i = 0; $i < 5; $i++) {
            $response = $this->pass($mw, $this->request('/admin/shifts'));
        }

        $this->assertSame(200, $response->status());
        $this->assertArrayNotHasKey('X-RateLimit-Limit', $response->headers());
    }

    public function testTheRawTokenNeverEndsUpInTheCounterFiles(): void
    {
        $this->pass($this->middleware('5'), $this->request('/api/v1/shifts', 'secret-token-value'));

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            $this->assertStringNotContainsString('secret-token-value', basename($file) . file_get_contents($file));
        }
        $this->addToAssertionCount(1);
    }
}
