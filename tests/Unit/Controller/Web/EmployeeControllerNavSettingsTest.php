<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\Repositories\AvailabilityRepositoryInterface;
use App\Repositories\DevicePushTokenRepositoryInterface;
use App\Repositories\IcalTokenRepositoryInterface;
use App\Repositories\ShiftRepositoryInterface;
use App\Repositories\ShiftTypeRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserDashboardPrefsRepositoryInterface;
use App\Repositories\UserNavPrefsRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Repositories\UserShiftTypeRateRepositoryInterface;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\NotificationRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\TranslationRepositoryInterface;
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
    private UserNavPrefsRepositoryInterface&MockObject $navPrefs;
    private EmployeeController $controller;

    protected function setUp(): void
    {
        $this->navPrefs = $this->createMock(UserNavPrefsRepositoryInterface::class);

        $this->controller = new EmployeeController(
            kintai_test_views(sys_get_temp_dir()),
            $this->createStub(ShiftRepositoryInterface::class),
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            new AuditLogger(),
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $this->createStub(UserDashboardPrefsRepositoryInterface::class),
            new NotificationService(
                $this->createStub(NotificationRepositoryInterface::class),
                new PushNotificationService([], $this->createStub(DevicePushTokenRepositoryInterface::class)),
                kintai_test_translator(),
                $this->createStub(UserRepositoryInterface::class),
            ),
            $this->navPrefs,
            new PermissionService(
                $this->createStub(RoleAssignmentRepositoryInterface::class),
                $this->createStub(RoleRepositoryInterface::class),
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
        $this->navPrefs->method('getHidden')->with(9)->willReturn([]);
        $this->navPrefs->method('getSectionOrder')->with(9)->willReturn([]);
        $this->navPrefs->method('getBottomNavItems')->with(9)->willReturn([]);

        $_POST = [
            'nav_available' => ['messages'],
            'nav'           => [],
        ];

        $this->navPrefs->expects($this->once())->method('saveHidden')->with(9, ['messages']);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->controller->saveNavSettings($req);
    }

    public function testSaveNavSettingsKeepsPreviouslyHiddenItemsNotOfferedThisTime(): void
    {
        // 'swaps' était déjà masqué par choix de l'employé.
        $this->navPrefs->method('getHidden')->with(9)->willReturn(['swaps']);
        $this->navPrefs->method('getSectionOrder')->with(9)->willReturn([]);
        $this->navPrefs->method('getBottomNavItems')->with(9)->willReturn([]);

        // Seule 'messages' est proposée cette fois (feature réactivée), laissée cochée.
        $_POST = [
            'nav_available' => ['messages'],
            'nav'           => ['messages' => '1'],
        ];

        $this->navPrefs->expects($this->once())->method('saveHidden')->with(9, ['swaps']);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);

        $this->controller->saveNavSettings($req);
    }
}
