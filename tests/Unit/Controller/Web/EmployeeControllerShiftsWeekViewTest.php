<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\Models\Availability;
use App\Models\DevicePushToken;
use App\Models\IcalToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Models\Notification;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Repositories\TranslationRepositoryInterface;
use App\Models\UserDashboardPref;
use App\Models\UserNavPref;
use App\Models\User;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\PushNotificationService;
use Illuminate\Translation\Translator;
use App\Http\Controllers\EmployeeController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
    private EmployeeController $controller;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $viewDir = sys_get_temp_dir() . '/kintai-employee-controller-shifts-week-views';
        $this->writeViewFile($viewDir, 'scheduling.shifts', '<?= ($can_manage ?? false) ? "CAN_MANAGE_TRUE" : "CAN_MANAGE_FALSE" ?>');
        $this->writeViewFile($viewDir, 'layout.app', '<?= $content ?>');

        kintai_test_store_users(findByUser: fn() => []);
        kintai_test_shifts([]);
        kintai_test_users(findAll: fn() => []);
        kintai_test_shift_types([]);
        kintai_test_stores(findAll: fn() => []);
        $this->controller = new EmployeeController(
            kintai_test_views($viewDir),
            new AuditLogger(),
            new NotificationService(
                new PushNotificationService([]),
                kintai_test_translator(),
            ),
            new PermissionService(
            ),
        );
    }

    public function testShiftsWeekRendersSharedShiftsViewWithoutCanManage(): void
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $response = $this->controller->shiftsWeek($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('CAN_MANAGE_FALSE', $response->getContent());
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
