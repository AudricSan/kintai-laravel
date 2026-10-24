<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\System;

use kintai\Core\BundleDiscoveryService;
use kintai\Core\FeatureManager;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\PlanLimitService;
use kintai\UI\Controller\Web\System\LicenseController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 5));
}

final class LicenseControllerTest extends TestCase
{
    private array $store = [];

    protected function setUp(): void
    {
        $this->store = [];
        $this->ensureViewFile('system.license');
        $this->ensureViewFile('layout.app');
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    private function makeController(array $config = [], ?callable $transport = null): LicenseController
    {
        $appSettings = $this->createStub(AppSettingsRepositoryInterface::class);
        $appSettings->method('get')->willReturnCallback(fn(string $k) => $this->store[$k] ?? null);
        $appSettings->method('set')->willReturnCallback(function (string $k, string $v): void {
            $this->store[$k] = $v;
        });

        $config = array_merge(['base_url' => 'https://license.test/api/v1', 'api_key' => 'kintai-key', 'grace_period_days' => 14], $config);
        $license = new LicenseClientService($appSettings, $config, transport: $transport);

        $planLimits = new PlanLimitService(
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            $license,
        );

        return new LicenseController(
            new ViewRenderer(sys_get_temp_dir()),
            $license,
            new AuditLogger(),
            $planLimits,
            new BundleDiscoveryService(sys_get_temp_dir() . '/kintai-license-controller-test-empty-bundles-dir'),
            new FeatureManager([]),
        );
    }

    public function testShowRendersPage(): void
    {
        $response = $this->makeController()->show(new Request());

        $this->assertSame(200, $response->status());
    }

    public function testActivateRedirectsWithErrorWhenKeyMissing(): void
    {
        $_POST = ['license_key' => ''];
        $req = new Request();

        $response = $this->makeController()->activate($req);

        $this->assertSame(302, $response->status());
    }

    public function testActivateWithValidKeyPersistsAndRedirectsSuccess(): void
    {
        $controller = $this->makeController([], fn(): string => json_encode(['valid' => true, 'status' => 'active']));
        $_POST = ['license_key' => 'KEY-1'];
        $req = new Request();

        $response = $controller->activate($req);

        $this->assertSame(302, $response->status());
        $this->assertSame('KEY-1', $this->store['license_key'] ?? null);
    }

    public function testActivateWithInvalidKeyRedirectsWithError(): void
    {
        $controller = $this->makeController([], fn(): string => json_encode(['valid' => false, 'error' => 'license_not_found']));
        $_POST = ['license_key' => 'BAD-KEY'];
        $req = new Request();

        $response = $controller->activate($req);

        $this->assertSame(302, $response->status());
    }

    public function testDeactivateClearsStoredLicense(): void
    {
        $controller = $this->makeController([], fn(): string => json_encode(['valid' => true]));
        $_POST = ['license_key' => 'KEY-1'];
        $req = new Request();
        $controller->activate($req);
        $this->assertNotNull($this->store['license_key'] ?? null);

        $response = $controller->deactivate(new Request());

        $this->assertSame(302, $response->status());
        $this->assertSame('', $this->store['license_key'] ?? null);
    }

    private function ensureViewFile(string $view): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        touch($file);
    }
}
