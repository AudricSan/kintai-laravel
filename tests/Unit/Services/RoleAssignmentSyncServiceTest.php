<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Services\RoleAssignmentSyncService;

final class RoleAssignmentSyncServiceTest extends TestCase
{
    private RoleRepositoryInterface&Stub $roles;
    private RoleAssignmentRepositoryInterface&Stub $assignments;
    private ?RoleAssignmentSyncService $service = null;

    protected function setUp(): void
    {
        $this->roles       = $this->createStub(RoleRepositoryInterface::class);
        $this->assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): RoleAssignmentSyncService
    {
        if ($this->service === null) {
            $this->service      = new RoleAssignmentSyncService($this->roles, $this->assignments);
        }

        return $this->service;
    }

    // -------------------------------------------------------------------------
    // syncOwnerRole()
    // -------------------------------------------------------------------------

    public function testSyncOwnerRoleAssignsWhenPromotedAndNotAlreadyOwner(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(10)->willReturn([]);
        $this->assignments->expects($this->once())->method('assign')->with(10, 1, 'global', null);

        $this->service()->syncOwnerRole(10, true);
    }

    public function testSyncOwnerRoleDoesNothingWhenAlreadyOwner(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->assignments->method('findByUser')->willReturn([
            ['id' => 5, 'user_id' => 10, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $this->assignments->expects($this->never())->method('assign');
        $this->assignments->expects($this->never())->method('revoke');

        $this->service()->syncOwnerRole(10, true);
    }

    public function testSyncOwnerRoleRevokesWhenDemoted(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->assignments->method('findByUser')->willReturn([
            ['id' => 5, 'user_id' => 10, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $this->assignments->expects($this->once())->method('revoke')->with(5);

        $this->service()->syncOwnerRole(10, false);
    }

    public function testSyncOwnerRoleDoesNothingWhenDemotedAndNotOwner(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->assignments->method('findByUser')->willReturn([]);
        $this->assignments->expects($this->never())->method('revoke');

        $this->service()->syncOwnerRole(10, false);
    }

    public function testSyncOwnerRoleNoopsWhenOwnerRoleMissing(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->method('findBySlug')->willReturn(null);
        $this->assignments->expects($this->never())->method('assign');
        $this->assignments->expects($this->never())->method('findByUser');

        $this->service()->syncOwnerRole(10, true);
    }

    // -------------------------------------------------------------------------
    // syncStoreRoleById()
    // -------------------------------------------------------------------------

    public function testSyncStoreRoleByIdAssignsWhenMissing(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->expects($this->atLeastOnce())->method('getPermissions')->with(2)->willReturn(['employees.view']);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(10)->willReturn([]);
        $this->assignments->expects($this->once())->method('assign')->with(10, 2, 'store', 1);

        $role = $this->service()->syncStoreRoleById(10, 1, 2);
        $this->assertSame('Manager', $role['name']);
        $this->assertTrue($role['is_managing']);
    }

    public function testSyncStoreRoleByIdReplacesExistingDifferentRoleOnSameStore(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->method('getPermissions')->willReturn([]);
        $this->assignments->method('findByUser')->willReturn([
            ['id' => 9, 'user_id' => 10, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $this->assignments->expects($this->once())->method('revoke')->with(9);
        $this->assignments->expects($this->once())->method('assign')->with(10, 2, 'store', 1);

        $this->service()->syncStoreRoleById(10, 1, 2);
    }

    public function testSyncStoreRoleByIdDoesNothingWhenAlreadyCorrect(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->method('getPermissions')->willReturn([]);
        $this->assignments->method('findByUser')->willReturn([
            ['id' => 9, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $this->assignments->expects($this->never())->method('revoke');
        $this->assignments->expects($this->never())->method('assign');

        $this->service()->syncStoreRoleById(10, 1, 2);
    }

    public function testSyncStoreRoleByIdRejectsSystemRole(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Owner', 'is_system' => 1]);
        $this->assignments->expects($this->never())->method('assign');

        $this->assertNull($this->service()->syncStoreRoleById(10, 1, 1));
    }

    public function testSyncStoreRoleByIdRejectsUnknownRole(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(null);
        $this->assignments->expects($this->never())->method('assign');

        $this->assertNull($this->service()->syncStoreRoleById(10, 1, 99));
    }

    // -------------------------------------------------------------------------
    // assignableStoreRoles() / defaultStoreRole()
    // -------------------------------------------------------------------------

    public function testAssignableStoreRolesExcludesSystemRolesAndFlagsManaging(): void
    {
        $this->roles->method('findAll')->willReturn([
            ['id' => 1, 'name' => 'Owner', 'is_system' => 1],
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
            ['id' => 3, 'name' => 'Employé', 'is_system' => 0],
        ]);
        $this->roles->method('getPermissions')->willReturnMap([
            [2, ['employees.view', 'shifts.create']],
            [3, []],
        ]);

        $roles = $this->service()->assignableStoreRoles();

        $this->assertCount(2, $roles);
        $this->assertSame([2, 3], array_map(fn($r) => $r['id'], $roles));
        $this->assertTrue($roles[0]['is_managing']);
        $this->assertFalse($roles[1]['is_managing']);
    }

    public function testDefaultStoreRolePrefersRoleWithoutPermissions(): void
    {
        $this->roles->method('findAll')->willReturn([
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
            ['id' => 3, 'name' => 'Employé', 'is_system' => 0],
        ]);
        $this->roles->method('getPermissions')->willReturnMap([
            [2, ['employees.view']],
            [3, []],
        ]);

        $this->assertSame(3, $this->service()->defaultStoreRole()['id']);
    }

    public function testDefaultStoreRoleFallsBackToFirstAssignable(): void
    {
        $this->roles->method('findAll')->willReturn([
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
        ]);
        $this->roles->method('getPermissions')->willReturn(['employees.view']);

        $this->assertSame(2, $this->service()->defaultStoreRole()['id']);
    }

    public function testDefaultStoreRoleIsNullWithoutDynamicRoles(): void
    {
        $this->roles->method('findAll')->willReturn([
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
        $this->roles->method('findAll')->willReturn([
            ['id' => 1, 'name' => 'Owner', 'is_system' => 1],
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
            ['id' => 3, 'name' => 'Employé', 'is_system' => 0],
        ]);
        $this->roles->method('getPermissions')->willReturnMap([
            [2, ['employees.view']],
            [3, []],
        ]);

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
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Owner', 'is_system' => 1]);

        $role = $this->service()->findRole(1);

        $this->assertSame('Owner', $role['name']);
        $this->assertTrue($role['is_managing']);
    }

    public function testFindRoleReturnsNonSystemRole(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->expects($this->atLeastOnce())->method('getPermissions')->with(2)->willReturn(['employees.view']);

        $role = $this->service()->findRole(2);

        $this->assertSame('Manager', $role['name']);
        $this->assertTrue($role['is_managing']);
    }

    public function testFindRoleReturnsNullForUnknownOrInvalidId(): void
    {
        $this->roles->method('findById')->willReturn(null);

        $this->assertNull($this->service()->findRole(99));
        $this->assertNull($this->service()->findRole(0));
    }

    public function testOwnerRoleDelegatesToFindBySlug(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1, 'name' => 'Owner']);

        $this->assertSame(['id' => 1, 'name' => 'Owner'], $this->service()->ownerRole());
    }

    // -------------------------------------------------------------------------
    // storeRoleMapForStore() / storeRoleMapForUser()
    // -------------------------------------------------------------------------

    public function testStoreRoleMapForStoreIndexesByUser(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByScope')->with('store', 1)->willReturn([
            ['id' => 9, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
            ['id' => 11, 'user_id' => 12, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $this->roles->method('findById')->willReturnMap([
            [2, ['id' => 2, 'name' => 'Manager', 'is_system' => 0]],
            [3, ['id' => 3, 'name' => 'Employé', 'is_system' => 0]],
        ]);
        $this->roles->method('getPermissions')->willReturnMap([
            [2, ['employees.view']],
            [3, []],
        ]);

        $map = $this->service()->storeRoleMapForStore(1);

        $this->assertSame(['role_id' => 2, 'name' => 'Manager', 'is_managing' => true], $map[10]);
        $this->assertSame(['role_id' => 3, 'name' => 'Employé', 'is_managing' => false], $map[12]);
    }

    public function testStoreRoleMapForUserIndexesByStoreAndIgnoresGlobalScope(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(10)->willReturn([
            ['id' => 5, 'user_id' => 10, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
            ['id' => 9, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 4],
        ]);
        $this->roles->method('findById')->willReturnMap([
            [2, ['id' => 2, 'name' => 'Manager', 'is_system' => 0]],
        ]);
        $this->roles->method('getPermissions')->willReturn(['employees.view']);

        $map = $this->service()->storeRoleMapForUser(10);

        $this->assertArrayNotHasKey(0, $map);
        $this->assertSame(['role_id' => 2, 'name' => 'Manager', 'is_managing' => true], $map[4]);
    }

    // -------------------------------------------------------------------------
    // revokeStoreRole()
    // -------------------------------------------------------------------------

    public function testRevokeStoreRoleRevokesMatchingAssignments(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->method('findByUser')->willReturn([
            ['id' => 9, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
            ['id' => 11, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 2],
        ]);
        $this->assignments->expects($this->once())->method('revoke')->with(9);

        $this->service()->revokeStoreRole(10, 1);
    }

    public function testRevokeStoreRoleNoopsWhenNoMatch(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->method('findByUser')->willReturn([]);
        $this->assignments->expects($this->never())->method('revoke');

        $this->service()->revokeStoreRole(10, 1);
    }
}
