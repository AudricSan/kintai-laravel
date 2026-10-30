<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\AuthService;
use Illuminate\Container\Container;
use App\Exceptions\ServiceUnavailableException;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Models\AppSetting;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AppSettingsService;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeMiddlewareTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_fk_off();
        $this->container = new \Tests\Support\TestContainer();
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
    }

    private function makeRequest(string $uri, string $method = 'GET'): Request
    {
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $uri,
            'SCRIPT_NAME'    => '/index.php',
        ];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        return Request::capture();
    }

    private function bindSettings(array $stored): void
    {
        kintai_test_settings($stored);
        $this->container->instance(AppSettingsService::class, new AppSettingsService());
    }

    private function bindAuth(?array $user): void
    {
        kintai_test_users(findById: fn() => $user);
        // AuthService::isAdmin() lit désormais role_assignments : on simule une
        // affectation au rôle système (Owner) quand le user de test porte
        // is_admin=1, pour préserver l'intention de chaque test existant.
        if ($user !== null && !empty($user['is_admin'])) {
            kintai_test_roles(findById: fn() => ['id' => 1, 'is_system' => 1]);
            kintai_test_assignments(findByUser: fn(int $id) => $id === (int) $user['id'] ? [['role_id' => 1, 'scope_type' => 'global', 'scope_id' => null]] : []);
        }

        $this->container->instance(AuthService::class, new AuthService());

        if ($user !== null) {
            session()->put(auth()->getName(), $user['id']);
        }
    }

    private function noop(): \Closure
    {
        return fn(Request $req) => response()->json(['ok' => true]);
    }

    public function testPassesThroughWhenMaintenanceDisabled(): void
    {
        $this->bindSettings(['maintenance_mode_enabled' => '0']);
        $this->bindAuth(null);
        $middleware = new MaintenanceModeMiddleware($this->container);

        $response = $middleware->handle($this->makeRequest('/'), $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminAlwaysPassesThrough(): void
    {
        $this->bindSettings(['maintenance_mode_enabled' => '1']);
        $this->bindAuth(['id' => 1, 'is_admin' => 1]);
        $middleware = new MaintenanceModeMiddleware($this->container);

        $response = $middleware->handle($this->makeRequest('/admin/stores'), $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnonymousCanReachLoginPage(): void
    {
        $this->bindSettings(['maintenance_mode_enabled' => '1']);
        $this->bindAuth(null);
        $middleware = new MaintenanceModeMiddleware($this->container);

        $response = $middleware->handle($this->makeRequest('/login'), $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnonymousBlockedOnOtherPagesWithCustomMessage(): void
    {
        $this->bindSettings([
            'maintenance_mode_enabled' => '1',
            'maintenance_message'      => 'Retour dans 10 minutes.',
        ]);
        $this->bindAuth(null);
        $middleware = new MaintenanceModeMiddleware($this->container);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Retour dans 10 minutes.');
        $middleware->handle($this->makeRequest('/'), $this->noop());
    }

    public function testApiRequestsBypassMaintenance(): void
    {
        $this->bindSettings(['maintenance_mode_enabled' => '1']);
        $this->bindAuth(null);
        $middleware = new MaintenanceModeMiddleware($this->container);

        $response = $middleware->handle($this->makeRequest('/api/v1/ping'), $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCronRequestsBypassMaintenance(): void
    {
        $this->bindSettings(['maintenance_mode_enabled' => '1']);
        $this->bindAuth(null);
        $middleware = new MaintenanceModeMiddleware($this->container);

        $response = $middleware->handle($this->makeRequest('/cron/run/backup'), $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testFailsOpenWhenSettingsResolutionThrows(): void
    {
        $this->container->bind(AppSettingsService::class, function (): AppSettingsService {
            throw new \RuntimeException('table absente');
        });
        $this->bindAuth(null);
        $middleware = new MaintenanceModeMiddleware($this->container);

        $response = $middleware->handle($this->makeRequest('/'), $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }
}
