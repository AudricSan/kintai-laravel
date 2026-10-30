<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftClaim\Controllers\Web;


use kintai\Bundles\Installed\ShiftClaim\Controllers\Web\EmployeeShiftClaimController;
use App\Auth\PermissionService;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use kintai\Bundles\Installed\ShiftClaim\Repositories\ShiftClaimRepositoryInterface;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class EmployeeShiftClaimControllerTest extends TestCase
{
    private StoreRepositoryInterface&Stub $stores;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private ShiftClaimRepositoryInterface&Stub $shiftClaims;
    private NotificationService&Stub $notifs;
    private ?EmployeeShiftClaimController $controller = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $viewDir = sys_get_temp_dir() . '/kintai-shift-claim-views';
        $this->ensureViewFile($viewDir, 'open-shifts');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createStub(ShiftClaimRepositoryInterface::class);
        $this->notifs = $this->createStub(NotificationService::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): EmployeeShiftClaimController
    {
        if ($this->controller === null) {
            $viewDir = sys_get_temp_dir() . '/kintai-shift-claim-views';
            $view = kintai_test_views(sys_get_temp_dir());
            $view->addNamespace('shift-claim', $viewDir);
            $this->controller = new EmployeeShiftClaimController(
                $view,
                $this->stores,
                $this->storeUsers,
                $this->createStub(UserRepositoryInterface::class),
                $this->shiftClaims,
                new AuditLogger(),
                $this->notifs,
                new PermissionService(
                    $this->createStub(RoleAssignmentRepositoryInterface::class),
                    $this->createStub(RoleRepositoryInterface::class),
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

    public function testOpenShiftsListsShiftsFromMyStoresWithMyClaimMarked(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn([]);
        kintai_test_shifts([
            ['id' => 100, 'shift_date' => '2026-08-01', 'store_id' => 1],
        ]);
        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'Store A']]);
        $this->shiftClaims->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([
            ['id' => 5, 'shift_id' => 100, 'status' => 'pending'],
        ]);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $response = $this->controller()->openShifts($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    /** Ouvrir la liste marque comme lues les notifications des shifts publiés affichés. */
    public function testOpenShiftsMarksTheNotificationsOfTheDisplayedShiftsAsRead(): void
    {
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createStub(ShiftClaimRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $this->storeUsers->method('findByUser')->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->method('getFeatures')->willReturn([]);
        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'Store A']]);
        kintai_test_shifts([
            ['id' => 200, 'is_open' => 1, 'shift_date' => '2026-08-02', 'store_id' => 1],
            ['id' => 100, 'is_open' => 1, 'shift_date' => '2026-08-01', 'store_id' => 1],
        ]);
        $this->shiftClaims->method('findByUser')->willReturn([]);
        $this->notifs->expects($this->once())->method('markReadFor')->with(9, 'open_shift', [100, 200]);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->assertSame(200, $this->controller()->openShifts($req)->getStatusCode());
    }

    public function testOpenShiftsRedirectsWhenFeatureDisabledForStore(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn(['shifts']);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $response = $this->controller()->openShifts($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testClaimShiftCreatesPendingClaim(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn([]);
        kintai_test_shifts([['id' => 50, 'is_open' => 1, 'user_id' => null, 'store_id' => 1]]);
        $this->shiftClaims->expects($this->atLeastOnce())->method('findByUserAndShift')->with(9, 50)->willReturn(null);

        $captured = null;
        $this->shiftClaims->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 77];
        });

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $response = $this->controller()->claimShift($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('pending', $captured['status']);
        $this->assertSame(9, $captured['user_id']);
    }

    public function testClaimShiftNotifiesManagersWithApprovePermission(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn([]);
        kintai_test_shifts([['id' => 50, 'is_open' => 1, 'user_id' => null, 'store_id' => 1]]);
        $this->shiftClaims->expects($this->atLeastOnce())->method('findByUserAndShift')->with(9, 50)->willReturn(null);
        $this->shiftClaims->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 77]);

        $this->storeUsers->expects($this->atLeastOnce())->method('findByStore')->with(1)->willReturn([
            ['user_id' => 9],  // le candidat lui-même, sans droit particulier : ne doit pas se notifier lui-même
            ['user_id' => 20], // manager avec open_shifts.approve
        ]);

        $usersRepo = $this->createStub(UserRepositoryInterface::class);
        $usersRepo->method('findById')->willReturnMap([
            [9, ['id' => 9]],
            [20, ['id' => 20]],
        ]);
        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturnMap([
            [9, []],
            [20, [['id' => 1, 'user_id' => 20, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]]],
        ]);
        $roles = $this->createMock(RoleRepositoryInterface::class);
        $roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'is_system' => 0]);
        $roles->expects($this->atLeastOnce())->method('getPermissions')->with(2)->willReturn(['open_shifts.approve']);

        $view = kintai_test_views(sys_get_temp_dir());
        $view->addNamespace('shift-claim', sys_get_temp_dir() . '/kintai-shift-claim-views');

        $controller = new EmployeeShiftClaimController(
            $view,
            $this->stores,
            $this->storeUsers,
            $usersRepo,
            $this->shiftClaims,
            new AuditLogger(),
            $this->notifs,
            new PermissionService($assignments, $roles),
        );

        $this->notifs->expects($this->once())->method('notifyMany')
            ->with([20], 'shift_claim_submitted', $this->anything(), $this->anything(), 50);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $controller->claimShift($req);
    }

    /** Publier un shift attribué, c'est le céder (audit n°8, B3) : un collègue peut y postuler. */
    public function testColleagueCanClaimAPublishedAssignedShift(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn([]);
        kintai_test_shifts([['id' => 50, 'is_open' => 1, 'user_id' => 5, 'store_id' => 1]]);
        $this->shiftClaims->expects($this->atLeastOnce())->method('findByUserAndShift')->with(9, 50)->willReturn(null);
        $this->shiftClaims->expects($this->once())->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 77]);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $this->controller()->claimShift($req)->headers->get('Location') ?? '';
        $this->assertStringContainsString('claimed', (string) session('success'));
    }

    public function testHolderCannotClaimOwnPublishedShift(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(5)->willReturn([['store_id' => 1, 'user_id' => 5]]);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn([]);
        kintai_test_shifts([['id' => 50, 'is_open' => 1, 'user_id' => 5, 'store_id' => 1]]);
        $this->shiftClaims->expects($this->never())->method('save');

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 5]);
        $req->setRouteParams(['id' => '50']);

        $this->controller()->claimShift($req)->headers->get('Location') ?? '';
        $this->assertStringContainsString('own_shift', (string) session('error'));
    }

    public function testClaimShiftRejectsAlreadyClaimedShift(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn([]);
        kintai_test_shifts([['id' => 50, 'is_open' => 1, 'user_id' => null, 'store_id' => 1]]);
        $this->shiftClaims->expects($this->atLeastOnce())->method('findByUserAndShift')->with(9, 50)->willReturn(['id' => 1, 'status' => 'pending']);
        $this->shiftClaims->expects($this->never())->method('save');

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $response = $this->controller()->claimShift($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testWithdrawShiftClaimSetsWithdrawnStatus(): void
    {
        $this->shiftClaims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->shiftClaims->expects($this->atLeastOnce())->method('findByUserAndShift')->with(9, 50)->willReturn(['id' => 1, 'status' => 'pending', 'shift_id' => 50]);

        $captured = null;
        $this->shiftClaims->expects($this->once())->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d;
        });

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $response = $this->controller()->withdrawShiftClaim($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('withdrawn', $captured['status']);
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
