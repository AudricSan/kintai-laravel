<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web\System;

use App\Models\ActivityEntry;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Http\Controllers\System\ActivityController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
    private UserRepositoryInterface&Stub $users;
    private ActivityController $controller;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        \Illuminate\Support\Facades\DB::table('stores')->insert([['id' => 3, 'name' => 'A', 'code' => 'A'], ['id' => 4, 'name' => 'B', 'code' => 'B']]);
        $viewDir = sys_get_temp_dir() . '/kintai-activity-controller-views';
        $this->writeViewFile($viewDir, 'system.activity-log', 'ok');
        $this->writeViewFile($viewDir, 'layout.app', '<?= $content ?>');
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findAll')->willReturn([]);

        $this->controller = new ActivityController(
            kintai_test_views($viewDir),
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
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', [3]);
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->controller->index($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty(array_filter($queries, static fn(string $sql): bool => str_contains($sql, 'activity_log') && str_contains($sql, '"store_id" in (')), 'la requête est limitée aux magasins gérés');
    }
    public function testOwnerRequestDoesNotFilterByStore(): void
    {
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->controller->index($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEmpty(array_filter($queries, static fn(string $sql): bool => str_contains($sql, 'activity_log') && str_contains($sql, '"store_id" in (')));
    }
    public function testExportReturnsCsvWithMatchingRows(): void
    {
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        \App\Models\ActivityEntry::create([
            'created_at' => '2026-09-18 10:00:00', 'level' => 'info', 'channel' => 'audit', 'action' => 'auth.login', 'message' => 'auth.login', 'user_id' => null,
        ]);

        $response = $this->controller->export($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('auth.login', $response->getContent());
    }
    public function testExportAppliesSameFiltersAsIndex(): void
    {
        $_GET = ['level' => 'error', 'channel' => 'audit'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', [3]);
        foreach ([['garde-moi', 'error', 'audit', 3], ['mauvais-niveau', 'info', 'audit', 3], ['autre-magasin', 'error', 'audit', 4], ['autre-canal', 'error', 'access', 3]] as [$message, $level, $channel, $store]) {
            \App\Models\ActivityEntry::create(['message' => $message, 'level' => $level, 'channel' => $channel, 'store_id' => $store, 'created_at' => '2026-09-18 10:00:00']);
        }

        $response = $this->controller->export($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('garde-moi', $response->getContent());
        foreach (['mauvais-niveau', 'autre-magasin', 'autre-canal'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $response->getContent());
        }
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
