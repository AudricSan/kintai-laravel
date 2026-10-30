<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Auth\CredentialRevoker;
use App\Auth\UserAnonymizer;
use App\Models\ApiToken;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserNavPref;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
    use \Tests\Support\ApiTokenExpectations;

    private const USER_ID = 7;

    private UserRepositoryInterface&Stub $users;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private RoleAssignmentRepositoryInterface&Stub $roleAssignments;
    private UserRepositoryInterface&Stub $remember;
    private AuthService $auth;
    private AuthController $controller;
    private string $currentPassword;
    /** @var array<string, mixed> */
    private array $row;

    protected function setUp(): void
    {
        session()->put(auth()->getName(), self::USER_ID);

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

        $this->remember = $this->createStub(UserRepositoryInterface::class);

        $this->auth = new AuthService(
            $this->users,
            $this->storeUsers,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->roleAssignments,
            $this->createStub(UserRepositoryInterface::class),
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
            kintai_test_views(sys_get_temp_dir()),
            $this->auth,
            new AuditLogger(),
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
            $this->storeUsers,
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
            new CredentialRevoker($this->remember),
            new UserAnonymizer($this->users, $this->roleAssignments, new CredentialRevoker($this->remember), sys_get_temp_dir()),
        );
    }

    private function useTokenMocks(): void
    {
        $this->remember = $this->createMock(UserRepositoryInterface::class);
        $this->buildController();
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
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
        $this->remember->expects($this->once())->method('revokeRememberToken')->with(self::USER_ID);
        $this->expectApiTokensRevoked(self::USER_ID);

        $this->changePassword($this->currentPassword, bin2hex(random_bytes(8)));
    }

    public function testCurrentSessionSurvivesItsOwnPasswordChange(): void
    {
        // La session est « liée » à l'ancien mot de passe (comme après une connexion).
        $this->assertTrue($this->auth->check());

        $this->changePassword($this->currentPassword, bin2hex(random_bytes(8)));

        $this->assertTrue($this->auth->check());
    }

    public function testWrongCurrentPasswordRevokesNothing(): void
    {
        $this->useTokenMocks();
        $this->remember->expects($this->never())->method('revokeRememberToken');
        $this->expectApiTokensKept(1);

        $this->changePassword(bin2hex(random_bytes(8)), bin2hex(random_bytes(8)));
    }

    public function testAccountDeletionPurgesRememberCookiesAndApiTokens(): void
    {
        $this->useTokenMocks();
        $this->remember->expects($this->once())->method('revokeRememberToken')->with(self::USER_ID);
        $this->expectApiTokensRevoked(self::USER_ID);

        $_POST = ['password' => $this->currentPassword];
        $this->controller->deleteAccount(Request::capture());
    }
}
