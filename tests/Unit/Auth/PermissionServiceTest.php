<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Auth\PermissionService;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Models\Role;

final class PermissionServiceTest extends TestCase
{
    private RoleAssignmentRepositoryInterface&Stub $assignments;
    private ?PermissionService $service = null;

    protected function setUp(): void
    {
        $this->assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): PermissionService
    {
        if ($this->service === null) {
            $this->service     = new PermissionService($this->assignments);
        }

        return $this->service;
    }

    private function role(int $id, bool $isSystem = false): array
    {
        return ['id' => $id, 'name' => 'Role ' . $id, 'slug' => 'role-' . $id, 'is_system' => $isSystem ? 1 : 0];
    }

    // -------------------------------------------------------------------------
    // can() — utilisateur invalide
    // -------------------------------------------------------------------------

    public function testCanReturnsFalseForMissingUserId(): void
    {
        $this->assertFalse($this->service()->can([], 'employees.create'));
    }

    // -------------------------------------------------------------------------
    // can() — rôle système (Owner)
    // -------------------------------------------------------------------------

    public function testCanReturnsTrueForSystemRoleRegardlessOfPermissionsTable(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(1)->willReturn([
            ['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 10 ? $this->role(10, isSystem: true) : null);

        $this->assertTrue($this->service()->can(['id' => 1], 'settings.update', 5));
    }

    // -------------------------------------------------------------------------
    // can() — rôle store-scope
    // -------------------------------------------------------------------------

    public function testCanReturnsTrueWhenRoleGrantsPermissionOnMatchingStore(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 5],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getPermissions: fn(int $id) => $id === 20 ? ['employees.create'] : []);
        $this->assertTrue($this->service()->can(['id' => 2], 'employees.create', 5));
    }

    public function testCanReturnsFalseWhenStoreDoesNotMatch(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 5],
        ]);
        kintai_test_roles(findById: fn() => $this->role(20), getPermissions: fn() => ['employees.create']);
        $this->assertFalse($this->service()->can(['id' => 2], 'employees.create', 6));
    }

    public function testCanReturnsFalseWhenRoleDoesNotGrantPermission(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 5],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getPermissions: fn(int $id) => $id === 20 ? ['employees.view'] : []);
        $this->assertFalse($this->service()->can(['id' => 2], 'employees.delete', 5));
    }

    public function testCanWithNullStoreMatchesAnyScope(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 5],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getPermissions: fn(int $id) => $id === 20 ? ['employees.view'] : []);
        $this->assertTrue($this->service()->can(['id' => 2], 'employees.view'));
    }

    public function testCanUnionsPermissionsAcrossMultipleRoles(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(3)->willReturn([
            ['id' => 1, 'user_id' => 3, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 5],
            ['id' => 2, 'user_id' => 3, 'role_id' => 21, 'scope_type' => 'store', 'scope_id' => 5],
        ]);
        kintai_test_roles(findById: fn(int $id) => array_column([
            [20, $this->role(20)],
            [21, $this->role(21)],
        ], 1, 0)[$id] ?? null, getPermissions: static fn(int $id) => array_column([
            [20, ['employees.view']],
            [21, ['employees.delete']],
        ], 1, 0)[$id] ?? []);
        $this->assertTrue($this->service()->can(['id' => 3], 'employees.delete', 5));
    }

    // -------------------------------------------------------------------------
    // scopedStoreIds()
    // -------------------------------------------------------------------------

    public function testScopedStoreIdsReturnsStoresGrantingThatPermission(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(4)->willReturn([
            ['id' => 1, 'user_id' => 4, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 5],
            ['id' => 2, 'user_id' => 4, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 6],
            ['id' => 3, 'user_id' => 4, 'role_id' => 21, 'scope_type' => 'store', 'scope_id' => 7],
        ]);
        kintai_test_roles(findById: fn(int $id) => array_column([
            [20, $this->role(20)],
            [21, $this->role(21)],
        ], 1, 0)[$id] ?? null, getPermissions: static fn(int $id) => array_column([
            [20, ['employees.view']],
            [21, ['shifts.view']],
        ], 1, 0)[$id] ?? []);
        $result = $this->service()->scopedStoreIds(4, 'employees.view');
        $this->assertSame([5, 6], $result);
    }

    public function testScopedStoreIdsExcludesGlobalAssignments(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(1)->willReturn([
            ['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn() => $this->role(10, isSystem: true));
        $this->assertSame([], $this->service()->scopedStoreIds(1, 'employees.view'));
    }

    // -------------------------------------------------------------------------
    // restrictToScope()
    // -------------------------------------------------------------------------

    public function testRestrictToScopeKeepsOnlyItemsFromScopedStores(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 5],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getPermissions: fn(int $id) => $id === 20 ? ['timeoff.view'] : []);
        $items = [
            ['id' => 1, 'store_id' => 5],
            ['id' => 2, 'store_id' => 9],
        ];

        $result = $this->service()->restrictToScope(['id' => 2], 'timeoff.view', $items);

        $this->assertSame([['id' => 1, 'store_id' => 5]], $result);
    }

    public function testRestrictToScopeReturnsEverythingForGlobalRole(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(1)->willReturn([
            ['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 10 ? $this->role(10, isSystem: true) : null);
        $items = [
            ['id' => 1, 'store_id' => 5],
            ['id' => 2, 'store_id' => 9],
        ];

        $result = $this->service()->restrictToScope(['id' => 1], 'timeoff.view', $items);

        $this->assertSame($items, $result);
    }

    public function testRestrictToScopeReturnsEmptyWithoutAnyGrant(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(3)->willReturn([]);

        $items = [['id' => 1, 'store_id' => 5]];

        $this->assertSame([], $this->service()->restrictToScope(['id' => 3], 'timeoff.view', $items));
    }

    // -------------------------------------------------------------------------
    // Portée globale par permission (roles.role_permissions.scope='global') —
    // un rôle store-scope peut accorder certaines permissions sur toutes les
    // boutiques (ex. shifts.view) tout en restant restreint pour les autres
    // (ex. shifts.update), sans changer la portée de l'affectation elle-même.
    // -------------------------------------------------------------------------

    public function testCanTreatsGlobalScopedPermissionAsMatchingAnyStore(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getPermissions: fn(int $id) => $id === 20 ? ['shifts.view'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 20 ? ['shifts.view'] : []);
        $this->assertTrue($this->service()->can(['id' => 2], 'shifts.view', 999));
    }

    public function testCanStillRestrictsLocallyScopedPermissionToItsStore(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getPermissions: fn(int $id) => $id === 20 ? ['shifts.view', 'shifts.update'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 20 ? ['shifts.view'] : []);
        $this->assertFalse($this->service()->can(['id' => 2], 'shifts.update', 999));
        $this->assertTrue($this->service()->can(['id' => 2], 'shifts.update', 3));
    }

    public function testScopedStoreIdsExcludesStoreForGloballyScopedPermission(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getPermissions: fn(int $id) => $id === 20 ? ['shifts.view'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 20 ? ['shifts.view'] : []);
        $this->assertSame([], $this->service()->scopedStoreIds(2, 'shifts.view'));
    }

    public function testScopedStoreIdsStillIncludesStoreForLocallyScopedPermission(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getPermissions: fn(int $id) => $id === 20 ? ['shifts.view', 'shifts.update'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 20 ? ['shifts.view'] : []);
        $this->assertSame([3], $this->service()->scopedStoreIds(2, 'shifts.update'));
    }

    public function testGlobalScopeAssignmentUnaffectedByPermissionScopeFlag(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(1)->willReturn([
            ['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 10 ? $this->role(10) : null, getPermissions: fn(int $id) => $id === 10 ? ['shifts.update'] : []);

        $this->assertTrue($this->service()->can(['id' => 1], 'shifts.update', 999));
    }

    // -------------------------------------------------------------------------
    // hasAnyGlobalPermissionGrant() — porte grossière des routes 'public', doit
    // détecter qu'un rôle rend AU MOINS UNE permission globale, peu importe le
    // scope_type de l'affectation qui relie l'utilisateur à ce rôle. Complète
    // anyGrantedStoreIds(), qui ignore complètement getGlobalPermissionKeys().
    // -------------------------------------------------------------------------

    public function testHasAnyGlobalPermissionGrantTrueForStoreScopedAssignmentWithGloballyFlaggedPermission(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        // Repro directe du bug : photos.view marquée globale sur un rôle dont
        // l'affectation reste store-scope sur le magasin d'origine.
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getGlobalPermissionKeys: fn(int $id) => $id === 20 ? ['photos.view'] : []);
        $this->assertTrue($this->service()->hasAnyGlobalPermissionGrant(['id' => 2]));
    }

    public function testHasAnyGlobalPermissionGrantTrueForSystemRole(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(1)->willReturn([
            ['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 10 ? $this->role(10, isSystem: true) : null);

        $this->assertTrue($this->service()->hasAnyGlobalPermissionGrant(['id' => 1]));
    }

    public function testHasAnyGlobalPermissionGrantTrueRegardlessOfAssignmentScopeType(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getGlobalPermissionKeys: fn(int $id) => $id === 20 ? ['photos.view'] : []);
        $this->assertTrue($this->service()->hasAnyGlobalPermissionGrant(['id' => 2]));
    }

    public function testHasAnyGlobalPermissionGrantFalseWithoutAnyGlobalPermissionOrSystemRole(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? $this->role(20) : null, getGlobalPermissionKeys: fn(int $id) => $id === 20 ? [] : []);
        $this->assertFalse($this->service()->hasAnyGlobalPermissionGrant(['id' => 2]));
    }

    public function testHasAnyGlobalPermissionGrantFalseWithoutAnyAssignment(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([]);

        $this->assertFalse($this->service()->hasAnyGlobalPermissionGrant(['id' => 2]));
    }

    // -------------------------------------------------------------------------
    // canAssignRole() — plafond d'attribution des rôles
    // -------------------------------------------------------------------------

    /** Gérant du magasin 5 avec un rôle (20) qui n'accorde que shifts.view. */
    private function managerWithViewOnly(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(2)->willReturn([
            ['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 5],
        ]);
        kintai_test_roles(findById: fn(int $id) => $this->role($id), getPermissions: fn(int $id) => match ($id) {
            20, 31 => ['shifts.view'],
            30     => ['shifts.view', 'shifts.update'],
            32     => ['shifts.view'],
            default => [],
        }, getGlobalPermissionKeys: fn(int $id) => $id === 32 ? ['shifts.view'] : []);
    }

    public function testCanAssignRoleAllowsSubsetOfOwnPermissions(): void
    {
        $this->managerWithViewOnly();
        $this->assertTrue($this->service()->canAssignRole(['id' => 2], 31, 5));
    }

    public function testCanAssignRoleRefusesRoleWithPermissionTheCallerLacks(): void
    {
        $this->managerWithViewOnly();
        $this->assertFalse($this->service()->canAssignRole(['id' => 2], 30, 5));
    }

    /** Un rôle portant une permission « Toutes les boutiques » donnerait des droits hors du magasin du gérant. */
    public function testCanAssignRoleRefusesGlobalPermissionWithoutUnrestrictedGrant(): void
    {
        $this->managerWithViewOnly();
        $this->assertFalse($this->service()->canAssignRole(['id' => 2], 32, 5));
    }

    public function testCanAssignRoleAlwaysAllowedForOwner(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(1)->willReturn([
            ['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 10 ? $this->role(10, isSystem: true) : null, getPermissions: fn() => ['shifts.view', 'shifts.update'], getGlobalPermissionKeys: fn() => ['shifts.view']);
        $this->assertTrue($this->service()->canAssignRole(['id' => 1], 30, 5));
    }
}
