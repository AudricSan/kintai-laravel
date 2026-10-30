<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\AuthService;
use App\Auth\CredentialRevoker;
use App\Models\ApiToken;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
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
    use \Tests\Support\ApiTokenExpectations;

    private UserRepositoryInterface&Stub $users;
    /** @var array<string, mixed> Ligne « en base » que findById() renvoie, modifiable par chaque test. */
    private array $row;
    private AuthService $auth;
    private string $pwOne;
    private string $pwTwo;

    protected function setUp(): void
    {
        session()->flush();

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
        kintai_test_assignments(findByUser: fn() => []);
        $this->auth = new AuthService(
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
        );
    }

    protected function tearDown(): void
    {
        session()->flush();
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
        $this->assertFalse(session()->has(auth()->getName()));
    }

    public function testSessionOfASoftDeletedUserIsRefused(): void
    {
        $this->loginNormally();
        $this->row['deleted_at'] = '2026-09-30 10:00:00';

        $this->assertFalse($this->auth->check());
    }

    public function testSessionOfAnUnknownUserIsRefused(): void
    {
        session()->put(auth()->getName(), 999);
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(null);
        $auth = new AuthService(
            $users,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
        );

        $this->assertFalse($auth->check());
    }

    public function testCurrentSessionSurvivesItsOwnPasswordChangeOnceRefreshed(): void
    {
        $this->loginNormally();
        $this->row['password_hash'] = password_hash($this->pwTwo, PASSWORD_BCRYPT, ['cost' => 4]);

        $this->auth->refreshSessionAfterPasswordChange($this->row);

        $this->assertTrue($this->auth->check());
    }

    public function testRowWithoutIsActiveKeyIsTreatedAsActive(): void
    {
        unset($this->row['is_active']);
        session()->flush();
        session()->put([auth()->getName() => 42]);

        $this->assertTrue($this->auth->check());
    }

    public function testNoSessionMeansNotLoggedIn(): void
    {
        $this->assertFalse($this->auth->check());
    }

    public function testCredentialRevokerRemovesRememberCookiesAndApiTokens(): void
    {
        $remember = $this->createMock(UserRepositoryInterface::class);
        $remember->expects($this->once())->method('revokeRememberToken')->with(42);
        $this->expectApiTokensRevoked(42);

        (new CredentialRevoker($remember))->revokeAllFor(42);
    }
}
