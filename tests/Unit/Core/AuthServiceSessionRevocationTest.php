<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Une session ouverte ne doit survivre ni à la désactivation/suppression du compte, ni à un
 * changement de mot de passe (audit du 30/09/2026 : check() se contentait de lire la session et
 * user() renvoyait n'importe quel utilisateur, actif ou non).
 */
final class AuthServiceSessionRevocationTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    /** @var array<string, mixed> Ligne « en base » que findById() renvoie, modifiable par chaque test. */
    private array $row;
    private AuthService $auth;
    private string $pwOne;
    private string $pwTwo;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];

        // Valeurs générées à l'exécution : aucun mot de passe en clair dans le dépôt.
        $this->pwOne = bin2hex(random_bytes(8));
        $this->pwTwo = bin2hex(random_bytes(8));

        $this->row = [
            'id'            => 42,
            'email'         => 'emp@example.test',
            'is_active'     => 1,
            'deleted_at'    => null,
            'password_hash' => password_hash($this->pwOne, PASSWORD_BCRYPT, ['cost' => 4]),
        ];

        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturnCallback(fn() => $this->row);
        $this->users->method('findByEmail')->willReturnCallback(fn() => $this->row);

        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);

        $this->auth = new AuthService(
            $this->users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $roleAssignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function loginNormally(): void
    {
        $this->assertTrue($this->auth->attempt('emp@example.test', $this->pwOne));
    }

    public function testActiveUserSessionIsAccepted(): void
    {
        $this->loginNormally();

        $this->assertTrue($this->auth->check());
        $this->assertSame(42, (int) $this->auth->user()['id']);
    }

    public function testSessionOfADeactivatedUserIsRefusedAndCleared(): void
    {
        $this->loginNormally();
        $this->row['is_active'] = 0;

        $this->assertFalse($this->auth->check());
        $this->assertNull($this->auth->user());
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
    }

    public function testSessionOfASoftDeletedUserIsRefused(): void
    {
        $this->loginNormally();
        $this->row['deleted_at'] = '2026-09-30 10:00:00';

        $this->assertFalse($this->auth->check());
    }

    public function testSessionOfAnUnknownUserIsRefused(): void
    {
        $_SESSION['auth_user_id'] = 999;
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(null);
        $auth = new AuthService(
            $users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $this->assertFalse($auth->check());
    }

    public function testSessionOpenedBeforeAPasswordChangeIsRevoked(): void
    {
        $this->loginNormally();
        // Un autre appareil / un admin / une réinitialisation change le mot de passe.
        $this->row['password_hash'] = password_hash($this->pwTwo, PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertFalse($this->auth->check());
        $this->assertNull($this->auth->user());
    }

    public function testCurrentSessionSurvivesItsOwnPasswordChangeOnceRefreshed(): void
    {
        $this->loginNormally();
        $this->row['password_hash'] = password_hash($this->pwTwo, PASSWORD_BCRYPT, ['cost' => 4]);

        $this->auth->refreshSessionAfterPasswordChange($this->row);

        $this->assertTrue($this->auth->check());
    }

    public function testAnotherSessionIsStillRevokedWhenTheCurrentOneRefreshes(): void
    {
        $this->loginNormally();
        $otherDeviceSession = $_SESSION;

        $this->row['password_hash'] = password_hash($this->pwTwo, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->auth->refreshSessionAfterPasswordChange($this->row);

        // On simule la session de l'autre appareil (empreinte ancienne).
        $_SESSION = $otherDeviceSession;
        $this->assertFalse($this->auth->check());
    }

    public function testSessionOpenedBeforeTheFingerprintExistedAdoptsItOnFirstRequest(): void
    {
        $_SESSION = ['auth_user_id' => 42]; // session « historique », sans empreinte

        $this->assertTrue($this->auth->check());
        $this->assertArrayHasKey('auth_pw_fp', $_SESSION);

        // Une fois adoptée, un changement de mot de passe la révoque.
        $this->row['password_hash'] = password_hash($this->pwTwo, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertFalse($this->auth->check());
    }

    public function testRowWithoutIsActiveKeyIsTreatedAsActive(): void
    {
        unset($this->row['is_active']);
        $_SESSION = ['auth_user_id' => 42];

        $this->assertTrue($this->auth->check());
    }

    public function testNoSessionMeansNotLoggedIn(): void
    {
        $this->assertFalse($this->auth->check());
    }

    public function testCredentialRevokerRemovesRememberCookiesAndApiTokens(): void
    {
        $remember = $this->createMock(RememberTokenRepositoryInterface::class);
        $remember->expects($this->once())->method('deleteByUserId')->with(42);
        $api = $this->createMock(ApiTokenRepositoryInterface::class);
        $api->expects($this->once())->method('deleteByUserId')->with(42)->willReturn(3);

        (new CredentialRevoker($remember, $api))->revokeAllFor(42);
    }
}
