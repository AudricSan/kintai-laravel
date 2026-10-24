<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Auth\AuthService;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;

final class AuthServiceTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private StoreRepositoryInterface&Stub $stores;
    private RoleRepositoryInterface&Stub $roles;
    private RoleAssignmentRepositoryInterface&Stub $roleAssignments;
    private RememberTokenRepositoryInterface&Stub $rememberTokens;
    private ?AuthService $auth = null;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        unset($_SESSION['auth_user_id']);
        unset($_COOKIE['kintai_remember']);

        $this->users           = $this->createStub(UserRepositoryInterface::class);
        $this->storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $this->stores          = $this->createStub(StoreRepositoryInterface::class);
        $this->roles           = $this->createStub(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->rememberTokens  = $this->createStub(RememberTokenRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function auth(): AuthService
    {
        if ($this->auth === null) {
            $this->auth = new AuthService($this->users, $this->storeUsers, $this->stores, $this->roles, $this->roleAssignments, $this->rememberTokens);
        }

        return $this->auth;
    }

    /** Simule une affectation Owner (portée globale, rôle système) pour cet utilisateur. */
    private function grantOwnerRole(int $userId): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments->method('findByUser')->willReturnCallback(
            fn(int $uid) => $uid === $userId ? [
                ['id' => 1, 'user_id' => $userId, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
            ] : []
        );
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'slug' => 'owner', 'is_system' => 1]);
    }

    /**
     * Simule une affectation store-scope à un rôle non-système accordant $permissions.
     * $isManager pilote roles.is_manager, INDÉPENDAMMENT de $permissions (voir
     * AuthService::roleIsManagerType()) — les deux doivent être choisis explicitement
     * pour chaque test plutôt que de dépendre d'un défaut implicite.
     */
    private function grantStoreRole(int $userId, int $storeId, int $roleId, array $permissions, bool $isManager): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments->method('findByUser')->willReturnCallback(
            fn(int $uid) => $uid === $userId ? [
                ['id' => 2, 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'store', 'scope_id' => $storeId],
            ] : []
        );
        $this->roles->expects($this->atLeastOnce())->method('findById')->with($roleId)->willReturn([
            'id' => $roleId, 'slug' => 'manager', 'is_system' => 0, 'is_manager' => $isManager ? 1 : 0,
        ]);
        // getPermissions n'est pas toujours lu (isManager() ne regarde que is_manager) : pas d'attente, réponse par rôle.
        $this->roles->method('getPermissions')->willReturnCallback(fn(int $id): array => $id === $roleId ? $permissions : []);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
        unset($_COOKIE['kintai_remember']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function activeUser(int $id = 10, bool $isAdmin = false): array
    {
        return [
            'id'            => $id,
            'email'         => 'user@test.com',
            'is_active'     => 1,
            'is_admin'      => $isAdmin ? 1 : 0,
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'deleted_at'    => null,
        ];
    }

    // -------------------------------------------------------------------------
    // attempt()
    // -------------------------------------------------------------------------

    public function testAttemptSucceedsWithValidCredentials(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $user = $this->activeUser();
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('user@test.com')->willReturn($user);

        $result = $this->auth()->attempt('user@test.com', 'secret123');

        $this->assertTrue($result);
        $this->assertSame(10, $_SESSION['auth_user_id']);
    }

    public function testAttemptFailsForUnknownEmail(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->assertFalse($this->auth()->attempt('nobody@test.com', 'pass'));
    }

    public function testAttemptFailsForWrongPassword(): void
    {
        $user = $this->activeUser();
        $this->users->method('findByEmail')->willReturn($user);

        $this->assertFalse($this->auth()->attempt('user@test.com', 'wrongpassword'));
    }

    public function testAttemptFailsForInactiveUser(): void
    {
        $user               = $this->activeUser();
        $user['is_active']  = 0;
        $this->users->method('findByEmail')->willReturn($user);

        $this->assertFalse($this->auth()->attempt('user@test.com', 'secret123'));
    }

    public function testAttemptFailsForSoftDeletedUser(): void
    {
        $user                = $this->activeUser();
        $user['deleted_at']  = '2025-01-01 00:00:00';
        $this->users->method('findByEmail')->willReturn($user);

        $this->assertFalse($this->auth()->attempt('user@test.com', 'secret123'));
    }

    // -------------------------------------------------------------------------
    // attemptByCode()
    // -------------------------------------------------------------------------

    public function testAttemptByCodeSucceeds(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $store = ['id' => 5, 'code' => 'STORE01'];
        $user  = array_merge($this->activeUser(), ['employee_code' => 'EMP001']);

        $this->stores->expects($this->atLeastOnce())->method('findByCode')->with('STORE01')->willReturn($store);
        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP001')->willReturn($user);
        $this->storeUsers->expects($this->atLeastOnce())->method('findMembership')->with(5, 10)->willReturn(['role' => 'staff']);

        $result = $this->auth()->attemptByCode('EMP001', 'STORE01', 'secret123');
        $this->assertTrue($result);
    }

    public function testAttemptByCodeFailsForUnknownStore(): void
    {
        $this->stores->method('findByCode')->willReturn(null);
        $this->assertFalse($this->auth()->attemptByCode('EMP001', 'UNKNOWN', 'pass'));
    }

    public function testAttemptByCodeFailsForUnknownEmployee(): void
    {
        $this->stores->method('findByCode')->willReturn(['id' => 1, 'code' => 'S']);
        $this->users->method('findByEmployeeCode')->willReturn(null);

        $this->assertFalse($this->auth()->attemptByCode('NOBODY', 'S', 'pass'));
    }

    public function testAttemptByCodeFailsWhenNotMember(): void
    {
        $store = ['id' => 5, 'code' => 'S'];
        $user  = $this->activeUser();
        $this->stores->method('findByCode')->willReturn($store);
        $this->users->method('findByEmployeeCode')->willReturn($user);
        $this->storeUsers->method('findMembership')->willReturn(null);

        $this->assertFalse($this->auth()->attemptByCode('EMP', 'S', 'secret123'));
    }

    public function testAttemptByCodeNormalizesStoreCode(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        // Code en minuscules doit être normalisé en majuscules
        $this->stores->expects($this->once())
            ->method('findByCode')
            ->with('STORE01')
            ->willReturn(null);

        $this->auth()->attemptByCode('EMP', 'store01', 'pass');
    }

    // -------------------------------------------------------------------------
    // check()
    // -------------------------------------------------------------------------

    public function testCheckReturnsFalseWhenNotLoggedIn(): void
    {
        $this->assertFalse($this->auth()->check());
    }

    public function testCheckReturnsTrueAfterLogin(): void
    {
        $user = $this->activeUser();
        $this->users->method('findByEmail')->willReturn($user);
        // check() recharge désormais l'utilisateur (compte actif, mot de passe inchangé)
        // au lieu de se contenter de lire l'identifiant en session.
        $this->users->method('findById')->willReturn($user);
        $this->auth()->attempt('user@test.com', 'secret123');

        $this->assertTrue($this->auth()->check());
    }

    // -------------------------------------------------------------------------
    // user()
    // -------------------------------------------------------------------------

    public function testUserReturnsNullWhenNotLoggedIn(): void
    {
        $this->assertNull($this->auth()->user());
    }

    public function testUserReturnsUserFromDatabase(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $_SESSION['auth_user_id'] = 10;
        $user = $this->activeUser();
        $this->users->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($user);

        $result = $this->auth()->user();
        $this->assertSame(10, $result['id']);
    }

    // -------------------------------------------------------------------------
    // logout()
    // -------------------------------------------------------------------------

    public function testLogoutClearsSession(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->auth()->logout();
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
    }

    public function testCheckReturnsFalseAfterLogout(): void
    {
        $user = $this->activeUser();
        $this->users->method('findByEmail')->willReturn($user);
        $this->auth()->attempt('user@test.com', 'secret123');

        $this->auth()->logout();
        $this->assertFalse($this->auth()->check());
    }

    // -------------------------------------------------------------------------
    // isAdmin()
    // -------------------------------------------------------------------------

    public function testIsAdminReturnsFalseWhenNotLoggedIn(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->assertFalse($this->auth()->isAdmin());
    }

    public function testIsAdminReturnsTrueForOwnerRoleAssignment(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantOwnerRole(10);
        $this->assertTrue($this->auth()->isAdmin());
    }

    public function testIsAdminReturnsFalseForRegularUser(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->roleAssignments->method('findByUser')->willReturn([]);
        $this->assertFalse($this->auth()->isAdmin());
    }

    public function testIsAdminIgnoresLegacyIsAdminColumnWithoutRoleAssignment(): void
    {
        // La colonne users.is_admin brute n'est plus la source de vérité —
        // seule une affectation role_assignments de portée globale à un rôle
        // système compte désormais.
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, true));
        $this->roleAssignments->method('findByUser')->willReturn([]);
        $this->assertFalse($this->auth()->isAdmin());
    }

    // -------------------------------------------------------------------------
    // managedStoreIds()
    // -------------------------------------------------------------------------

    public function testManagedStoreIdsEmptyWhenNotLoggedIn(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->assertSame([], $this->auth()->managedStoreIds());
    }

    public function testManagedStoreIdsEmptyForGlobalAdmin(): void
    {
        // Admin global → retourne [] (pas de restriction)
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantOwnerRole(10);
        $this->assertSame([], $this->auth()->managedStoreIds());
    }

    public function testManagedStoreIdsForRoleFlaggedManager(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 2, ['employees.view', 'employees.create'], isManager: true);

        $ids = $this->auth()->managedStoreIds();
        $this->assertSame([1], $ids);
    }

    public function testManagedStoreIdsEmptyForRoleWithoutPermissionsNorManagerFlag(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, [], isManager: false); // rôle sans permission ni flag manager (ex. Employé)

        $this->assertSame([], $this->auth()->managedStoreIds());
    }

    /**
     * Régression (12/09/2026) : un rôle qui n'accorde QUE des permissions .view (ex. un rôle
     * custom "lecture seule sur les rapports photos", avec seulement photos.view) doit pouvoir
     * compter comme gestionnaire de ce store QUAND il est explicitement marqué is_manager —
     * sinon ce rôle ne peut jamais atteindre /admin/*, même pour consulter exactement ce que sa
     * permission autorise. PermissionMiddleware reste la barrière fine qui limite ensuite
     * l'accès à cette seule permission.
     */
    public function testManagedStoreIdsIncludesStoreForManagerFlaggedRoleGrantingOnlyViewPermissions(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, ['photos.view'], isManager: true);

        $this->assertSame([1], $this->auth()->managedStoreIds());
    }

    /**
     * Régression (16/09/2026) : le problème inverse — un rôle "Employé" auquel on a accordé une
     * permission purement en libre-service (ex. photos.create, pour poster sa propre photo de
     * store) ne doit PAS basculer en navigation manager tant que is_manager n'est pas coché
     * explicitement. Avant roles.is_manager, roleGrantsAnyPermission() faisait cette promotion
     * par erreur dès qu'une permission quelconque était accordée.
     */
    public function testManagedStoreIdsEmptyForRoleGrantingPermissionButNotFlaggedManager(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, ['photos.create'], isManager: false);

        $this->assertSame([], $this->auth()->managedStoreIds());
    }

    // -------------------------------------------------------------------------
    // isManager()
    // -------------------------------------------------------------------------

    public function testIsManagerTrueForGlobalAdmin(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantOwnerRole(10);
        $this->assertTrue($this->auth()->isManager());
    }

    public function testIsManagerTrueForRoleFlaggedManager(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 2, ['employees.view', 'employees.create'], isManager: true);
        $this->assertTrue($this->auth()->isManager());
    }

    public function testIsManagerFalseForRoleWithoutPermissionsNorManagerFlag(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, [], isManager: false);
        $this->assertFalse($this->auth()->isManager());
    }

    /** Régression : voir testManagedStoreIdsIncludesStoreForManagerFlaggedRoleGrantingOnlyViewPermissions. */
    public function testIsManagerTrueForManagerFlaggedRoleGrantingOnlyViewPermissions(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, ['photos.view'], isManager: true);
        $this->assertTrue($this->auth()->isManager());
    }

    /** Régression : voir testManagedStoreIdsEmptyForRoleGrantingPermissionButNotFlaggedManager. */
    public function testIsManagerFalseForRoleGrantingPermissionButNotFlaggedManager(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, ['photos.create'], isManager: false);
        $this->assertFalse($this->auth()->isManager());
    }

    // -------------------------------------------------------------------------
    // "Rester connecté" (remember me, 30 jours)
    // -------------------------------------------------------------------------

    public function testAttemptWithRememberIssuesToken(): void
    {
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $user = $this->activeUser();
        $this->users->method('findByEmail')->willReturn($user);
        $this->rememberTokens->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data) => $data['user_id'] === 10
                && isset($data['selector'], $data['validator_hash'], $data['expires_at'])))
            ->willReturn([]);

        $result = $this->auth()->attempt('user@test.com', 'secret123', remember: true);

        $this->assertTrue($result);
    }

    public function testAttemptWithoutRememberDoesNotIssueToken(): void
    {
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $user = $this->activeUser();
        $this->users->method('findByEmail')->willReturn($user);
        $this->rememberTokens->expects($this->never())->method('create');

        $this->auth()->attempt('user@test.com', 'secret123');
    }

    public function testAttemptByCodeWithRememberIssuesToken(): void
    {
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $store = ['id' => 5, 'code' => 'STORE01'];
        $user  = array_merge($this->activeUser(), ['employee_code' => 'EMP001']);
        $this->stores->method('findByCode')->willReturn($store);
        $this->users->method('findByEmployeeCode')->willReturn($user);
        $this->storeUsers->method('findMembership')->willReturn(['role' => 'staff']);
        $this->rememberTokens->expects($this->once())->method('create')->willReturn([]);

        $result = $this->auth()->attemptByCode('EMP001', 'STORE01', 'secret123', remember: true);

        $this->assertTrue($result);
    }

    public function testAttemptViaRememberCookieFailsWithMalformedCookie(): void
    {
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->rememberTokens->expects($this->never())->method('findBySelector');
        $this->assertFalse($this->auth()->attemptViaRememberCookie('not-a-valid-cookie'));
    }

    public function testAttemptViaRememberCookieFailsWithUnknownSelector(): void
    {
        $this->rememberTokens->method('findBySelector')->willReturn(null);
        $this->assertFalse($this->auth()->attemptViaRememberCookie('selector123.validator456'));
    }

    public function testAttemptViaRememberCookieFailsWithExpiredToken(): void
    {
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->rememberTokens->method('findBySelector')->willReturn([
            'user_id'        => 10,
            'selector'       => 'selector123',
            'validator_hash' => hash('sha256', 'validator456'),
            'expires_at'     => date('Y-m-d H:i:s', time() - 3600),
        ]);
        $this->rememberTokens->expects($this->once())->method('deleteBySelector')->with('selector123');

        $this->assertFalse($this->auth()->attemptViaRememberCookie('selector123.validator456'));
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
    }

    public function testAttemptViaRememberCookieFailsWithWrongValidator(): void
    {
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->rememberTokens->method('findBySelector')->willReturn([
            'user_id'        => 10,
            'selector'       => 'selector123',
            'validator_hash' => hash('sha256', 'the-real-validator'),
            'expires_at'     => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->rememberTokens->expects($this->once())->method('deleteBySelector')->with('selector123');

        $this->assertFalse($this->auth()->attemptViaRememberCookie('selector123.wrong-validator'));
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
    }

    public function testAttemptViaRememberCookieFailsForInactiveUser(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->rememberTokens->method('findBySelector')->willReturn([
            'user_id'        => 10,
            'selector'       => 'selector123',
            'validator_hash' => hash('sha256', 'validator456'),
            'expires_at'     => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $user              = $this->activeUser();
        $user['is_active'] = 0;
        $this->users->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($user);
        $this->rememberTokens->expects($this->once())->method('deleteBySelector')->with('selector123');

        $this->assertFalse($this->auth()->attemptViaRememberCookie('selector123.validator456'));
    }

    public function testAttemptViaRememberCookieSucceedsAndRotatesToken(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->rememberTokens->method('findBySelector')->willReturn([
            'user_id'        => 10,
            'selector'       => 'selector123',
            'validator_hash' => hash('sha256', 'validator456'),
            'expires_at'     => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($this->activeUser());
        // Rotation : l'ancien sélecteur est supprimé, un nouveau token est émis.
        $this->rememberTokens->expects($this->once())->method('deleteBySelector')->with('selector123');
        $this->rememberTokens->expects($this->once())->method('create')->willReturn([]);

        $result = $this->auth()->attemptViaRememberCookie('selector123.validator456');

        $this->assertTrue($result);
        $this->assertSame(10, $_SESSION['auth_user_id']);
    }

    public function testLogoutRevokesMatchingRememberToken(): void
    {
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        $_COOKIE['kintai_remember'] = 'selector123.validator456';
        $this->rememberTokens->expects($this->once())->method('deleteBySelector')->with('selector123');

        $this->auth()->logout();
    }

    public function testLogoutWithoutRememberCookieDoesNotCallRepository(): void
    {
        $this->rememberTokens = $this->createMock(RememberTokenRepositoryInterface::class);
        unset($_COOKIE['kintai_remember']);
        $this->rememberTokens->expects($this->never())->method('deleteBySelector');

        $this->auth()->logout();
    }
}
