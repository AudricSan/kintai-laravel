<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Auth\AuthService;
use App\Repositories\UserRepositoryInterface;
use App\Models\StoreUser;
use App\Models\Store;
use App\Models\Role;
use App\Models\RoleAssignment;

final class AuthServiceTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    private ?AuthService $auth = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_fk_off();
        session()->forget(auth()->getName());

        $this->users           = $this->createStub(UserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function auth(): AuthService
    {
        if ($this->auth === null) {
            $this->auth = new AuthService($this->users);
        }

        return $this->auth;
    }

    /** Simule une affectation Owner (portée globale, rôle système) pour cet utilisateur. */
    private function grantOwnerRole(int $userId): void
    {
        kintai_test_assignments(findByUser: fn(int $uid) => $uid === $userId ? [
                ['id' => 1, 'user_id' => $userId, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
            ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'slug' => 'owner', 'is_system' => 1] : null);
    }

    /**
     * Simule une affectation store-scope à un rôle non-système accordant $permissions.
     * $isManager pilote roles.is_manager, INDÉPENDAMMENT de $permissions (voir
     * AuthService::roleIsManagerType()) — les deux doivent être choisis explicitement
     * pour chaque test plutôt que de dépendre d'un défaut implicite.
     */
    private function grantStoreRole(int $userId, int $storeId, int $roleId, array $permissions, bool $isManager): void
    {
        kintai_test_assignments(findByUser: fn(int $uid) => $uid === $userId ? [
                ['id' => 2, 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'store', 'scope_id' => $storeId],
            ] : []);
        kintai_test_roles(findById: fn() => [
            'id' => $roleId, 'slug' => 'manager', 'is_system' => 0, 'is_manager' => $isManager ? 1 : 0,
        ], getPermissions: fn(int $id): array => $id === $roleId ? $permissions : []);
        // getPermissions n'est pas toujours lu (isManager() ne regarde que is_manager) : pas d'attente, réponse par rôle.
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
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
        $this->assertSame(10, session()->get(auth()->getName()));
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
        $store = ['id' => 5, 'code' => 'STORE01'];
        $user  = array_merge($this->activeUser(), ['employee_code' => 'EMP001']);

        kintai_test_stores(findByCode: fn() => $store);
        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP001')->willReturn($user);
        kintai_test_store_users(findMembership: fn() => ['role' => 'staff']);
        $result = $this->auth()->attemptByCode('EMP001', 'STORE01', 'secret123');
        $this->assertTrue($result);
    }

    public function testAttemptByCodeFailsForUnknownStore(): void
    {
        kintai_test_stores(findByCode: fn() => null);
        $this->assertFalse($this->auth()->attemptByCode('EMP001', 'UNKNOWN', 'pass'));
    }

    public function testAttemptByCodeFailsForUnknownEmployee(): void
    {
        kintai_test_stores(findByCode: fn() => ['id' => 1, 'code' => 'S']);
        $this->users->method('findByEmployeeCode')->willReturn(null);

        $this->assertFalse($this->auth()->attemptByCode('NOBODY', 'S', 'pass'));
    }

    public function testAttemptByCodeFailsWhenNotMember(): void
    {
        $store = ['id' => 5, 'code' => 'S'];
        $user  = $this->activeUser();
        kintai_test_stores(findByCode: fn() => $store);
        $this->users->method('findByEmployeeCode')->willReturn($user);
        kintai_test_store_users(findMembership: fn() => null);
        $this->assertFalse($this->auth()->attemptByCode('EMP', 'S', 'secret123'));
    }

    public function testAttemptByCodeNormalizesStoreCode(): void
    {
        // Code en minuscules doit être normalisé en majuscules
        kintai_test_stores(findByCode: fn() => null);
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
        session()->put(auth()->getName(), 10);
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
        session()->put(auth()->getName(), 10);
        $this->auth()->logout();
        $this->assertFalse(session()->has(auth()->getName()));
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
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantOwnerRole(10);
        $this->assertTrue($this->auth()->isAdmin());
    }

    public function testIsAdminReturnsFalseForRegularUser(): void
    {
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        kintai_test_assignments(findByUser: fn() => []);
        $this->assertFalse($this->auth()->isAdmin());
    }

    public function testIsAdminIgnoresLegacyIsAdminColumnWithoutRoleAssignment(): void
    {
        // La colonne users.is_admin brute n'est plus la source de vérité —
        // seule une affectation role_assignments de portée globale à un rôle
        // système compte désormais.
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, true));
        kintai_test_assignments(findByUser: fn() => []);
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
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantOwnerRole(10);
        $this->assertSame([], $this->auth()->managedStoreIds());
    }

    public function testManagedStoreIdsForRoleFlaggedManager(): void
    {
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 2, ['employees.view', 'employees.create'], isManager: true);

        $ids = $this->auth()->managedStoreIds();
        $this->assertSame([1], $ids);
    }

    public function testManagedStoreIdsEmptyForRoleWithoutPermissionsNorManagerFlag(): void
    {
        session()->put(auth()->getName(), 10);
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
        session()->put(auth()->getName(), 10);
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
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, ['photos.create'], isManager: false);

        $this->assertSame([], $this->auth()->managedStoreIds());
    }

    // -------------------------------------------------------------------------
    // isManager()
    // -------------------------------------------------------------------------

    public function testIsManagerTrueForGlobalAdmin(): void
    {
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantOwnerRole(10);
        $this->assertTrue($this->auth()->isManager());
    }

    public function testIsManagerTrueForRoleFlaggedManager(): void
    {
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 2, ['employees.view', 'employees.create'], isManager: true);
        $this->assertTrue($this->auth()->isManager());
    }

    public function testIsManagerFalseForRoleWithoutPermissionsNorManagerFlag(): void
    {
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, [], isManager: false);
        $this->assertFalse($this->auth()->isManager());
    }

    /** Régression : voir testManagedStoreIdsIncludesStoreForManagerFlaggedRoleGrantingOnlyViewPermissions. */
    public function testIsManagerTrueForManagerFlaggedRoleGrantingOnlyViewPermissions(): void
    {
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, ['photos.view'], isManager: true);
        $this->assertTrue($this->auth()->isManager());
    }

    /** Régression : voir testManagedStoreIdsEmptyForRoleGrantingPermissionButNotFlaggedManager. */
    public function testIsManagerFalseForRoleGrantingPermissionButNotFlaggedManager(): void
    {
        session()->put(auth()->getName(), 10);
        $this->users->method('findById')->willReturn($this->activeUser(10, false));
        $this->grantStoreRole(10, 1, 3, ['photos.create'], isManager: false);
        $this->assertFalse($this->auth()->isManager());
    }

}
