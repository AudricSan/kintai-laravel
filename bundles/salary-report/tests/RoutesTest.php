<?php

declare(strict_types=1);

namespace Tests\Bundles\SalaryReport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestContainer;
use kintai\Contract\Http\Middleware\PermissionMiddleware;
use kintai\Contract\Http\Middleware\AuthMiddleware;

/**
 * Régression : les routes /admin/reports/salary et
 * /admin/stores/{id}/reports/salary/* de ce bundle doivent rester protégées.
 */
final class RoutesTest extends TestCase
{
    private function loadRoutes(): \Illuminate\Routing\Router
    {
        $router = kintai_test_router();
        require dirname(__DIR__) . '/routes.php';

        return $router;
    }

    #[DataProvider('adminRoutesProvider')]
    public function testAdminRoutesRequireAdminAuth(string $method, string $path): void
    {
        $this->loadRoutes();
        $route = kintai_test_match($method, $path);

        $this->assertContains(AuthMiddleware::class, $route->gatherMiddleware());
        $this->assertContains(PermissionMiddleware::class, $route->gatherMiddleware());
    }

    public static function adminRoutesProvider(): array
    {
        return [
            ['GET', '/admin/reports/salary'],
            ['GET', '/admin/stores/1/reports/salary'],
            ['GET', '/admin/stores/1/reports/salary/create'],
            ['POST', '/admin/stores/1/reports/salary/create'],
            ['GET', '/admin/stores/1/reports/salary/10'],
            ['GET', '/admin/stores/1/reports/salary/10/edit'],
            ['POST', '/admin/stores/1/reports/salary/10/edit'],
            ['POST', '/admin/stores/1/reports/salary/10/delete'],
            ['GET', '/admin/stores/1/reports/salary/10/pdf'],
        ];
    }
}
