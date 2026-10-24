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
use PHPUnit\Framework\TestCase;

/**
 * shiftsWeek() rendait auparavant une vue employé dédiée (scheduling.employee-shifts),
 * dupliquée avec scheduling.shifts (table admin) — sans bandeau "slider" partagé avec
 * l'admin. Elle rend désormais scheduling.shifts, la même vue que AdminShiftController::
 * shifts(), sans jamais passer can_manage (défaut false), pour ne pas exposer la table
 * de gestion multi-employés/actions en masse à un simple employé.
 */
final class EmployeeControllerShiftsWeekViewTest extends TestCase
{
    private StoreUserRepositoryInterface $storeUsers;
    private ShiftRepositoryInterface $shifts;
    private EmployeeController $controller;

    protected function setUp(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-employee-controller-shifts-week-views';
        $this->writeViewFile($viewDir, 'scheduling.shifts', '<?= ($can_manage ?? false) ? "CAN_MANAGE_TRUE" : "CAN_MANAGE_FALSE" ?>');
        $this->writeViewFile($viewDir, 'layout.app', '<?= $content ?>');

        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->shifts      = $this->createStub(ShiftRepositoryInterface::class);
        $users             = $this->createStub(UserRepositoryInterface::class);
        $shiftTypes        = $this->createStub(ShiftTypeRepositoryInterface::class);
        $stores            = $this->createStub(StoreRepositoryInterface::class);

        $this->storeUsers->method('findByUser')->willReturn([]);
        $this->shifts->method('findByUser')->willReturn([]);
        $users->method('findAll')->willReturn([]);
        $shiftTypes->method('findAll')->willReturn([]);
        $stores->method('findAll')->willReturn([]);

        $this->controller = new EmployeeController(
            new ViewRenderer($viewDir),
            $this->shifts,
            $shiftTypes,
            $stores,
            $this->storeUsers,
            $users,
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            new AuditLogger(),
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $this->createStub(UserDashboardPrefsRepositoryInterface::class),
            new NotificationService(
                $this->createStub(NotificationRepositoryInterface::class),
                new PushNotificationService([], $this->createStub(DevicePushTokenRepositoryInterface::class)),
                new TranslationService(
                    $this->createStub(TranslationRepositoryInterface::class),
                    $this->createStub(LanguageRepositoryInterface::class),
                ),
                $this->createStub(UserRepositoryInterface::class),
            ),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            new PermissionService(
                $this->createStub(RoleAssignmentRepositoryInterface::class),
                $this->createStub(RoleRepositoryInterface::class),
            ),
        );
    }

    public function testShiftsWeekRendersSharedShiftsViewWithoutCanManage(): void
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $response = $this->controller->shiftsWeek($req);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('CAN_MANAGE_FALSE', $response->body());
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
