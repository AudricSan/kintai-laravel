<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\System;

use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\Tests\Support\FakeBundleManagerFactory;
use kintai\UI\Controller\Web\System\AdminRoleController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminRoleControllerTest extends TestCase
{
    private RoleRepositoryInterface&Stub $roles;
    private RoleAssignmentRepositoryInterface&Stub $assignments;
    private UserRepositoryInterface&Stub $users;
    private StoreRepositoryInterface&Stub $stores;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private ?AdminRoleController $controller = null;

    protected function setUp(): void
    {
        $this->ensureViewFile('system.roles');
        $this->ensureViewFile('system.roles-form');
        $this->ensureViewFile('layout.app');

        $this->roles       = $this->createStub(RoleRepositoryInterface::class);
        $this->assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->users       = $this->createStub(UserRepositoryInterface::class);
        $this->stores      = $this->createStub(StoreRepositoryInterface::class);
        $this->storeUsers  = $this->createStub(StoreUserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminRoleController
    {
        if ($this->controller === null) {
            $this->controller = new AdminRoleController(
                new ViewRenderer(sys_get_temp_dir()),
                $this->roles,
                $this->assignments,
                $this->users,
                $this->stores,
                new AuditLogger(),
                $this->storeUsers,
                new RoleAssignmentSyncService($this->roles, $this->assignments),
                new OwnerGuard(new PermissionService($this->assignments, $this->roles), $this->users),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        // Réinitialise le singleton Container pour ne pas propager le
        // BundleManager injecté par les tests de bundles désactivés.
        $instance = new \ReflectionProperty(Container::class, 'instance');
        $instance->setValue(null, null);
    }

    private function ownerRequest(): Request
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);
        return $req;
    }

    // -------------------------------------------------------------------------
    // roles()
    // -------------------------------------------------------------------------

    public function testRolesRendersListWithAssignmentCounts(): void
    {
        $this->roles->method('findAll')->willReturn([
            ['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1],
            ['id' => 2, 'name' => 'Manager', 'slug' => 'manager', 'is_system' => 0],
        ]);
        $this->assignments->method('findByRole')->willReturnMap([
            [1, [['id' => 10]]],
            [2, [['id' => 11], ['id' => 12]]],
        ]);

        $response = $this->controller()->roles($this->ownerRequest());

        $this->assertSame(200, $response->status());
    }

    // -------------------------------------------------------------------------
    // createRole() / storeRole()
    // -------------------------------------------------------------------------

    public function testCreateRoleRendersForm(): void
    {
        $response = $this->controller()->createRole($this->ownerRequest());
        $this->assertSame(200, $response->status());
    }

    public function testStoreRoleCreatesRoleWithPermissions(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $_POST = [
            'name'                    => 'Auditor',
            'description'             => 'Lecture seule',
            'color'                   => '#ff0000',
            'perm_employees_view'     => '1',
            'perm_payroll_view'       => '1',
        ];
        $req = $this->ownerRequest();

        $this->roles->method('findBySlug')->willReturn(null);

        $captured = null;
        $this->roles->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 5];
        });

        $capturedPermissions = null;
        $this->roles->expects($this->once())->method('savePermissions')
            ->willReturnCallback(function (int $roleId, array $perms) use (&$capturedPermissions) {
                $capturedPermissions = $perms;
            });

        $response = $this->controller()->storeRole($req);

        $this->assertSame(302, $response->status());
        $this->assertSame('Auditor', $captured['name']);
        $this->assertSame('auditor', $captured['slug']);
        $this->assertSame(0, $captured['is_system']);
        sort($capturedPermissions);
        $this->assertSame(['employees.view', 'payroll.view'], $capturedPermissions);
        $this->assertSame(0, $captured['is_manager']);
    }

    /**
     * is_manager doit rester indépendant des permissions cochées (voir AuthService::
     * roleIsManagerType()) : un rôle peut accorder des permissions en libre-service
     * (ex. photos.create) sans jamais devoir afficher la navigation manager, tant que
     * cette case n'est pas explicitement cochée.
     */
    public function testStoreRoleCapturesIsManagerFlagWhenChecked(): void
    {
        $_POST = ['name' => 'Manager Boutique', 'is_manager' => '1'];
        $this->roles->method('findBySlug')->willReturn(null);

        $captured = null;
        $this->roles->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 6];
        });

        $this->controller()->storeRole($this->ownerRequest());

        $this->assertSame(1, $captured['is_manager']);
    }

    public function testStoreRoleCapturesGlobalScopeOnlyForGrantedPermissions(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $_POST = [
            'name'                => 'Auditor',
            'perm_shifts_view'    => '1',
            'scope_shifts_view'   => 'global',
            'perm_employees_view' => '1',
            // scope_employees_view absent : reste local.
            'scope_shifts_update' => 'global', // permission non cochée : doit être ignorée.
        ];
        $this->roles->method('findBySlug')->willReturn(null);
        $this->roles->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 5]);

        $capturedGlobal = null;
        $this->roles->expects($this->once())->method('savePermissions')
            ->willReturnCallback(function (int $roleId, array $perms, array $global = []) use (&$capturedGlobal) {
                $capturedGlobal = $global;
            });

        $this->controller()->storeRole($this->ownerRequest());

        $this->assertSame(['shifts.view'], $capturedGlobal);
    }

    public function testStoreRoleRedirectsWithErrorWhenNameBlank(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $_POST = ['name' => '   '];
        $this->roles->expects($this->never())->method('save');

        $response = $this->controller()->storeRole($this->ownerRequest());

        $this->assertSame(302, $response->status());
    }

    public function testStoreRoleDedupesSlugWhenAlreadyTaken(): void
    {
        $_POST = ['name' => 'Manager'];
        $this->roles->method('findBySlug')->willReturnMap([
            ['manager', ['id' => 2, 'slug' => 'manager']],
            ['manager-2', null],
        ]);

        $captured = null;
        $this->roles->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 6];
        });

        $this->controller()->storeRole($this->ownerRequest());

        $this->assertSame('manager-2', $captured['slug']);
    }

    // -------------------------------------------------------------------------
    // editRole() / updateRole()
    // -------------------------------------------------------------------------

    public function testEditRoleThrowsWhenNotFound(): void
    {
        $this->roles->method('findById')->willReturn(null);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 99]);

        $this->expectException(NotFoundException::class);
        $this->controller()->editRole($req);
    }

    public function testEditRoleRendersHoldersList(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->method('getPermissions')->willReturn(['employees.view']);
        $this->assignments->method('findByRole')->willReturn([
            ['id' => 1, 'user_id' => 7, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(7)->willReturn(['id' => 7, 'display_name' => 'Jane']);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(3)->willReturn(['id' => 3, 'name' => 'Store A']);
        $this->users->method('findAll')->willReturn([
            ['id' => 7, 'display_name' => 'Jane', 'is_active' => 1],
            ['id' => 8, 'display_name' => 'Inactive Joe', 'is_active' => 0],
        ]);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(7)->willReturn([['store_id' => 3]]);

        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->editRole($req);
        $this->assertSame(200, $response->status());
    }

    public function testEditRoleForOwnerRoleListsActiveUsersWithoutStoreMembership(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $this->assignments->method('findByRole')->willReturn([]);
        $this->users->method('findAll')->willReturn([
            ['id' => 7, 'display_name' => 'Jane', 'is_active' => 1],
        ]);
        // Contrairement à un rôle store-scope, la liste des candidats du rôle
        // Owner ne doit jamais interroger les appartenances aux magasins.
        $this->storeUsers->expects($this->never())->method('findByUser');

        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $response = $this->controller()->editRole($req);

        $this->assertSame(200, $response->status());
    }

    public function testUpdateRoleThrowsForbiddenOnSystemRole(): void
    {
        $this->roles->method('findById')->willReturn(['id' => 1, 'name' => 'Owner', 'is_system' => 1]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->updateRole($req);
    }

    public function testUpdateRoleReplacesPermissions(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->method('getPermissions')->willReturn(['employees.view']);
        $this->roles->method('save')->willReturnCallback(fn(array $d) => $d);

        $capturedPermissions = null;
        $this->roles->expects($this->once())->method('savePermissions')
            ->willReturnCallback(function (int $roleId, array $perms) use (&$capturedPermissions) {
                $capturedPermissions = $perms;
            });

        $_POST = ['name' => 'Manager', 'perm_shifts_view' => '1'];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->updateRole($req);

        $this->assertSame(302, $response->status());
        $this->assertSame(['shifts.view'], $capturedPermissions);
    }

    public function testUpdateRolePreservesPermissionsOfDisabledBundles(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        // Aucun bundle activé → les catégories portées par un bundle (timeoff,
        // swaps…) sont masquées du formulaire : leurs cases ne sont ni
        // affichées ni prises en compte, mais les clés déjà accordées
        // survivent à la sauvegarde.
        Container::getInstance()->instance(BundleManager::class, FakeBundleManagerFactory::withActiveSlugs([]));

        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->method('getPermissions')->willReturn(['timeoff.view', 'timeoff.approve', 'employees.view']);
        $this->roles->method('save')->willReturnCallback(fn(array $d) => $d);

        $capturedPermissions = null;
        $this->roles->expects($this->once())->method('savePermissions')
            ->willReturnCallback(function (int $roleId, array $perms) use (&$capturedPermissions) {
                $capturedPermissions = $perms;
            });

        $_POST = ['name' => 'Manager', 'perm_shifts_view' => '1', 'perm_swaps_view' => '1'];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $this->controller()->updateRole($req);

        sort($capturedPermissions);
        // employees.view (catégorie Core, décochée) disparaît ; swaps.view
        // (catégorie masquée, cochée par manipulation du POST) est ignorée ;
        // les clés timeoff.* déjà en base sont préservées.
        $this->assertSame(['shifts.view', 'timeoff.approve', 'timeoff.view'], $capturedPermissions);
    }

    public function testUpdateRolePreservesGlobalScopeOfDisabledBundlePermissions(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        // Même scénario que testUpdateRolePreservesPermissionsOfDisabledBundles,
        // mais timeoff.view était en plus marquée "Toutes les boutiques" avant
        // cette édition : ce flag doit lui aussi survivre, pas seulement la clé.
        Container::getInstance()->instance(BundleManager::class, FakeBundleManagerFactory::withActiveSlugs([]));

        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->method('getPermissions')->willReturn(['timeoff.view', 'employees.view']);
        $this->roles->method('getGlobalPermissionKeys')->willReturn(['timeoff.view']);
        $this->roles->method('save')->willReturnCallback(fn(array $d) => $d);

        $capturedGlobal = null;
        $this->roles->expects($this->once())->method('savePermissions')
            ->willReturnCallback(function (int $roleId, array $perms, array $global = []) use (&$capturedGlobal) {
                $capturedGlobal = $global;
            });

        $_POST = ['name' => 'Manager', 'perm_shifts_view' => '1'];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $this->controller()->updateRole($req);

        $this->assertSame(['timeoff.view'], $capturedGlobal);
    }

    public function testUpdateRoleAcceptsBundleCategoryWhenBundleEnabled(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        // timeoff déclare sa catégorie de permissions (contrat 1.2).
        $manager = FakeBundleManagerFactory::withActiveSlugs(['timeoff'], permissions: ['timeoff' => ['timeoff' => ['view', 'create', 'update', 'approve', 'delete']]]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(\kintai\Core\Extension\ExtensionRegistry::class, new \kintai\Core\Extension\ExtensionRegistry($manager));

        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->method('getPermissions')->willReturn([]);
        $this->roles->method('save')->willReturnCallback(fn(array $d) => $d);

        $capturedPermissions = null;
        $this->roles->expects($this->once())->method('savePermissions')
            ->willReturnCallback(function (int $roleId, array $perms) use (&$capturedPermissions) {
                $capturedPermissions = $perms;
            });

        $_POST = ['name' => 'Manager', 'perm_timeoff_view' => '1'];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $this->controller()->updateRole($req);

        $this->assertSame(['timeoff.view'], $capturedPermissions);
    }

    // -------------------------------------------------------------------------
    // deleteRole()
    // -------------------------------------------------------------------------

    public function testDeleteRoleThrowsForbiddenOnSystemRole(): void
    {
        $this->roles->method('findById')->willReturn(['id' => 1, 'name' => 'Owner', 'is_system' => 1]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->deleteRole($req);
    }

    public function testDeleteRoleRedirectsWithErrorWhenStillAssigned(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->assignments->method('findByRole')->willReturn([['id' => 1]]);
        $this->roles->expects($this->never())->method('delete');

        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->deleteRole($req);

        $this->assertSame(302, $response->status());
    }

    public function testDeleteRoleDeletesWhenUnassigned(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Auditor', 'is_system' => 0]);
        $this->assignments->method('findByRole')->willReturn([]);
        $this->roles->expects($this->once())->method('delete')->with(2);

        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->deleteRole($req);

        $this->assertSame(302, $response->status());
    }

    // -------------------------------------------------------------------------
    // addHolder() / removeHolder()
    // -------------------------------------------------------------------------

    public function testAddHolderThrowsForbiddenOnNonOwnerSystemRole(): void
    {
        // Owner est aujourd'hui le seul rôle système, mais la garde ne doit
        // lever l'exception que pour un rôle système qui n'est PAS Owner
        // (hypothétique) — Owner lui-même est géré en portée globale, voir
        // testAddHolderAppliesOwnerRoleGloballyWithoutStoreCheck ci-dessous.
        $this->roles->method('findById')->willReturn(['id' => 1, 'name' => 'Weird', 'slug' => 'weird-system', 'is_system' => 1]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->addHolder($req);
    }

    public function testAddHolderAppliesOwnerRoleGloballyWithoutStoreCheck(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(7)->willReturn(['id' => 7]);
        $this->assignments->method('findByUser')->willReturn([]);
        $this->storeUsers->expects($this->never())->method('findByUser');

        $assigned = [];
        $this->assignments->expects($this->once())->method('assign')
            ->willReturnCallback(function (int $userId, int $roleId, string $scopeType, ?int $scopeId) use (&$assigned) {
                $assigned[] = [$userId, $roleId, $scopeType, $scopeId];
                return ['id' => 100];
            });

        $_POST = ['user_ids' => ['7']];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $response = $this->controller()->addHolder($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=holder_added', $this->redirectLocation($response));
        $this->assertSame([[7, 1, 'global', null]], $assigned);
    }

    public function testAddHolderRedirectsWithErrorWhenNoUserSelected(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->assignments->expects($this->never())->method('assign');

        $_POST = ['user_ids' => []];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->addHolder($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('error=invalid_holder', $this->redirectLocation($response));
    }

    public function testAddHolderRedirectsWithErrorWhenSelectedUserHasNoStore(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(7)->willReturn(['id' => 7]);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(7)->willReturn([]);
        $this->assignments->expects($this->never())->method('assign');

        $_POST = ['user_ids' => ['7']];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->addHolder($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('error=invalid_holder', $this->redirectLocation($response));
    }

    public function testAddHolderAssignsEachValidUserToEveryStoreTheyBelongTo(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->users->method('findById')->willReturnMap([
            [7, ['id' => 7]],
            [8, ['id' => 8]],
            [9, null],
        ]);
        $this->storeUsers->method('findByUser')->willReturnMap([
            [7, [['store_id' => 3]]],
            [8, [['store_id' => 3], ['store_id' => 4]]],
        ]);
        $this->assignments->method('findByUser')->willReturn([]);

        $assigned = [];
        $this->assignments->expects($this->exactly(3))->method('assign')
            ->willReturnCallback(function (int $userId, int $roleId, string $scopeType, ?int $scopeId) use (&$assigned) {
                $assigned[] = [$userId, $roleId, $scopeType, $scopeId];
                return ['id' => 100];
            });

        $_POST = ['user_ids' => ['7', '8', '9']];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->addHolder($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=holder_added', $this->redirectLocation($response));
        $this->assertSame([
            [7, 2, 'store', 3],
            [8, 2, 'store', 3],
            [8, 2, 'store', 4],
        ], $assigned);
    }

    public function testRemoveHolderThrowsForbiddenOnNonOwnerSystemRole(): void
    {
        $this->roles->method('findById')->willReturn(['id' => 1, 'name' => 'Weird', 'slug' => 'weird-system', 'is_system' => 1]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1, 'assignmentId' => 5]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->removeHolder($req);
    }

    public function testRemoveHolderRevokesOwnerRoleGlobally(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $this->assignments->method('findById')->willReturn(['id' => 5, 'role_id' => 1, 'user_id' => 7, 'scope_type' => 'global', 'scope_id' => null]);
        $this->assignments->method('findByUser')->willReturn([
            ['id' => 5, 'role_id' => 1, 'user_id' => 7, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $this->assignments->expects($this->once())->method('revoke')->with(5);

        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1, 'assignmentId' => 5]);

        $response = $this->controller()->removeHolder($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=holder_removed', $this->redirectLocation($response));
    }

    public function testRemoveHolderRefusesToRemoveTheOwnerRoleFromTheActor(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $this->assignments->method('findById')->willReturn(['id' => 5, 'role_id' => 1, 'user_id' => 1, 'scope_type' => 'global', 'scope_id' => null]);
        $this->assignments->method('findByScope')->willReturn([
            ['id' => 5, 'role_id' => 1, 'user_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
            ['id' => 6, 'role_id' => 1, 'user_id' => 2, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $this->assignments->expects($this->never())->method('revoke');

        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1, 'assignmentId' => 5]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->removeHolder($req);
    }

    public function testRemoveHolderThrowsNotFoundWhenAssignmentMissing(): void
    {
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->assignments->method('findById')->willReturn(null);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2, 'assignmentId' => 5]);

        $this->expectException(NotFoundException::class);
        $this->controller()->removeHolder($req);
    }

    public function testRemoveHolderThrowsNotFoundWhenAssignmentBelongsToAnotherRole(): void
    {
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->assignments->method('findById')->willReturn(['id' => 5, 'role_id' => 99, 'user_id' => 7, 'scope_type' => 'store', 'scope_id' => 3]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2, 'assignmentId' => 5]);

        $this->expectException(NotFoundException::class);
        $this->controller()->removeHolder($req);
    }

    public function testRemoveHolderRevokesAssignment(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles->method('findById')->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->assignments->method('findById')->willReturn(['id' => 5, 'role_id' => 2, 'user_id' => 7, 'scope_type' => 'store', 'scope_id' => 3]);
        $this->assignments->method('findByUser')->willReturn([
            ['id' => 5, 'role_id' => 2, 'user_id' => 7, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        $this->assignments->expects($this->once())->method('revoke')->with(5);

        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2, 'assignmentId' => 5]);

        $response = $this->controller()->removeHolder($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=holder_removed', $this->redirectLocation($response));
    }

    // -------------------------------------------------------------------------
    // resolveDescription()
    // -------------------------------------------------------------------------

    public function testResolveDescriptionTranslatesOwnerRoleDescriptionOnly(): void
    {
        $method = new \ReflectionMethod(AdminRoleController::class, 'resolveDescription');
        $method->setAccessible(true);

        $this->assertSame(__('role_owner_description'), $method->invoke($this->controller(), ['slug' => 'owner', 'description' => 'Valeur en base']));
        $this->assertSame('Description personnalisée', $method->invoke($this->controller(), ['slug' => 'manager', 'description' => 'Description personnalisée']));
    }

    private function redirectLocation(\kintai\Core\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'headers');
        return $ref->getValue($response)['Location'] ?? '';
    }

    private function ensureViewFile(string $view): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        touch($file);
    }
}
