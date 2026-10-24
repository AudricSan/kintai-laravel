<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\System;

use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Web\System\AdminController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminControllerNavSettingsTest extends TestCase
{
    private UserNavPrefsRepositoryInterface&MockObject $navPrefs;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->navPrefs = $this->createMock(UserNavPrefsRepositoryInterface::class);

        $this->controller = new AdminController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->navPrefs,
            new AuditLogger(),
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    /**
     * Reproduit le bug : quand une feature ('messages') est désactivée pour le magasin au
     * moment de la sauvegarde, sa case n'est jamais soumise dans le formulaire. L'ancien code
     * calculait `array_diff($allowedKeys, $visible)`, ce qui masquait alors TOUTES les clés
     * non explicitement cochées, y compris celles jamais proposées à l'utilisateur.
     */
    public function testSaveNavSettingsOnlyHidesItemsActuallyOfferedInTheForm(): void
    {
        $user = ['id' => 7, 'is_admin' => false];

        $this->navPrefs->method('getHidden')->with(7)->willReturn([]);
        $this->navPrefs->method('getSectionOrder')->with(7)->willReturn([]);
        $this->navPrefs->method('getBottomNavItems')->with(7)->willReturn([]);

        // Seule 'messages' était proposée dans le formulaire (les autres features étaient
        // désactivées pour ce magasin) ; l'utilisateur l'a décochée pour la masquer.
        $_POST = [
            'nav_available' => ['messages'],
            'nav'           => [],
        ];

        $this->navPrefs->expects($this->once())->method('saveHidden')->with(7, ['messages']);

        $req = new Request();
        $req->setAttribute('auth_user', $user);

        $this->controller->saveNavSettings($req);
    }

    public function testSaveNavSettingsKeepsPreviouslyHiddenItemsNotOfferedThisTime(): void
    {
        $user = ['id' => 7, 'is_admin' => false];

        // 'daily_reports' était déjà masqué par choix de l'utilisateur.
        $this->navPrefs->method('getHidden')->with(7)->willReturn(['daily_reports']);
        $this->navPrefs->method('getSectionOrder')->with(7)->willReturn([]);
        $this->navPrefs->method('getBottomNavItems')->with(7)->willReturn([]);

        // Seule 'messages' est proposée cette fois (feature réactivée), l'utilisateur la laisse cochée.
        $_POST = [
            'nav_available' => ['messages'],
            'nav'           => ['messages' => '1'],
        ];

        $this->navPrefs->expects($this->once())->method('saveHidden')->with(7, ['daily_reports']);

        $req = new Request();
        $req->setAttribute('auth_user', $user);

        $this->controller->saveNavSettings($req);
    }
}
