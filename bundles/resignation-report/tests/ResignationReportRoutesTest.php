<?php

declare(strict_types=1);

namespace Tests\Bundles\ResignationReport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestContainer;
use kintai\Contract\Http\Middleware\PermissionMiddleware;
use kintai\Contract\Http\Middleware\AuthMiddleware;

/**
 * Régression : les routes /admin/reports/resignation et
 * /admin/stores/{id}/reports/resignation/* du bundle ResignationReport
 * doivent rester protégées après leur extraction du core.
 *
 * Depuis son extraction du monorepo (voir docs/architecture.md "Modular
 * Bundles"), le code réel du bundle vit hors de ce dépôt
 * (kintai-bundle-resignation-report) ; tests/Fixtures/bundles/resignation-report-1.0.0/
 * en est une copie fidèle, maintenue en même temps que le dépôt externe.
 */
final class ResignationReportRoutesTest extends TestCase
{
    private function loadRoutes(): \Illuminate\Routing\Router
    {
        $router = kintai_test_router();
        require dirname(__DIR__, 1) . '/routes.php';

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
            ['GET', '/admin/reports/resignation'],
            ['GET', '/admin/stores/1/reports/resignation'],
            ['GET', '/admin/stores/1/reports/resignation/create'],
            ['POST', '/admin/stores/1/reports/resignation/create'],
            ['GET', '/admin/stores/1/reports/resignation/10'],
            ['GET', '/admin/stores/1/reports/resignation/10/edit'],
            ['POST', '/admin/stores/1/reports/resignation/10/edit'],
            ['POST', '/admin/stores/1/reports/resignation/10/delete'],
            ['POST', '/admin/stores/1/reports/resignation/10/delete-permanently'],
            ['GET', '/admin/stores/1/reports/resignation/10/pdf'],
            ['POST', '/admin/stores/1/reports/resignation/10/reactivate'],
        ];
    }
}
