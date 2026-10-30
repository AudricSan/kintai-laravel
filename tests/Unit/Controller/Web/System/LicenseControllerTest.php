<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web\System;

use App\BundleDiscoveryService;
use App\FeatureManager;
use App\Models\AppSetting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\LicenseClientService;
use App\Services\PlanLimitService;
use App\Http\Controllers\System\LicenseController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 5));
}

final class LicenseControllerTest extends TestCase
{

    protected function setUp(): void
    {
        kintai_test_settings();
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
        $config = array_merge(['base_url' => 'https://license.test/api/v1', 'api_key' => 'kintai-key', 'grace_period_days' => 14], $config);
        $license = new LicenseClientService($config, transport: $transport);

        $planLimits = new PlanLimitService(
            $license,
        );

        return new LicenseController(
            kintai_test_views(sys_get_temp_dir()),
            $license,
            new AuditLogger(),
            $planLimits,
            new BundleDiscoveryService(sys_get_temp_dir() . '/kintai-license-controller-test-empty-bundles-dir'),
            new FeatureManager([]),
        );
    }

    public function testShowRendersPage(): void
    {
        $response = $this->makeController()->show(Request::capture());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testActivateRedirectsWithErrorWhenKeyMissing(): void
    {
        $_POST = ['license_key' => ''];
        $req = Request::capture();

        $response = $this->makeController()->activate($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testActivateWithValidKeyPersistsAndRedirectsSuccess(): void
    {
        $controller = $this->makeController([], fn(): string => json_encode(['valid' => true, 'status' => 'active']));
        $_POST = ['license_key' => 'KEY-1'];
        $req = Request::capture();

        $response = $controller->activate($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('KEY-1', AppSetting::value('license_key'));
    }

    public function testActivateWithInvalidKeyRedirectsWithError(): void
    {
        $controller = $this->makeController([], fn(): string => json_encode(['valid' => false, 'error' => 'license_not_found']));
        $_POST = ['license_key' => 'BAD-KEY'];
        $req = Request::capture();

        $response = $controller->activate($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testDeactivateClearsStoredLicense(): void
    {
        $controller = $this->makeController([], fn(): string => json_encode(['valid' => true]));
        $_POST = ['license_key' => 'KEY-1'];
        $req = Request::capture();
        $controller->activate($req);
        $this->assertNotNull(AppSetting::value('license_key'));

        $response = $controller->deactivate(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('', AppSetting::value('license_key'));
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
