<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\Models\Availability;
use App\Models\DevicePushToken;
use App\Models\IcalToken;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\UserDashboardPref;
use App\Models\UserNavPref;
use App\Models\User;
use App\Models\UserShiftTypeRate;
use App\Models\Notification;
use App\Models\RoleAssignment;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\PushNotificationService;
use Illuminate\Translation\Translator;
use App\Http\Controllers\EmployeeController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EmployeeControllerNavSettingsTest extends TestCase
{
    use \Tests\Support\PrefsExpectations;

    private EmployeeController $controller;

    protected function setUp(): void
    {

        $this->controller = new EmployeeController(
            kintai_test_views(sys_get_temp_dir()),
            new AuditLogger(),
            new NotificationService(
                new PushNotificationService([]),
                kintai_test_translator(),
            ),
            new PermissionService(
            ),
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    /**
     * Reproduit le bug : quand une feature ('messages') est désactivée pour le magasin de
     * l'employé au moment de la sauvegarde, sa case n'est jamais soumise dans le formulaire.
     * L'ancien code calculait `array_diff(NAV_EMPLOYEE_KEYS, $visible)`, ce qui masquait
     * alors TOUTES les clés non explicitement cochées, y compris celles jamais proposées.
     */
    public function testSaveNavSettingsOnlyHidesItemsActuallyOfferedInTheForm(): void
    {
        $_POST = [
            'nav_available' => ['messages'],
            'nav'           => [],
        ];

        $this->expectNavHidden(9, ['messages']);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->controller->saveNavSettings($req);
    }

    public function testSaveNavSettingsKeepsPreviouslyHiddenItemsNotOfferedThisTime(): void
    {
        // 'swaps' était déjà masqué par choix de l'employé.
        \App\Models\UserNavPref::saveHidden(9, ['swaps']);
        // Seule 'messages' est proposée cette fois (feature réactivée), laissée cochée.
        $_POST = [
            'nav_available' => ['messages'],
            'nav'           => ['messages' => '1'],
        ];

        $this->expectNavHidden(9, ['swaps']);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->controller->saveNavSettings($req);
    }
}
