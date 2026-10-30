<?php

declare(strict_types=1);

namespace Tests\Bundles\StorePhoto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestContainer;
use kintai\Contract\Http\Middleware\PermissionMiddleware;
use kintai\Contract\Http\Middleware\AuthMiddleware;

/**
 * Régression : les routes /admin/photos du bundle StorePhoto doivent rester
 * protégées par AuthMiddleware + PermissionMiddleware après leur extraction
 * du core, exactement comme avant l'extraction.
 */
final class StorePhotoRoutesTest extends TestCase
{
    #[DataProvider('photosRoutesProvider')]
    public function testPhotosRoutesRequireAdminAuth(string $method, string $path): void
    {
        $router = kintai_test_router();
        require dirname(__DIR__, 1) . '/routes.php';

        $route = kintai_test_match($method, $path);

        $this->assertContains(AuthMiddleware::class, $route->gatherMiddleware(), "$method $path must require auth");
        $this->assertContains(PermissionMiddleware::class, $route->gatherMiddleware(), "$method $path must require admin");
    }

    public static function photosRoutesProvider(): array
    {
        return [
            ['GET', '/admin/photos'],
            ['GET', '/admin/photos/settings'],
            ['POST', '/admin/photos/settings'],
            ['GET', '/admin/photos/create'],
            ['POST', '/admin/photos/create'],
            ['POST', '/admin/photos/1/upload'],
            ['GET', '/admin/photos/1/2'],
            ['POST', '/admin/photos/1/2/delete'],
        ];
    }
}
