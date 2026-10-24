<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Auth\UserAnonymizer;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Changer son mot de passe depuis le profil : la session courante reste valide, mais toutes les
 * autres sessions, les cookies « rester connecté » et les jetons d'API sont révoqués.
 * Supprimer son compte purge aussi ces identifiants persistants.
 */
final class AuthControllerCredentialRevocationTest extends TestCase
{
    private const USER_ID = 7;

    private UserRepositoryInterface&Stub $users;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private RoleAssignmentRepositoryInterface&Stub $roleAssignments;
    private RememberTokenRepositoryInterface&Stub $remember;
    private ApiTokenRepositoryInterface&Stub $api;
    private AuthService $auth;
    private AuthController $controller;
    private string $currentPassword;
    /** @var array<string, mixed> */
    private array $row;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_user_id'] = self::USER_ID;

        $this->currentPassword = bin2hex(random_bytes(8));
        $this->row = [
            'id'            => self::USER_ID,
            'email'         => 'emp@example.test',
            'is_active'     => 1,
            'deleted_at'    => null,
            'password_hash' => password_hash($this->currentPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ];

        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturnCallback(fn() => $this->row);
        // Le dépôt persiste ce que le contrôleur sauvegarde, comme la vraie base.
        $this->users->method('save')->willReturnCallback(function (array $data) {
            $this->row = array_merge($this->row, $data);
            return $this->row;
        });

        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->roleAssignments->method('findByUser')->willReturn([]);

        $this->remember = $this->createStub(RememberTokenRepositoryInterface::class);
        $this->api      = $this->createStub(ApiTokenRepositoryInterface::class);

        $this->auth = new AuthService(
            $this->users,
            $this->storeUsers,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->roleAssignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $this->buildController();
    }

    /**
     * Contrôleur branché sur les dépôts de jetons courants : un test qui vérifie la révocation remplace d'abord les
     * bouchons par des mocks (useTokenMocks()).
     */
    private function buildController(): void
    {
        $this->controller = new AuthController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->auth,
            new AuditLogger(),
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
            $this->storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
            new CredentialRevoker($this->remember, $this->api),
            new UserAnonymizer($this->users, $this->roleAssignments, new CredentialRevoker($this->remember, $this->api), sys_get_temp_dir()),
        );
    }

    private function useTokenMocks(): void
    {
        $this->remember = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->api      = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->buildController();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
        $_POST = [];
    }

    private function changePassword(string $current, string $new): void
    {
        $_POST = ['current_password' => $current, 'new_password' => $new, 'confirm_password' => $new];
        $this->controller->saveProfilePassword(new Request());
    }

    public function testPasswordChangeRevokesRememberCookiesAndApiTokens(): void
    {
        $this->useTokenMocks();
        $this->remember->expects($this->once())->method('deleteByUserId')->with(self::USER_ID);
        $this->api->expects($this->once())->method('deleteByUserId')->with(self::USER_ID)->willReturn(0);

        $this->changePassword($this->currentPassword, bin2hex(random_bytes(8)));
    }

    public function testCurrentSessionSurvivesItsOwnPasswordChange(): void
    {
        // La session est « liée » à l'ancien mot de passe (comme après une connexion).
        $this->assertTrue($this->auth->check());

        $this->changePassword($this->currentPassword, bin2hex(random_bytes(8)));

        $this->assertTrue($this->auth->check());
    }

    public function testAnotherDeviceSessionIsRevokedByThePasswordChange(): void
    {
        $this->assertTrue($this->auth->check());
        $otherDevice = $_SESSION; // session d'un autre appareil, liée à l'ancien mot de passe

        $this->changePassword($this->currentPassword, bin2hex(random_bytes(8)));

        $_SESSION = $otherDevice;
        $this->assertFalse($this->auth->check());
    }

    public function testWrongCurrentPasswordRevokesNothing(): void
    {
        $this->useTokenMocks();
        $this->remember->expects($this->never())->method('deleteByUserId');
        $this->api->expects($this->never())->method('deleteByUserId');

        $this->changePassword(bin2hex(random_bytes(8)), bin2hex(random_bytes(8)));
    }

    public function testAccountDeletionPurgesRememberCookiesAndApiTokens(): void
    {
        $this->useTokenMocks();
        $this->remember->expects($this->once())->method('deleteByUserId')->with(self::USER_ID);
        $this->api->expects($this->once())->method('deleteByUserId')->with(self::USER_ID)->willReturn(0);

        $_POST = ['password' => $this->currentPassword];
        $this->controller->deleteAccount(new Request());
    }
}
