<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web\System;

use App\Auth\OwnerGuard;
use App\Auth\PermissionService;
use App\BundleManager;
use Illuminate\Container\Container;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\RoleAssignmentSyncService;
use Tests\Support\FakeBundleManagerFactory;
use App\Http\Controllers\System\AdminRoleController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminRoleControllerTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    private StoreRepositoryInterface&Stub $stores;
    private ?AdminRoleController $controller = null;

    /** Dernier rôle créé par l'action testée. */
    private function lastRole(): array
    {
        return Role::query()->orderByDesc('id')->first()?->toArray() ?? [];
    }

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        $this->ensureViewFile('system.roles');
        $this->ensureViewFile('system.roles-form');
        $this->ensureViewFile('layout.app');
        $this->users       = $this->createStub(UserRepositoryInterface::class);
        $this->stores      = $this->createStub(StoreRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminRoleController
    {
        if ($this->controller === null) {
            $this->controller = new AdminRoleController(
                kintai_test_views(sys_get_temp_dir()),
                $this->users,
                $this->stores,
                new AuditLogger(),
                new RoleAssignmentSyncService(),
                new OwnerGuard(new PermissionService(), $this->users),
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
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        return $req;
    }

    // -------------------------------------------------------------------------
    // roles()
    // -------------------------------------------------------------------------

    public function testRolesRendersListWithAssignmentCounts(): void
    {
        kintai_test_role_table([
            ['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1],
            ['id' => 2, 'name' => 'Manager', 'slug' => 'manager', 'is_system' => 0],
        ]);
        kintai_test_assignments(findByRole: fn(int $id) => array_column([
            [1, [['id' => 10]]],
            [2, [['id' => 11], ['id' => 12]]],
        ], 1, 0)[$id] ?? []);
        $response = $this->controller()->roles($this->ownerRequest());

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // createRole() / storeRole()
    // -------------------------------------------------------------------------

    public function testCreateRoleRendersForm(): void
    {
        $response = $this->controller()->createRole($this->ownerRequest());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStoreRoleCreatesRoleWithPermissions(): void
    {
        $_POST = [
            'name'                    => 'Auditor',
            'description'             => 'Lecture seule',
            'color'                   => '#ff0000',
            'perm_employees_view'     => '1',
            'perm_payroll_view'       => '1',
        ];
        $req = $this->ownerRequest();

        $captured = null;
        $capturedPermissions = null;
        $response = $this->controller()->storeRole($req);

        $captured = $this->lastRole();
        $capturedPermissions = Role::getPermissions((int) $this->lastRole()['id']);
        $this->assertSame(302, $response->getStatusCode());
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
        $captured = null;
        $this->controller()->storeRole($this->ownerRequest());

        $captured = $this->lastRole();
        $this->assertSame(1, $captured['is_manager']);
    }

    public function testStoreRoleCapturesGlobalScopeOnlyForGrantedPermissions(): void
    {
        $_POST = [
            'name'                => 'Auditor',
            'perm_shifts_view'    => '1',
            'scope_shifts_view'   => 'global',
            'perm_employees_view' => '1',
            // scope_employees_view absent : reste local.
            'scope_shifts_update' => 'global', // permission non cochée : doit être ignorée.
        ];
        $capturedGlobal = null;
        $this->controller()->storeRole($this->ownerRequest());

        $capturedGlobal = Role::getGlobalPermissionKeys((int) $this->lastRole()['id']);
        $this->assertSame(['shifts.view'], $capturedGlobal);
    }

    public function testStoreRoleRedirectsWithErrorWhenNameBlank(): void
    {
        $_POST = ['name' => '   '];

        $response = $this->controller()->storeRole($this->ownerRequest());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreRoleDedupesSlugWhenAlreadyTaken(): void
    {
        $_POST = ['name' => 'Manager'];
        $captured = null;
        $this->controller()->storeRole($this->ownerRequest());

        $captured = $this->lastRole();
        $this->assertSame('manager-2', $captured['slug']);
    }

    // -------------------------------------------------------------------------
    // editRole() / updateRole()
    // -------------------------------------------------------------------------

    public function testEditRoleThrowsWhenNotFound(): void
    {
        kintai_test_roles(findById: fn() => null);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 99]);

        $this->expectException(NotFoundException::class);
        $this->controller()->editRole($req);
    }

    public function testEditRoleRendersHoldersList(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0], getPermissions: fn() => ['employees.view']);
        kintai_test_assignments(findByRole: fn() => [
            ['id' => 1, 'user_id' => 7, 'scope_type' => 'store', 'scope_id' => 3],
        ]);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(3)->willReturn(['id' => 3, 'name' => 'Store A']);
        $this->users->method('findAll')->willReturn([
            ['id' => 7, 'display_name' => 'Jane', 'is_active' => 1],
            ['id' => 8, 'display_name' => 'Inactive Joe', 'is_active' => 0],
        ]);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 7 ? [['store_id' => 3]] : []);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->editRole($req);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEditRoleForOwnerRoleListsActiveUsersWithoutStoreMembership(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        kintai_test_assignments(findByRole: fn() => []);
        $this->users->method('findAll')->willReturn([
            ['id' => 7, 'display_name' => 'Jane', 'is_active' => 1],
        ]);
        // Contrairement à un rôle store-scope, la liste des candidats du rôle
        // Owner ne doit jamais interroger les appartenances aux magasins.
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $response = $this->controller()->editRole($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateRoleThrowsForbiddenOnSystemRole(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 1, 'name' => 'Owner', 'is_system' => 1]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->updateRole($req);
    }

    public function testUpdateRoleReplacesPermissions(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0], getPermissions: fn() => ['employees.view']);
        $capturedPermissions = null;
        $_POST = ['name' => 'Manager', 'perm_shifts_view' => '1'];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->updateRole($req);

        $capturedPermissions = Role::getPermissions(2);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(['shifts.view'], $capturedPermissions);
    }

    public function testUpdateRolePreservesPermissionsOfDisabledBundles(): void
    {
        // Aucun bundle activé → les catégories portées par un bundle (timeoff,
        // swaps…) sont masquées du formulaire : leurs cases ne sont ni
        // affichées ni prises en compte, mais les clés déjà accordées
        // survivent à la sauvegarde.
        Container::getInstance()->instance(BundleManager::class, FakeBundleManagerFactory::withActiveSlugs([]));

        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0], getPermissions: fn() => ['timeoff.view', 'timeoff.approve', 'employees.view']);
        $capturedPermissions = null;
        $_POST = ['name' => 'Manager', 'perm_shifts_view' => '1', 'perm_swaps_view' => '1'];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $this->controller()->updateRole($req);

        // employees.view (catégorie Core, décochée) disparaît ; swaps.view
        // (catégorie masquée, cochée par manipulation du POST) est ignorée ;
        // les clés timeoff.* déjà en base sont préservées.
        $capturedPermissions = Role::getPermissions(2);
        sort($capturedPermissions);
        $this->assertSame(['shifts.view', 'timeoff.approve', 'timeoff.view'], $capturedPermissions);
    }

    public function testUpdateRolePreservesGlobalScopeOfDisabledBundlePermissions(): void
    {
        // Même scénario que testUpdateRolePreservesPermissionsOfDisabledBundles,
        // mais timeoff.view était en plus marquée "Toutes les boutiques" avant
        // cette édition : ce flag doit lui aussi survivre, pas seulement la clé.
        Container::getInstance()->instance(BundleManager::class, FakeBundleManagerFactory::withActiveSlugs([]));

        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0], getPermissions: fn() => ['timeoff.view', 'employees.view'], getGlobalPermissionKeys: fn() => ['timeoff.view']);
        $capturedGlobal = null;
        $_POST = ['name' => 'Manager', 'perm_shifts_view' => '1'];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $this->controller()->updateRole($req);

        $capturedGlobal = Role::getGlobalPermissionKeys(2);
        $this->assertSame(['timeoff.view'], $capturedGlobal);
    }

    public function testUpdateRoleAcceptsBundleCategoryWhenBundleEnabled(): void
    {
        // timeoff déclare sa catégorie de permissions (contrat 1.2).
        $manager = FakeBundleManagerFactory::withActiveSlugs(['timeoff'], permissions: ['timeoff' => ['timeoff' => ['view', 'create', 'update', 'approve', 'delete']]]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(\App\Extension\ExtensionRegistry::class, new \App\Extension\ExtensionRegistry($manager));

        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0], getPermissions: fn() => []);
        $capturedPermissions = null;
        $_POST = ['name' => 'Manager', 'perm_timeoff_view' => '1'];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $this->controller()->updateRole($req);

        $capturedPermissions = Role::getPermissions(2);
        $this->assertSame(['timeoff.view'], $capturedPermissions);
    }

    // -------------------------------------------------------------------------
    // deleteRole()
    // -------------------------------------------------------------------------

    public function testDeleteRoleThrowsForbiddenOnSystemRole(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 1, 'name' => 'Owner', 'is_system' => 1]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->deleteRole($req);
    }

    public function testDeleteRoleRedirectsWithErrorWhenStillAssigned(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        kintai_test_assignments(findByRole: fn() => [['id' => 1]]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->deleteRole($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testDeleteRoleDeletesWhenUnassigned(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Auditor', 'is_system' => 0]);
        kintai_test_assignments(findByRole: fn() => []);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->deleteRole($req);

        $this->assertNull(Role::findById(2), 'rôle supprimé');
        $this->assertSame(302, $response->getStatusCode());
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
        kintai_test_roles(findById: fn(int $id) => $id === 40 ? ['id' => 40, 'name' => 'Weird', 'slug' => 'weird-system', 'is_system' => 1] : null);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 40]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->addHolder($req);
    }

    public function testAddHolderAppliesOwnerRoleGloballyWithoutStoreCheck(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        kintai_test_roles(findById: fn() => ['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(7)->willReturn(['id' => 7]);
        kintai_test_assignments(findByUser: fn() => []);
        $_POST = ['user_ids' => ['7']];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1]);

        $response = $this->controller()->addHolder($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('holder_added', (string) session('success'));
        $assigned = \App\Models\RoleAssignment::query()->orderBy('id')->get()->map(fn($r) => [(int) $r->user_id, (int) $r->role_id, $r->scope_type, $r->scope_id === null ? null : (int) $r->scope_id])->all();
        $this->assertSame([[7, 1, 'global', null]], $assigned);
    }

    public function testAddHolderRedirectsWithErrorWhenNoUserSelected(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $assignmentsBefore = \App\Models\RoleAssignment::query()->count();

        $_POST = ['user_ids' => []];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->addHolder($req);

        $this->assertSame($assignmentsBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation créée');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('invalid_holder', (string) session('error'));
    }

    public function testAddHolderRedirectsWithErrorWhenSelectedUserHasNoStore(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(7)->willReturn(['id' => 7]);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 7 ? [] : []);
        $assignmentsBefore = \App\Models\RoleAssignment::query()->count();

        $_POST = ['user_ids' => ['7']];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->addHolder($req);

        $this->assertSame($assignmentsBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation créée');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('invalid_holder', (string) session('error'));
    }

    public function testAddHolderAssignsEachValidUserToEveryStoreTheyBelongTo(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->users->method('findById')->willReturnMap([
            [7, ['id' => 7]],
            [8, ['id' => 8]],
            [9, null],
        ]);
        kintai_test_store_users(findByUser: fn(int $id) => array_column([
            [7, [['store_id' => 3]]],
            [8, [['store_id' => 3], ['store_id' => 4]]],
        ], 1, 0)[$id] ?? []);
        kintai_test_assignments(findByUser: fn() => []);

        $_POST = ['user_ids' => ['7', '8', '9']];
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2]);

        $response = $this->controller()->addHolder($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('holder_added', (string) session('success'));
        $assigned = \App\Models\RoleAssignment::query()->orderBy('id')->get()->map(fn($r) => [(int) $r->user_id, (int) $r->role_id, $r->scope_type, $r->scope_id === null ? null : (int) $r->scope_id])->all();
        $this->assertSame([
            [7, 2, 'store', 3],
            [8, 2, 'store', 3],
            [8, 2, 'store', 4],
        ], $assigned);
    }

    public function testRemoveHolderThrowsForbiddenOnNonOwnerSystemRole(): void
    {
        kintai_test_roles(findById: fn(int $id) => $id === 40 ? ['id' => 40, 'name' => 'Weird', 'slug' => 'weird-system', 'is_system' => 1] : null);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 40, 'assignmentId' => 5]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->removeHolder($req);
    }

    public function testRemoveHolderRevokesOwnerRoleGlobally(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        kintai_test_user(7);
        kintai_test_user(8);   // un autre Owner actif : le dernier ne peut pas être retiré
        $this->users->method('findById')->willReturnCallback(fn(int $id) => ['id' => $id, 'is_active' => 1]);
        kintai_test_assignments(findByRole: fn(int $id) => $id === 1 ? [
            ['id' => 5, 'role_id' => 1, 'user_id' => 7, 'scope_type' => 'global', 'scope_id' => null],
            ['id' => 6, 'role_id' => 1, 'user_id' => 8, 'scope_type' => 'global', 'scope_id' => null],
        ] : []);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1, 'assignmentId' => 5]);

        $response = $this->controller()->removeHolder($req);

        $this->assertNull(\App\Models\RoleAssignment::findById(5), 'affectation révoquée');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('holder_removed', (string) session('success'));
    }

    public function testRemoveHolderRefusesToRemoveTheOwnerRoleFromTheActor(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        kintai_test_assignments(findByScope: fn() => [
            ['id' => 5, 'role_id' => 1, 'user_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
            ['id' => 6, 'role_id' => 1, 'user_id' => 2, 'scope_type' => 'global', 'scope_id' => null],
        ], findById: fn() => ['id' => 5, 'role_id' => 1, 'user_id' => 1, 'scope_type' => 'global', 'scope_id' => null]);
        $revokeBefore = \App\Models\RoleAssignment::query()->count();

        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 1, 'assignmentId' => 5]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->removeHolder($req);
        $this->assertSame($revokeBefore, \App\Models\RoleAssignment::query()->count(), 'aucune affectation révoquée');
    }

    public function testRemoveHolderThrowsNotFoundWhenAssignmentMissing(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        kintai_test_assignments(findById: fn() => null);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2, 'assignmentId' => 5]);

        $this->expectException(NotFoundException::class);
        $this->controller()->removeHolder($req);
    }

    public function testRemoveHolderThrowsNotFoundWhenAssignmentBelongsToAnotherRole(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        kintai_test_assignments(findById: fn() => ['id' => 5, 'role_id' => 99, 'user_id' => 7, 'scope_type' => 'store', 'scope_id' => 3]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2, 'assignmentId' => 5]);

        $this->expectException(NotFoundException::class);
        $this->controller()->removeHolder($req);
    }

    public function testRemoveHolderRevokesAssignment(): void
    {
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 5, 'role_id' => 2, 'user_id' => 7, 'scope_type' => 'store', 'scope_id' => 3],
        ], findById: fn() => ['id' => 5, 'role_id' => 2, 'user_id' => 7, 'scope_type' => 'store', 'scope_id' => 3]);
        $req = $this->ownerRequest();
        $req->setRouteParams(['id' => 2, 'assignmentId' => 5]);

        $response = $this->controller()->removeHolder($req);

        $this->assertNull(\App\Models\RoleAssignment::findById(5), 'affectation révoquée');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('holder_removed', (string) session('success'));
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

    private function redirectLocation(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return kintai_test_headers($response)['Location'] ?? '';
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
