<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Illuminate\Container\Container;
use App\Exceptions\HttpException;
use App\Http\Middleware\ApiRateLimitMiddleware;
use App\Repositories\AppSettingsRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AppSettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 13/10/2026 (M9) : l'API n'avait aucune limitation de débit hors connexion. Limite par jeton
 * (repli par IP), réglable par l'Owner, plafond par IP pour les rafales de jetons différents.
 */
final class ApiRateLimitMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
    }

    private \Illuminate\Cache\ArrayStore $store;

    private function middleware(?string $setting): ApiRateLimitMiddleware
    {
        $this->store = new \Illuminate\Cache\ArrayStore();
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn($setting === null ? [] : [ApiRateLimitMiddleware::SETTING_KEY => $setting]);

        $container = new \Tests\Support\TestContainer();
        $container->instance(AppSettingsService::class, new AppSettingsService($repo));

        return new ApiRateLimitMiddleware($container, new \Illuminate\Cache\RateLimiter(new \Illuminate\Cache\Repository($this->store)));
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
        return Request::capture();
    }

    private function pass(ApiRateLimitMiddleware $mw, Request $req): Response
    {
        return $mw->handle($req, static fn(Request $r): Response => response()->json(['ok' => true]));
    }

    public function testBlocksATokenAboveTheConfiguredLimitWithRetryAfter(): void
    {
        $mw = $this->middleware('3');
        for ($i = 0; $i < 3; $i++) {
            $response = $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));
            $this->assertSame(200, $response->getStatusCode());
        }
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));

        try {
            $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));
            $this->fail('La quatrième requête aurait dû être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertGreaterThan(0, (int) $e->getHeaders()['Retry-After']);
        }
    }

    public function testEachTokenHasItsOwnBudget(): void
    {
        $mw = $this->middleware('2');
        $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));
        $this->pass($mw, $this->request('/api/v1/shifts', 'token-a'));

        $response = $this->pass($mw, $this->request('/api/v1/shifts', 'token-b'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testWithoutTokenTheLimitAppliesPerIp(): void
    {
        $mw = $this->middleware('1');
        $this->pass($mw, $this->request('/api/v1/auth/ping', null, '10.0.0.1'));
        $this->assertSame(200, $this->pass($mw, $this->request('/api/v1/auth/ping', null, '10.0.0.2'))->getStatusCode());

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

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('X-RateLimit-Limit'));
    }

    public function testDefaultLimitAppliesWhenNotConfigured(): void
    {
        $response = $this->pass($this->middleware(null), $this->request('/api/v1/shifts', 'token-a'));

        $this->assertSame((string) ApiRateLimitMiddleware::DEFAULT_PER_MINUTE, $response->headers->get('X-RateLimit-Limit'));
    }

    public function testWebRoutesAreNotLimited(): void
    {
        $mw = $this->middleware('1');
        for ($i = 0; $i < 5; $i++) {
            $response = $this->pass($mw, $this->request('/admin/shifts'));
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('X-RateLimit-Limit'));
    }

    public function testTheRawTokenNeverEndsUpInTheCounterKeys(): void
    {
        $this->pass($this->middleware('5'), $this->request('/api/v1/shifts', 'secret-token-value'));

        $keys = array_keys((new \ReflectionProperty($this->store, 'storage'))->getValue($this->store));
        $this->assertNotSame([], $keys);
        $this->assertStringNotContainsString('secret-token-value', implode('|', $keys));
    }
}
