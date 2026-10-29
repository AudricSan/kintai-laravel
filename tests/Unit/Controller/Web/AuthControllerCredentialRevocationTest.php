<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Auth\CredentialRevoker;
use App\Auth\UserAnonymizer;
use App\Repositories\ApiTokenRepositoryInterface;
use App\Repositories\AvailabilityRepositoryInterface;
use App\Repositories\IcalTokenRepositoryInterface;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RememberTokenRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserNavPrefsRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use App\View\ViewRenderer;
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
        session()->put('auth_user_id', self::USER_ID);

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
        session()->forget('auth_user_id');
        $_POST = [];
    }

    private function changePassword(string $current, string $new): void
    {
        $_POST = ['current_password' => $current, 'new_password' => $new, 'confirm_password' => $new];
        $this->controller->saveProfilePassword(Request::capture());
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
        $otherDevice = session()->all(); // session d'un autre appareil, liée à l'ancien mot de passe

        $this->changePassword($this->currentPassword, bin2hex(random_bytes(8)));

        session()->flush();
        session()->put($otherDevice);
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
        $this->controller->deleteAccount(Request::capture());
    }
}
