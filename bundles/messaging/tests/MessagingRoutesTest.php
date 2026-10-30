<?php

declare(strict_types=1);

namespace Tests\Bundles\Messaging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestContainer;
use kintai\Contract\Http\Middleware\ApiAuthMiddleware;
use kintai\Contract\Http\Middleware\AuthMiddleware;
use kintai\Contract\Http\Middleware\PermissionMiddleware;

/**
 * Régression : Messaging était le seul bundle des onze jamais raccordé au
 * RBAC — ses routes /admin/messages/* ne passaient que par le filtre grossier
 * "gestionnaire d'au moins un store" (l'ancien AdminMiddleware), donc
 * n'importe quel manager pouvait tout lire/envoyer/supprimer indépendamment
 * de son rôle assigné.
 *
 * Depuis son extraction du monorepo (voir docs/architecture.md "Modular
 * Bundles"), le code réel du bundle vit hors de ce dépôt
 * (kintai-bundle-messaging) ; tests/Fixtures/bundles/messaging-1.0.0/ en
 * est une copie fidèle, maintenue en même temps que le dépôt externe.
 */
final class MessagingRoutesTest extends TestCase
{
    private function loadRoutes(): \Illuminate\Routing\Router
    {
        $router = kintai_test_router();
        require dirname(__DIR__, 1) . '/routes.php';

        return $router;
    }

    #[DataProvider('employeeRoutesProvider')]
    public function testEmployeeRoutesRequireAuthOnly(string $method, string $path): void
    {
        $this->loadRoutes();
        $route = kintai_test_match($method, $path);

        $this->assertContains(AuthMiddleware::class, $route->gatherMiddleware());
    }

    public static function employeeRoutesProvider(): array
    {
        return [
            ['GET', '/employee/messages/compose'],
            ['POST', '/employee/messages'],
            ['GET', '/employee/messages'],
            ['GET', '/employee/messages/10'],
            ['POST', '/employee/messages/10'],
            ['POST', '/employee/messages/10/delete'],
            ['POST', '/employee/messages/10/message/5/delete'],
        ];
    }

    #[DataProvider('adminRoutesProvider')]
    public function testAdminRoutesRequirePermissionMiddleware(string $method, string $path): void
    {
        $this->loadRoutes();
        $route = kintai_test_match($method, $path);

        $this->assertContains(AuthMiddleware::class, $route->gatherMiddleware());
        $this->assertContains(PermissionMiddleware::class, $route->gatherMiddleware());
    }

    public static function adminRoutesProvider(): array
    {
        return [
            ['GET', '/admin/messages/compose'],
            ['POST', '/admin/messages'],
            ['GET', '/admin/messages'],
            ['GET', '/admin/messages/10'],
            ['POST', '/admin/messages/10'],
            ['POST', '/admin/messages/10/delete'],
            ['POST', '/admin/messages/10/message/5/delete'],
        ];
    }

    #[DataProvider('apiRoutesProvider')]
    public function testApiRoutesRequireApiAuth(string $method, string $path): void
    {
        $this->loadRoutes();
        $route = kintai_test_match($method, $path);

        $this->assertContains(ApiAuthMiddleware::class, $route->gatherMiddleware());
    }

    public static function apiRoutesProvider(): array
    {
        return [
            ['GET', '/api/v1/messages/threads'],
            ['POST', '/api/v1/messages/threads'],
            ['GET', '/api/v1/messages/threads/10/messages'],
            ['POST', '/api/v1/messages/threads/10/messages'],
            ['GET', '/api/v1/messages/threads/10/participants'],
            ['POST', '/api/v1/messages/threads/10/participants'],
            ['GET', '/api/v1/messages/threads/10/participants/3'],
            ['GET', '/api/v1/messages/threads/10'],
            ['DELETE', '/api/v1/messages/threads/10'],
            ['DELETE', '/api/v1/messages/10'],
        ];
    }
}
