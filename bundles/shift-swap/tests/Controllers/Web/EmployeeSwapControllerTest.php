<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftSwap\Controllers\Web;


use kintai\Bundles\Installed\ShiftSwap\Controllers\Web\EmployeeSwapController;
use App\Exceptions\ForbiddenException;
use App\Models\Shift;
use kintai\Bundles\Installed\ShiftSwap\Repositories\ShiftSwapRequestRepositoryInterface;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Régression : storeSwap/acceptSwap/refuseSwap écrivaient et relisaient les clés
 * 'target_id'/'peer_accepted_at', qui n'existent pas dans la table shift_swap_requests
 * (colonnes réelles : target_user_id/accepted_at). En production, storeSwap plantait
 * avec une erreur SQL "unknown column 'target_id'" à chaque tentative d'échange.
 */
final class EmployeeSwapControllerTest extends TestCase
{
    private ShiftSwapRequestRepositoryInterface&Stub $swapRequests;
    private NotificationService&Stub $notifs;
    private ?EmployeeSwapController $controller = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $viewDir = sys_get_temp_dir() . '/kintai-shift-swap-views';
        $this->ensureViewFile($viewDir, 'swaps');
        $this->ensureViewFile($viewDir, 'swaps-form');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');
        $this->swapRequests = $this->createStub(ShiftSwapRequestRepositoryInterface::class);
        $this->notifs       = $this->createStub(NotificationService::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): EmployeeSwapController
    {
        if ($this->controller === null) {
            $viewDir = sys_get_temp_dir() . '/kintai-shift-swap-views';
            $view = kintai_test_views(sys_get_temp_dir());
            $view->addNamespace('shift-swap', $viewDir);
            $this->controller = new EmployeeSwapController(
                $view,
                $this->swapRequests,
                $this->createStub(UserRepositoryInterface::class),
                new AuditLogger(),
                $this->notifs,
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    public function testStoreSwapSavesTargetUserIdAndAcceptedAtColumns(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5, 'user_id' => 1]] : []);
        kintai_test_store_features(5, []);

        $_POST = [
            'requester_shift_id' => '10',
            'target_id'          => '2',
            'target_shift_id'    => '20',
            'reason'             => 'Test',
        ];

        $myShift     = ['id' => 10, 'user_id' => 1, 'store_id' => 5];
        $targetShift = ['id' => 20, 'user_id' => 2, 'store_id' => 5];
        kintai_test_shifts([$myShift, $targetShift]);

        $captured = null;
        $this->swapRequests->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 42];
        });

        $this->notifs->expects($this->once())->method('notify')->with(2, 'swap_requested', $this->anything(), $this->anything(), 42);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        $response = $this->controller()->storeSwap($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertArrayHasKey('target_user_id', $captured);
        $this->assertSame(2, $captured['target_user_id']);
        $this->assertArrayNotHasKey('target_id', $captured);
        $this->assertArrayHasKey('accepted_at', $captured);
        $this->assertNull($captured['accepted_at']);
        $this->assertArrayNotHasKey('peer_accepted_at', $captured);
    }

    /** Ouvrir la liste marque comme lues les notifications des échanges affichés (envoyés et reçus, sans doublon). */
    public function testSwapsMarksTheNotificationsOfTheDisplayedSwapsAsRead(): void
    {
        $this->swapRequests = $this->createStub(ShiftSwapRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        kintai_test_store_users(findByUser: fn() => [['store_id' => 5, 'user_id' => 1]]);
        kintai_test_store_features(null, []);
        $this->swapRequests->method('findByRequester')->willReturn([['id' => 3, 'created_at' => '2026-01-01'], ['id' => 4, 'created_at' => '2026-01-02']]);
        $this->swapRequests->method('findByTarget')->willReturn([['id' => 4, 'created_at' => '2026-01-02'], ['id' => 9, 'created_at' => '2026-01-03']]);
        $this->notifs->expects($this->once())->method('markReadFor')->with(1, 'swap', [4, 3, 9]);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        $this->assertSame(200, $this->controller()->swaps($req)->getStatusCode());
    }

    public function testStoreSwapRedirectsWhenFeatureDisabledForStore(): void
    {
        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5, 'user_id' => 1]] : []);
        kintai_test_store_features(5, ['shifts']);

        $_POST = ['requester_shift_id' => '10', 'target_id' => '2', 'target_shift_id' => '20'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        $response = $this->controller()->storeSwap($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testAcceptSwapReadsAndWritesAcceptedAtColumn(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $swap = [
            'id' => 5, 'store_id' => 3, 'requester_id' => 1,
            'target_user_id' => 9, 'status' => 'pending', 'accepted_at' => null,
        ];
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn($swap);

        $captured = null;
        $this->swapRequests->expects($this->once())->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d;
        });

        $this->notifs->expects($this->once())->method('notify')->with(1, 'swap_peer_accepted', $this->anything(), $this->anything(), 5);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->acceptSwap($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertArrayHasKey('accepted_at', $captured);
        $this->assertNotNull($captured['accepted_at']);
    }

    public function testAcceptSwapForbiddenWhenCurrentUserIsNotTheTarget(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $swap = [
            'id' => 5, 'store_id' => 3, 'requester_id' => 1,
            'target_user_id' => 9, 'status' => 'pending', 'accepted_at' => null,
        ];
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn($swap);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->acceptSwap($req);
    }

    public function testRefuseSwapReadsTargetUserIdColumn(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $swap = [
            'id' => 5, 'store_id' => 3, 'requester_id' => 1,
            'target_user_id' => 9, 'status' => 'pending', 'accepted_at' => null,
        ];
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn($swap);

        $captured = null;
        $this->swapRequests->expects($this->once())->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d;
        });

        $this->notifs->expects($this->once())->method('notify')->with(1, 'swap_peer_refused', $this->anything(), $this->anything(), 5);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->refuseSwap($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('refused', $captured['status']);
    }

    public function testCancelSwapNotifiesTargetUser(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $swap = [
            'id' => 5, 'store_id' => 3, 'requester_id' => 1,
            'target_user_id' => 9, 'status' => 'pending', 'accepted_at' => null,
        ];
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn($swap);
        $this->swapRequests->expects($this->once())->method('save');

        $this->notifs->expects($this->once())->method('notify')->with(9, 'swap_cancelled', $this->anything(), $this->anything(), 5);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->cancelSwap($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    private function ensureViewFile(string $dir, string $view): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        touch($file);
    }
}
