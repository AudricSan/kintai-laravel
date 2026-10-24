<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\NotificationRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\PushNotificationService;
use kintai\Core\Services\TranslationService;
use kintai\UI\Controller\Web\EmployeeController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie que 'can_manage' (bouton de gestion des shifts sur la page planning
 * employé) dépend désormais du RBAC réel (shifts.update sur le store affiché),
 * plus de la colonne legacy store_user.role, qui n'est jamais resynchronisée
 * quand les permissions d'un rôle sont modifiées après coup.
 */
final class EmployeeControllerShiftDayCanManageTest extends TestCase
{
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private StoreRepositoryInterface&Stub $stores;
    private RoleAssignmentRepositoryInterface&Stub $assignments;
    private RoleRepositoryInterface&Stub $roles;
    private EmployeeController $controller;
    private string $viewDir;

    protected function setUp(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-employee-controller-views';
        $this->writeViewFile($viewDir, 'scheduling.shifts-timeline', '<?= $can_manage ? "CAN_MANAGE_TRUE" : "CAN_MANAGE_FALSE" ?>');
        $this->writeViewFile($viewDir, 'layout.app', '<?= $content ?>');

        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->roles = $this->createStub(RoleRepositoryInterface::class);

        $this->viewDir    = $viewDir;
        $this->controller = $this->buildController($this->createStub(ShiftRepositoryInterface::class), $this->createStub(NotificationRepositoryInterface::class));

        $this->storeUsers->method('findByUser')->willReturnCallback(fn($a0) => $a0 === 9 ? [
            ['user_id' => 9, 'store_id' => 1, 'role' => 'staff'],
            ['user_id' => 9, 'store_id' => 2, 'role' => 'staff'],
        ] : []);
        $this->stores->method('findById')->willReturn(['id' => 1, 'name' => 'Store 1', 'currency' => 'JPY']);
    }

    private function buildController(ShiftRepositoryInterface $shifts, NotificationRepositoryInterface $notifRepo): EmployeeController
    {
        return new EmployeeController(
            new ViewRenderer($this->viewDir),
            $shifts,
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->stores,
            $this->storeUsers,
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            new AuditLogger(),
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $this->createStub(UserDashboardPrefsRepositoryInterface::class),
            new NotificationService(
                $notifRepo,
                new PushNotificationService([], $this->createStub(DevicePushTokenRepositoryInterface::class)),
                new TranslationService(
                    $this->createStub(TranslationRepositoryInterface::class),
                    $this->createStub(LanguageRepositoryInterface::class),
                ),
                $this->createStub(UserRepositoryInterface::class),
            ),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            new PermissionService($this->assignments, $this->roles),
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    private function grantShiftsUpdateOnStore(int $storeId): void
    {
        $this->assignments->method('findByUser')->willReturnCallback(fn($a0) => $a0 === 9 ? [
            ['id' => 1, 'user_id' => 9, 'role_id' => 5, 'scope_type' => 'store', 'scope_id' => $storeId],
        ] : []);
        $this->roles->method('findById')->willReturnCallback(fn($a0) => $a0 === 5 ? ['id' => 5, 'is_system' => 0] : null);
        $this->roles->method('getPermissions')->willReturnCallback(fn($a0) => $a0 === 5 ? ['shifts.update'] : []);
    }

    public function testCanManageTrueWhenRoleGrantsShiftsUpdateOnDisplayedStore(): void
    {
        $this->grantShiftsUpdateOnStore(1);

        $_GET = ['store_id' => '1'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $response = $this->controller->shiftDay($req);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('CAN_MANAGE_TRUE', $response->body());
    }

    public function testCanManageFalseWhenRoleGrantsShiftsUpdateOnOtherStoreOnly(): void
    {
        // Le rôle accorde shifts.update sur le store 2, mais l'utilisateur consulte le store 1.
        $this->grantShiftsUpdateOnStore(2);

        $_GET = ['store_id' => '1'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $response = $this->controller->shiftDay($req);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('CAN_MANAGE_FALSE', $response->body());
    }

    public function testCanManageFalseWhenNoRoleGrantsAnything(): void
    {
        $this->assignments->method('findByUser')->willReturnCallback(fn($a0) => $a0 === 9 ? [] : []);

        $_GET = ['store_id' => '1'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $response = $this->controller->shiftDay($req);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('CAN_MANAGE_FALSE', $response->body());
    }

    public function testCanManageTrueForOwnerRegardlessOfPermissions(): void
    {
        $this->assignments->method('findByUser')->willReturnCallback(fn($a0) => $a0 === 9 ? [] : []);

        $_GET = ['store_id' => '1'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9, 'is_admin' => 1]);

        $response = $this->controller->shiftDay($req);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('CAN_MANAGE_TRUE', $response->body());
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

    /** « Lu » automatique : seuls les shifts de l'utilisateur affichés dans la plage sont rapportés au service. */
    public function testShiftDayMarksTheUsersOwnDisplayedShiftsAsRead(): void
    {
        $date   = date('Y-m-d');
        $shifts = $this->createStub(ShiftRepositoryInterface::class);
        $shifts->method('findByStore')->willReturn([
            ['id' => 31, 'user_id' => 9, 'store_id' => 1, 'shift_date' => $date],
            ['id' => 32, 'user_id' => 4, 'store_id' => 1, 'shift_date' => $date],
            ['id' => 33, 'user_id' => 9, 'store_id' => 1, 'shift_date' => date('Y-m-d', strtotime('+30 days'))],
        ]);
        $notifRepo = $this->createMock(NotificationRepositoryInterface::class);
        // Deux appels : les shifts affichés, puis les jours affichés (notifications de shifts supprimés).
        $calls = [];
        $notifRepo->expects($this->exactly(2))->method('markReadByReference')
            ->willReturnCallback(function (int $uid, string $type, array $ids) use (&$calls) { $calls[$type] = [$uid, $ids]; });

        $_GET = ['store_id' => '1'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $this->assertSame(200, $this->buildController($shifts, $notifRepo)->shiftDay($req)->status());
        $this->assertSame([9, [31]], $calls['shift']);
        $this->assertSame(9, $calls['shift_day'][0]);
        $this->assertContains(shift_day_reference($date), $calls['shift_day'][1]);
    }

    public function testShiftDayPassesTheHighlightedShiftToTheView(): void
    {
        $this->writeViewFile($this->viewDir, 'scheduling.shifts-timeline', 'HIGHLIGHT=<?= $highlight_id ?>');

        $_GET = ['store_id' => '1', 'highlight' => '31'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $this->assertStringContainsString('HIGHLIGHT=31', $this->controller->shiftDay($req)->body());
        $this->writeViewFile($this->viewDir, 'scheduling.shifts-timeline', '<?= $can_manage ? "CAN_MANAGE_TRUE" : "CAN_MANAGE_FALSE" ?>');
    }
}
