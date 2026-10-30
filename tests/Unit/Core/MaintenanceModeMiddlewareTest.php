<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\AuthService;
use Illuminate\Container\Container;
use App\Exceptions\ServiceUnavailableException;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Models\AppSetting;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Models\Role;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AppSettingsService;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeMiddlewareTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
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
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $stores = $this->createStub(StoreRepositoryInterface::class);

        // AuthService::isAdmin() lit désormais role_assignments : on simule une
        // affectation au rôle système (Owner) quand le user de test porte
        // is_admin=1, pour préserver l'intention de chaque test existant.
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        if ($user !== null && !empty($user['is_admin'])) {
            kintai_test_roles(findById: fn() => ['id' => 1, 'is_system' => 1]);
            $roleAssignments->method('findByUser')->willReturn([
                ['id' => 1, 'user_id' => $user['id'], 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
            ]);
        } else {
            $roleAssignments->method('findByUser')->willReturn([]);
        }

        $this->container->instance(AuthService::class, new AuthService($users, $storeUsers, $stores, $roleAssignments));

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
