<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\NotificationRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\PushNotificationService;
use kintai\Core\Services\TranslationService;
use kintai\UI\Controller\Web\EmployeeController;
use kintai\UI\ViewRenderer;
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
            new ViewRenderer(sys_get_temp_dir()),
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
                new TranslationService(
                    $this->createStub(TranslationRepositoryInterface::class),
                    $this->createStub(LanguageRepositoryInterface::class),
                ),
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

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

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

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $this->controller->saveNavSettings($req);
    }
}
