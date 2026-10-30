<?php

declare(strict_types=1);

namespace Tests\Bundles\TimeOff\Controllers\Web;


use kintai\Bundles\Installed\TimeOff\Controllers\Web\EmployeeTimeoffController;
use App\Exceptions\ForbiddenException;
use App\Models\Store;
use App\Models\StoreUser;
use kintai\Bundles\Installed\TimeOff\Repositories\TimeoffRequestRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class EmployeeTimeoffControllerTest extends TestCase
{
    private TimeoffRequestRepositoryInterface&Stub $timeoffRequests;
    private \App\Services\NotificationService $notifs;
    private ?EmployeeTimeoffController $controller = null;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        $viewDir = sys_get_temp_dir() . '/kintai-timeoff-views';
        $this->ensureViewFile($viewDir, 'employee-timeoff');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $this->timeoffRequests = $this->createStub(TimeoffRequestRepositoryInterface::class);
        $this->notifs = $this->createStub(\App\Services\NotificationService::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): EmployeeTimeoffController
    {
        if ($this->controller === null) {
            $viewDir = sys_get_temp_dir() . '/kintai-timeoff-views';
            $view = kintai_test_views(sys_get_temp_dir());
            $view->addNamespace('timeoff', $viewDir);
            $this->controller = new EmployeeTimeoffController(
                $view,
                $this->timeoffRequests,
                new AuditLogger(),
                $this->createStub(\App\Repositories\UserRepositoryInterface::class),
                $this->notifs,
                new \App\Auth\PermissionService(
                ),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    public function testTimeoffRendersListForEmployee(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 9 ? [['store_id' => 1, 'user_id' => 9]] : []);
        kintai_test_store_features(1, []);
        $this->timeoffRequests->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([]);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $response = $this->controller()->timeoff($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    /** Ouvrir la liste marque comme lues les notifications des demandes affichées. */
    public function testTimeoffMarksTheNotificationsOfTheDisplayedRequestsAsRead(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(\App\Services\NotificationService::class);
        kintai_test_store_users(findByUser: fn() => [['store_id' => 1, 'user_id' => 9]]);
        kintai_test_store_features(null, []);
        $this->timeoffRequests->method('findByUser')->with(9)->willReturn([
            ['id' => 4, 'created_at' => '2026-01-01'], ['id' => 7, 'created_at' => '2026-02-01'],
        ]);
        $this->notifs->expects($this->once())->method('markReadFor')->with(9, 'timeoff', [7, 4]);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->assertSame(200, $this->controller()->timeoff($req)->getStatusCode());
    }

    public function testTimeoffRedirectsWhenFeatureDisabledForStore(): void
    {
        kintai_test_store_users(findByUser: fn(int $id) => $id === 9 ? [['store_id' => 1, 'user_id' => 9]] : []);
        kintai_test_store_features(1, ['shifts', 'timeclock']);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $response = $this->controller()->timeoff($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreTimeoffCreatesPendingRequest(): void
    {
        kintai_test_store_users(findByUser: fn(int $id) => $id === 9 ? [['store_id' => 1, 'user_id' => 9]] : []);
        kintai_test_store_features(1, []);

        $_POST = ['type' => 'sick', 'start_date' => '2026-08-01', 'end_date' => '2026-08-02', 'reason' => 'Grippe'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $captured = null;
        $this->timeoffRequests->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 7];
        });

        $response = $this->controller()->storeTimeoff($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($captured);
        $this->assertSame('pending', $captured['status']);
        $this->assertSame(9, $captured['user_id']);
        $this->assertSame(1, $captured['store_id']);
    }

    /** Audit du 13/10/2026 : les dates étaient enregistrées telles quelles, sans aucun contrôle. */
    public function testStoreTimeoffRejectsInvalidDates(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 9 ? [['store_id' => 1, 'user_id' => 9]] : []);
        kintai_test_store_features(1, []);
        $this->timeoffRequests->expects($this->never())->method('save');

        $_POST = ['type' => 'sick', 'start_date' => 'pas-une-date', 'end_date' => ''];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->storeTimeoff($req);
    }

    public function testStoreTimeoffRejectsEndBeforeStart(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 9 ? [['store_id' => 1, 'user_id' => 9]] : []);
        kintai_test_store_features(1, []);
        $this->timeoffRequests->expects($this->never())->method('save');

        $_POST = ['type' => 'sick', 'start_date' => '2026-08-05', 'end_date' => '2026-08-01'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->storeTimeoff($req);
    }

    public function testStoreTimeoffUsesTheChosenStoreWhenMember(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 9 ? [
            ['store_id' => 1, 'user_id' => 9], ['store_id' => 4, 'user_id' => 9],
        ] : []);
        kintai_test_store_features(null, []);

        $_POST = ['type' => 'sick', 'start_date' => '2026-08-01', 'store_id' => '4'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->timeoffRequests->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['store_id'] === 4 && $d['end_date'] === '2026-08-01'
        ))->willReturn(['id' => 7]);

        $this->assertSame(302, $this->controller()->storeTimeoff($req)->getStatusCode());
    }

    public function testStoreTimeoffRefusesAStoreTheEmployeeDoesNotBelongTo(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 9 ? [['store_id' => 1, 'user_id' => 9]] : []);
        kintai_test_store_features(null, []);
        $this->timeoffRequests->expects($this->never())->method('save');

        $_POST = ['type' => 'sick', 'start_date' => '2026-08-01', 'store_id' => '99'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->storeTimeoff($req);
    }

    public function testCancelTimeoffOnlyAllowsOwnerOfPendingRequest(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '5']);

        $this->timeoffRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn([
            'id' => 5, 'user_id' => 9, 'status' => 'pending',
        ]);
        $this->timeoffRequests->expects($this->once())->method('save')->willReturnCallback(fn(array $d) => $d);

        $response = $this->controller()->cancelTimeoff($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testCancelTimeoffForbiddenForAnotherUsersRequest(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '5']);

        $this->timeoffRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn([
            'id' => 5, 'user_id' => 42, 'status' => 'pending',
        ]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->cancelTimeoff($req);
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
