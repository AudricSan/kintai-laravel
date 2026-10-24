<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Container;
use kintai\Core\Middleware\LoginThrottleMiddleware;
use kintai\Core\Middleware\RateLimiterMiddleware;
use kintai\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Les routes d'authentification sont des points d'entrée publics (aucun token requis) : chacune
 * doit rester protégée contre la recherche par force brute. Les routes de connexion (web et API)
 * passent par LoginThrottleMiddleware (échecs comptés par IP et par compte) ; les actions rares
 * (mot de passe oublié, réinitialisation) par le limiteur générique.
 */
final class RoutesTest extends TestCase
{
    /** @return list<class-string> */
    private function middlewareOf(string $method, string $uri): array
    {
        $router    = new Router();
        $container = new Container();
        require dirname(__DIR__, 3) . '/routes/kintai.php';

        [$route] = $router->dispatch($method, $uri);

        return $route->middleware;
    }

    public function testApiLoginRouteIsThrottled(): void
    {
        $this->assertContains(LoginThrottleMiddleware::class, $this->middlewareOf('POST', '/api/v1/auth/login'));
    }

    public function testWebLoginRouteIsThrottled(): void
    {
        $this->assertContains(LoginThrottleMiddleware::class, $this->middlewareOf('POST', '/login'));
    }

    public function testLoginRoutesNoLongerUseTheCountEveryRequestLimiter(): void
    {
        // Ce limiteur comptait aussi les succès : il bloquait les employés d'un même magasin (IP partagée).
        $this->assertNotContains(RateLimiterMiddleware::class, $this->middlewareOf('POST', '/login'));
        $this->assertNotContains(RateLimiterMiddleware::class, $this->middlewareOf('POST', '/api/v1/auth/login'));
    }

    public function testForgotPasswordRouteIsRateLimited(): void
    {
        $this->assertContains(RateLimiterMiddleware::class, $this->middlewareOf('POST', '/forgot-password'));
    }

    public function testPasswordResetSubmissionIsRateLimited(): void
    {
        $this->assertContains(RateLimiterMiddleware::class, $this->middlewareOf('POST', '/reset-password/some-token'));
    }
}
