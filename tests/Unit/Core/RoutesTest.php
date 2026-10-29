<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Http\Middleware\LoginThrottleMiddleware;
use App\Http\Middleware\RateLimiterMiddleware;
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
        $router = kintai_test_load_routes();
        $route  = $router->getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));

        return $route->gatherMiddleware();
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
