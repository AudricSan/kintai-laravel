<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Services\RoleAssignmentSyncService;

final class RoleAssignmentSyncServiceTest extends TestCase
{
    private ?RoleAssignmentSyncService $service = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_fk_off();
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): RoleAssignmentSyncService
    {
        if ($this->service === null) {
            $this->service      = new RoleAssignmentSyncService();
        }

        return $this->service;
    }

    // -------------------------------------------------------------------------
    // syncOwnerRole()
    // -------------------------------------------------------------------------

    public function testSyncOwnerRoleAssignsWhenPromotedAndNotAlreadyOwner(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [] : []);
        $this->service()->syncOwnerRole(10, true);
        $this->assertTrue(\App\Models\RoleAssignment::query()->where('user_id', 10)->where('role_id', 1)->where('scope_type', 'global')->whereNull('scope_id')->exists(), 'affectation créée');
    }

    public function testSyncOwnerRoleDoesNothingWhenAlreadyOwner(): void
    {
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 5, 'user_id' => 10, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $assignmentsBefore = \App\Models\RoleAssignment::query()->count();
        $revokeBefore = \App\Models\RoleAssignment::query()->count();

        $this->service()->syncOwnerRole(10, true);
        $this->assertSame($assignmentsBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation créée');
        $this->assertSame($revokeBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation révoquée');
    }

    public function testSyncOwnerRoleRevokesWhenDemoted(): void
    {
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 5, 'user_id' => 10, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $this->service()->syncOwnerRole(10, false);
        $this->assertNull(\App\Models\RoleAssignment::findById(5), 'affectation révoquée');
    }

    public function testSyncOwnerRoleDoesNothingWhenDemotedAndNotOwner(): void
    {
        kintai_test_assignments(findByUser: fn() => []);
        $revokeBefore = \App\Models\RoleAssignment::query()->count();

        $this->service()->syncOwnerRole(10, false);
        $this->assertSame($revokeBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation révoquée');
    }

    public function testSyncOwnerRoleNoopsWhenOwnerRoleMissing(): void
    {
        $assignmentsBefore = \App\Models\RoleAssignment::query()->count();
        \Illuminate\Support\Facades\DB::table('role_assignments')->delete();
        \Illuminate\Support\Facades\DB::table('roles')->where('slug', 'owner')->delete();
        \App\Database\RequestMemo::flush();

        $this->service()->syncOwnerRole(10, true);
        $this->assertSame($assignmentsBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation créée');
    }

    // -------------------------------------------------------------------------
    // syncStoreRoleById()
    // -------------------------------------------------------------------------

    public function testSyncStoreRoleByIdAssignsWhenMissing(): void
    {
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'name' => 'Manager', 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['employees.view'] : []);
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [] : []);
        $role = $this->service()->syncStoreRoleById(10, 1, 2);
        $this->assertTrue(\App\Models\RoleAssignment::query()->where('user_id', 10)->where('role_id', 2)->where('scope_type', 'store')->where('scope_id', 1)->exists(), 'affectation créée');
        $this->assertSame('Manager', $role['name']);
        $this->assertTrue($role['is_managing']);
    }

    public function testSyncStoreRoleByIdReplacesExistingDifferentRoleOnSameStore(): void
    {
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'name' => 'Manager', 'is_system' => 0] : null, getPermissions: fn() => []);
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 9, 'user_id' => 10, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $this->service()->syncStoreRoleById(10, 1, 2);
        $this->assertNull(\App\Models\RoleAssignment::findById(9), 'affectation révoquée');
        $this->assertTrue(\App\Models\RoleAssignment::query()->where('user_id', 10)->where('role_id', 2)->where('scope_type', 'store')->where('scope_id', 1)->exists(), 'affectation créée');
    }

    public function testSyncStoreRoleByIdDoesNothingWhenAlreadyCorrect(): void
    {
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'name' => 'Manager', 'is_system' => 0] : null, getPermissions: fn() => []);
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 9, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $revokeBefore = \App\Models\RoleAssignment::query()->count();
        $assignmentsBefore = \App\Models\RoleAssignment::query()->count();

        $this->service()->syncStoreRoleById(10, 1, 2);
        $this->assertSame($revokeBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation révoquée');
        $this->assertSame($assignmentsBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation créée');
    }

    public function testSyncStoreRoleByIdRejectsSystemRole(): void
    {
        kintai_test_roles(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Owner', 'is_system' => 1] : null);
        $assignmentsBefore = \App\Models\RoleAssignment::query()->count();

        $this->assertSame($assignmentsBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation créée');
        $this->assertNull($this->service()->syncStoreRoleById(10, 1, 1));
    }

    public function testSyncStoreRoleByIdRejectsUnknownRole(): void
    {
        kintai_test_roles(findById: fn() => null);
        $assignmentsBefore = \App\Models\RoleAssignment::query()->count();

        $this->assertSame($assignmentsBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation créée');
        $this->assertNull($this->service()->syncStoreRoleById(10, 1, 99));
    }

    // -------------------------------------------------------------------------
    // assignableStoreRoles() / defaultStoreRole()
    // -------------------------------------------------------------------------

    public function testAssignableStoreRolesExcludesSystemRolesAndFlagsManaging(): void
    {
        kintai_test_role_table([
            ['id' => 1, 'name' => 'Owner', 'is_system' => 1],
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
            ['id' => 3, 'name' => 'Employé', 'is_system' => 0],
        ]);
        kintai_test_roles(getPermissions: fn(int $id) => array_column([
            [2, ['employees.view', 'shifts.create']],
            [3, []],
        ], 1, 0)[$id] ?? []);
        $roles = $this->service()->assignableStoreRoles();

        $this->assertCount(2, $roles);
        $this->assertSame([2, 3], array_map(fn($r) => $r['id'], $roles));
        $this->assertTrue($roles[0]['is_managing']);
        $this->assertFalse($roles[1]['is_managing']);
    }

    public function testDefaultStoreRolePrefersRoleWithoutPermissions(): void
    {
        kintai_test_role_table([
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
            ['id' => 3, 'name' => 'Employé', 'is_system' => 0],
        ]);
        kintai_test_roles(getPermissions: fn(int $id) => array_column([
            [2, ['employees.view']],
            [3, []],
        ], 1, 0)[$id] ?? []);
        $this->assertSame(3, $this->service()->defaultStoreRole()['id']);
    }

    public function testDefaultStoreRoleFallsBackToFirstAssignable(): void
    {
        kintai_test_role_table([
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
        ]);
        kintai_test_roles(getPermissions: fn() => ['employees.view']);
        $this->assertSame(2, $this->service()->defaultStoreRole()['id']);
    }

    public function testDefaultStoreRoleIsNullWithoutDynamicRoles(): void
    {
        kintai_test_role_table([
            ['id' => 1, 'name' => 'Owner', 'is_system' => 1],
        ]);

        $this->assertNull($this->service()->defaultStoreRole());
    }

    // -------------------------------------------------------------------------
    // allRoles() / findRole() / ownerRole() — sélecteur "Rôle" unifié du
    // formulaire de création d'utilisateur (Owner + rôles par store)
    // -------------------------------------------------------------------------

    public function testAllRolesIncludesSystemRolesAndFlagsManaging(): void
    {
        kintai_test_role_table([
            ['id' => 1, 'name' => 'Owner', 'is_system' => 1],
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
            ['id' => 3, 'name' => 'Employé', 'is_system' => 0],
        ]);
        kintai_test_roles(getPermissions: fn(int $id) => array_column([
            [2, ['employees.view']],
            [3, []],
        ], 1, 0)[$id] ?? []);
        $roles = $this->service()->allRoles();

        $this->assertCount(3, $roles);
        $this->assertSame([1, 2, 3], array_map(fn($r) => $r['id'], $roles));
        // Owner : is_managing forcé à true sans appeler getPermissions (rôle système).
        $this->assertTrue($roles[0]['is_managing']);
        $this->assertTrue($roles[1]['is_managing']);
        $this->assertFalse($roles[2]['is_managing']);
    }

    public function testFindRoleReturnsSystemRole(): void
    {
        kintai_test_roles(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Owner', 'is_system' => 1] : null);
        $role = $this->service()->findRole(1);

        $this->assertSame('Owner', $role['name']);
        $this->assertTrue($role['is_managing']);
    }

    public function testFindRoleReturnsNonSystemRole(): void
    {
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'name' => 'Manager', 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['employees.view'] : []);
        $role = $this->service()->findRole(2);

        $this->assertSame('Manager', $role['name']);
        $this->assertTrue($role['is_managing']);
    }

    public function testFindRoleReturnsNullForUnknownOrInvalidId(): void
    {
        kintai_test_roles(findById: fn() => null);
        $this->assertNull($this->service()->findRole(99));
        $this->assertNull($this->service()->findRole(0));
    }

    public function testOwnerRoleDelegatesToFindBySlug(): void
    {
        $owner = $this->service()->ownerRole();

        $this->assertSame(1, $owner['id']);
        $this->assertSame('owner', $owner['slug']);
    }

    // -------------------------------------------------------------------------
    // storeRoleMapForStore() / storeRoleMapForUser()
    // -------------------------------------------------------------------------

    public function testStoreRoleMapForStoreIndexesByUser(): void
    {
        kintai_test_assignments(findByScope: fn() => [
            ['id' => 9, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
            ['id' => 11, 'user_id' => 12, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        kintai_test_roles(findById: fn(int $id) => array_column([
            [2, ['id' => 2, 'name' => 'Manager', 'is_system' => 0]],
            [3, ['id' => 3, 'name' => 'Employé', 'is_system' => 0]],
        ], 1, 0)[$id] ?? null, getPermissions: static fn(int $id) => array_column([
            [2, ['employees.view']],
            [3, []],
        ], 1, 0)[$id] ?? []);
        $map = $this->service()->storeRoleMapForStore(1);

        $this->assertSame(['role_id' => 2, 'name' => 'Manager', 'is_managing' => true], $map[10]);
        $this->assertSame(['role_id' => 3, 'name' => 'Employé', 'is_managing' => false], $map[12]);
    }

    public function testStoreRoleMapForUserIndexesByStoreAndIgnoresGlobalScope(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 5, 'user_id' => 10, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
            ['id' => 9, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 4],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => array_column([
            [2, ['id' => 2, 'name' => 'Manager', 'is_system' => 0]],
        ], 1, 0)[$id] ?? null, getPermissions: static fn() => ['employees.view']);
        $map = $this->service()->storeRoleMapForUser(10);

        $this->assertArrayNotHasKey(0, $map);
        $this->assertSame(['role_id' => 2, 'name' => 'Manager', 'is_managing' => true], $map[4]);
    }

    // -------------------------------------------------------------------------
    // revokeStoreRole()
    // -------------------------------------------------------------------------

    public function testRevokeStoreRoleRevokesMatchingAssignments(): void
    {
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 9, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
            ['id' => 11, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 2],
        ]);
        $this->service()->revokeStoreRole(10, 1);
        $this->assertNull(\App\Models\RoleAssignment::findById(9), 'affectation révoquée');
    }

    public function testRevokeStoreRoleNoopsWhenNoMatch(): void
    {
        kintai_test_assignments(findByUser: fn() => []);
        $revokeBefore = \App\Models\RoleAssignment::query()->count();

        $this->service()->revokeStoreRole(10, 1);
        $this->assertSame($revokeBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation révoquée');
    }
}
