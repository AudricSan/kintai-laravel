<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\System;

use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Web\System\ActivityController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * 'admin.activity' est désormais protégée par la clé stores.view
 * (config/permissions.php) ; PermissionMiddleware pose managed_store_ids
 * pour un manager scopé. Ce test vérifie que le contrôleur relaie bien cette
 * portée au repository, plutôt que de renvoyer le journal de toute
 * l'organisation.
 */
final class ActivityControllerTest extends TestCase
{
    private LogRepositoryInterface&MockObject $logs;
    private UserRepositoryInterface&Stub $users;
    private ActivityController $controller;

    protected function setUp(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-activity-controller-views';
        $this->writeViewFile($viewDir, 'system.activity-log', 'ok');
        $this->writeViewFile($viewDir, 'layout.app', '<?= $content ?>');

        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findAll')->willReturn([]);

        $this->controller = new ActivityController(
            new ViewRenderer($viewDir),
            $this->logs,
            $this->users,
            new AuditLogger(),
        );

        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testManagerScopedRequestFiltersLogsByManagedStoreIds(): void
    {
        $req = new Request();
        $req->setAttribute('managed_store_ids', [3]);

        $this->logs->expects($this->once())->method('findAll')
            ->with(1, 100, $this->callback(fn(array $f) => ($f['store_ids'] ?? null) === [3]))
            ->willReturn([]);
        $this->logs->method('countAll')->with($this->callback(fn(array $f) => ($f['store_ids'] ?? null) === [3]))->willReturn(0);

        $response = $this->controller->index($req);

        $this->assertSame(200, $response->status());
    }

    public function testOwnerRequestDoesNotFilterByStore(): void
    {
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $this->logs->expects($this->once())->method('findAll')
            ->with(1, 100, $this->callback(fn(array $f) => !array_key_exists('store_ids', $f)))
            ->willReturn([]);
        $this->logs->method('countAll')->willReturn(0);

        $response = $this->controller->index($req);

        $this->assertSame(200, $response->status());
    }

    public function testExportReturnsCsvWithMatchingRows(): void
    {
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $this->logs->expects($this->once())->method('findAll')
            ->with(1, 20000, [])
            ->willReturn([
                [
                    'created_at' => '2026-09-18 10:00:00',
                    'level'      => 'info',
                    'channel'    => 'audit',
                    'action'     => 'auth.login',
                    'message'    => 'auth.login',
                    'user_id'    => 1,
                ],
            ]);

        $response = $this->controller->export($req);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('auth.login', $response->body());
    }

    public function testExportAppliesSameFiltersAsIndex(): void
    {
        $_GET = ['level' => 'error', 'channel' => 'audit'];
        $req = new Request();
        $req->setAttribute('managed_store_ids', [3]);

        $this->logs->expects($this->once())->method('findAll')
            ->with(1, 20000, $this->callback(fn(array $f) => ($f['level'] ?? null) === 'error'
                && ($f['channel'] ?? null) === 'audit'
                && ($f['store_ids'] ?? null) === [3]))
            ->willReturn([]);

        $response = $this->controller->export($req);

        $this->assertSame(200, $response->status());
    }

    private function writeViewFile(string $dir, string $view, string $phpBody): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($file, $phpBody);
    }
}
