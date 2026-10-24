<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvatarImageOptimizer;
use kintai\UI\Controller\Web\AuthController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * showProfile() calcule désormais has_default_password (password_verify du
 * hash stocké contre le mot de passe par défaut "0000") pour que la vue
 * affiche un avertissement invitant l'utilisateur à le changer.
 */
final class AuthControllerShowProfilePasswordWarningTest extends TestCase
{
    private const USER_ID = 7;

    private $users;
    private string $viewDir;
    private AuthController $controller;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_user_id'] = self::USER_ID;

        $this->viewDir = sys_get_temp_dir() . '/kintai-auth-profile-test';
        $this->ensureViewFile($this->viewDir, 'auth.profile', "<?= !empty(\$has_default_password) ? 'DEFAULT_PW_WARNING' : 'NO_WARNING' ?>");
        $this->ensureViewFile($this->viewDir, 'layout.app', '<?= $content ?>');

        $this->users = $this->createMock(UserRepositoryInterface::class);

        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByUser')->willReturn([]);

        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);

        $navPrefs = $this->createStub(UserNavPrefsRepositoryInterface::class);
        $navPrefs->method('getHidden')->willReturn([]);
        $navPrefs->method('getSectionOrder')->willReturn([]);
        $navPrefs->method('getBottomNavItems')->willReturn([]);

        $auth = new AuthService(
            $this->users,
            $storeUsers,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $roleAssignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $this->controller = new AuthController(
            new ViewRenderer($this->viewDir),
            $auth,
            new AuditLogger(),
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
            $storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class),
            $navPrefs,
            $this->createStub(AvailabilityRepositoryInterface::class),
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
        );
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
    }

    public function testWarningShownWhenPasswordIsStillTheDefault(): void
    {
        $this->users->method('findById')->with(self::USER_ID)->willReturn([
            'id'            => self::USER_ID,
            'password_hash' => password_hash('0000', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $response = $this->controller->showProfile(new Request());

        $this->assertStringContainsString('DEFAULT_PW_WARNING', $response->body());
    }

    public function testWarningHiddenWhenPasswordHasBeenChanged(): void
    {
        $this->users->method('findById')->with(self::USER_ID)->willReturn([
            'id'            => self::USER_ID,
            'password_hash' => password_hash('a-real-password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $response = $this->controller->showProfile(new Request());

        $this->assertStringContainsString('NO_WARNING', $response->body());
    }

    private function ensureViewFile(string $dir, string $view, string $content): void
    {
        $file   = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($file, $content);
    }
}
