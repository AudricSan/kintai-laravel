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
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\PushNotificationService;
use Illuminate\Translation\Translator;
use App\Http\Controllers\EmployeeController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
    private EmployeeController $controller;
    private string $viewDir;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $viewDir = sys_get_temp_dir() . '/kintai-employee-controller-views';
        $this->writeViewFile($viewDir, 'scheduling.shifts-timeline', '<?= $can_manage ? "CAN_MANAGE_TRUE" : "CAN_MANAGE_FALSE" ?>');
        $this->writeViewFile($viewDir, 'layout.app', '<?= $content ?>');

        // Pas de publication de planning dans ce test : tous les shifts sont visibles.
        \Illuminate\Container\Container::getInstance()->bind(\App\Services\SchedulePublication::class, static fn() => throw new \RuntimeException('indisponible'));

        $this->viewDir    = $viewDir;
        $this->controller = $this->buildController();

        kintai_test_store_users(findByUser: fn($a0) => $a0 === 9 ? [
            ['user_id' => 9, 'store_id' => 1, 'role' => 'staff'],
            ['user_id' => 9, 'store_id' => 2, 'role' => 'staff'],
        ] : []);
        kintai_test_stores(findById: fn() => ['id' => 1, 'name' => 'Store 1', 'currency' => 'JPY']);
    }

    private function buildController(): EmployeeController
    {
        return new EmployeeController(
            kintai_test_views($this->viewDir),
            $this->createStub(UserRepositoryInterface::class),
            new AuditLogger(),
            new NotificationService(
                new PushNotificationService([]),
                kintai_test_translator(),
                $this->createStub(UserRepositoryInterface::class),
            ),
            new PermissionService(),
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    private function grantShiftsUpdateOnStore(int $storeId): void
    {
        kintai_test_assignments(findByUser: fn($a0) => $a0 === 9 ? [
            ['id' => 1, 'user_id' => 9, 'role_id' => 5, 'scope_type' => 'store', 'scope_id' => $storeId],
        ] : []);
        kintai_test_roles(findById: fn($a0) => $a0 === 5 ? ['id' => 5, 'is_system' => 0] : null, getPermissions: fn($a0) => $a0 === 5 ? ['shifts.update'] : []);
    }

    public function testCanManageTrueWhenRoleGrantsShiftsUpdateOnDisplayedStore(): void
    {
        $this->grantShiftsUpdateOnStore(1);

        $_GET = ['store_id' => '1'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $response = $this->controller->shiftDay($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('CAN_MANAGE_TRUE', $response->getContent());
    }

    public function testCanManageFalseWhenRoleGrantsShiftsUpdateOnOtherStoreOnly(): void
    {
        // Le rôle accorde shifts.update sur le store 2, mais l'utilisateur consulte le store 1.
        $this->grantShiftsUpdateOnStore(2);

        $_GET = ['store_id' => '1'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $response = $this->controller->shiftDay($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('CAN_MANAGE_FALSE', $response->getContent());
    }

    public function testCanManageFalseWhenNoRoleGrantsAnything(): void
    {
        kintai_test_assignments(findByUser: fn($a0) => $a0 === 9 ? [] : []);
        $_GET = ['store_id' => '1'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $response = $this->controller->shiftDay($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('CAN_MANAGE_FALSE', $response->getContent());
    }

    public function testCanManageTrueForOwnerRegardlessOfPermissions(): void
    {
        kintai_test_assignments(findByUser: fn($a0) => $a0 === 9 ? [] : []);
        $_GET = ['store_id' => '1'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9, 'is_admin' => 1]);

        $response = $this->controller->shiftDay($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('CAN_MANAGE_TRUE', $response->getContent());
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
        kintai_test_shifts([
            ['id' => 31, 'user_id' => 9, 'store_id' => 1, 'shift_date' => $date],
            ['id' => 32, 'user_id' => 4, 'store_id' => 1, 'shift_date' => $date],
            ['id' => 33, 'user_id' => 9, 'store_id' => 1, 'shift_date' => date('Y-m-d', strtotime('+30 days'))],
        ]);
        // Les notifications des shifts affichés, puis celles des jours affichés (shifts supprimés), sont marquées lues.
        foreach ([['shift', 31], ['shift', 32], ['shift_day', shift_day_reference($date)]] as [$type, $ref]) {
            \App\Models\Notification::create(['user_id' => 9, 'type' => 'shift_assigned', 'body' => 'x', 'reference_type' => $type, 'reference_id' => $ref]);
        }

        $_GET = ['store_id' => '1'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->assertSame(200, $this->buildController()->shiftDay($req)->getStatusCode());
        $read = \App\Models\Notification::whereNotNull('read_at')->pluck('reference_type', 'reference_id')->all();
        $this->assertSame([31 => 'shift', shift_day_reference($date) => 'shift_day'], $read, 'seul le shift 31 de l\'employé 9 et son jour sont lus');
    }

    public function testShiftDayPassesTheHighlightedShiftToTheView(): void
    {
        $this->writeViewFile($this->viewDir, 'scheduling.shifts-timeline', 'HIGHLIGHT=<?= $highlight_id ?>');

        $_GET = ['store_id' => '1', 'highlight' => '31'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->assertStringContainsString('HIGHLIGHT=31', $this->controller->shiftDay($req)->getContent());
        $this->writeViewFile($this->viewDir, 'scheduling.shifts-timeline', '<?= $can_manage ? "CAN_MANAGE_TRUE" : "CAN_MANAGE_FALSE" ?>');
    }
}
