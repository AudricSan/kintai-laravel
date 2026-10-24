<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Middleware\ApiPermissionMiddleware;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ApiPermissionMiddlewareTest extends TestCase
{
    private RoleRepositoryInterface&Stub $roles;
    private RoleAssignmentRepositoryInterface&Stub $assignments;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private ?ApiPermissionMiddleware $middleware = null;

    protected function setUp(): void
    {
        $this->roles       = $this->createStub(RoleRepositoryInterface::class);
        $this->assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->storeUsers  = $this->createStub(StoreUserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function middleware(): ApiPermissionMiddleware
    {
        if ($this->middleware === null) {
            $this->middleware  = new ApiPermissionMiddleware(
                new PermissionService($this->assignments, $this->roles),
                $this->storeUsers,
            );
        }

        return $this->middleware;
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];
    }

    /** @param string|array|null $permission Règle telle que déclarée par Route::$permission. */
    private function makeRequest(string|array|null $permission, int $authUserId, array $routeParams = [], array $query = [], array $post = []): Request
    {
        $_GET  = $query;
        $_POST = $post;
        $request = new Request();
        $request->setAttribute('route_permission', $permission);
        $request->setAttribute('auth_user', ['id' => $authUserId]);
        $request->setRouteParams($routeParams);
        return $request;
    }

    private function next(): \Closure
    {
        return fn(Request $r) => Response::json(['ok' => true]);
    }

    private function grantStoreRole(int $userId, int $roleId, int $storeId, array $permissions): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with($userId)->willReturn([
            ['id' => 1, 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'store', 'scope_id' => $storeId],
        ]);
        // Le rôle n'est pas lu quand la portée exclut déjà la demande : pas d'attente, réponse par identifiant.
        $this->roles->method('findById')->willReturnCallback(fn(int $id): ?array => $id === $roleId ? ['id' => $roleId, 'is_system' => 0] : null);
        $this->roles->method('getPermissions')->willReturnCallback(fn(int $id): array => $id === $roleId ? $permissions : []);
    }

    public function testRouteWithoutPermissionDeclaredPassesThrough(): void
    {
        // Ex. auth.me = opération du porteur du token, volontairement sans permission.
        $request = $this->makeRequest(null, 10);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }

    public function testPublicRoutePassesThroughWithoutConsultingRoles(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->never())->method('findByUser');

        $request = $this->makeRequest('public', 10);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }

    public function testTokenWithoutPermissionGets403(): void
    {
        $this->assignments->method('findByUser')->willReturn([]);

        $request  = $this->makeRequest('employees.view', 10);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(403, $response->status());
        $this->assertSame('FORBIDDEN', json_decode($response->body(), true)['code']);
    }

    public function testPermissionGrantedAnywherePassesWhenNoStoreTargeted(): void
    {
        $this->grantStoreRole(10, 2, 1, ['employees.view']);

        $request = $this->makeRequest('employees.view', 10);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }

    public function testStoreScopedPermissionRejectsOtherStore(): void
    {
        // employees.view accordé sur le store 1 seulement → membres du store 2 refusés
        $this->grantStoreRole(10, 2, 1, ['employees.view']);

        $request  = $this->makeRequest('employees.view', 10, ['store_id' => '2']);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(403, $response->status());
    }

    public function testStoreScopedPermissionAcceptsGrantedStore(): void
    {
        $this->grantStoreRole(10, 2, 1, ['employees.view']);

        $request = $this->makeRequest('employees.view', 10, ['store_id' => '1']);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }

    public function testStoreIdQueryParamIsAlsoScopeChecked(): void
    {
        $this->grantStoreRole(10, 2, 1, ['shifts.view']);

        $ok  = $this->makeRequest('shifts.view', 10, [], ['store_id' => '1']);
        $this->assertSame(200, $this->middleware()->handle($ok, $this->next())->status());

        $ko  = $this->makeRequest('shifts.view', 10, [], ['store_id' => '2']);
        $this->assertSame(403, $this->middleware()->handle($ko, $this->next())->status());
    }

    public function testSelfRuleAllowsOwnResourceWithoutPermission(): void
    {
        $this->assignments->method('findByUser')->willReturn([]);

        // GET /users/10 par l'utilisateur 10 lui-même
        $request = $this->makeRequest(['perm' => 'employees.view', 'self' => 'id'], 10, ['id' => '10']);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }

    public function testSelfRuleRejectsOtherUsersResourceWithoutPermission(): void
    {
        $this->assignments->method('findByUser')->willReturn([]);

        $request  = $this->makeRequest(['perm' => 'employees.view', 'self' => 'id'], 10, ['id' => '11']);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(403, $response->status());
    }

    public function testSelfRuleReadsUserIdFromRequestBody(): void
    {
        $this->assignments->method('findByUser')->willReturn([]);

        // clock-in pour soi-même (user_id du corps) → autorisé sans permission
        $request = $this->makeRequest(['perm' => 'timeclock.update', 'self' => 'user_id'], 10, post: ['user_id' => '10']);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }

    public function testSelfRuleRejectsBodyTargetingAnotherUser(): void
    {
        $this->assignments->method('findByUser')->willReturn([]);

        // clock-in pour un autre employé sans timeclock.update → 403
        $request  = $this->makeRequest(['perm' => 'timeclock.update', 'self' => 'user_id'], 10, post: ['user_id' => '11']);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(403, $response->status());
    }

    public function testBundleRouteRequiresItsPermission(): void
    {
        $this->grantStoreRole(10, 2, 1, ['timeoff.view', 'timeoff.approve']);

        $ok = $this->makeRequest(['perm' => 'timeoff.view', 'self' => 'user_id'], 10);
        $this->assertSame(200, $this->middleware()->handle($ok, $this->next())->status());

        $ko       = $this->makeRequest('timeoff.delete', 10, ['id' => '5']);
        $response = $this->middleware()->handle($ko, $this->next());
        $this->assertSame(403, $response->status());
    }

    public function testOwnerSystemRoleGrantsEverything(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(1)->willReturn([
            ['id' => 1, 'user_id' => 1, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'is_system' => 1]);

        $request = $this->makeRequest('stores.delete', 1, ['id' => '3']);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }

    // -------------------------------------------------------------------------
    // Règle 'membership' (bundle DailyReport) : accès en libre-service pour
    // tout membre du store ciblé, sans permission RBAC dédiée.
    // -------------------------------------------------------------------------

    public function testMembershipRuleAllowsAnyStoreMemberWithoutPermission(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->assignments->method('findByUser')->willReturn([]);
        $this->storeUsers->expects($this->atLeastOnce())->method('findMembership')->with(1, 10)->willReturn(['store_id' => 1, 'user_id' => 10]);

        $request = $this->makeRequest(['perm' => 'daily_reports.create', 'membership' => true], 10, [], ['store_id' => '1']);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }

    public function testMembershipRuleRejectsNonMemberWithoutPermission(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->assignments->method('findByUser')->willReturn([]);
        $this->storeUsers->expects($this->atLeastOnce())->method('findMembership')->with(1, 10)->willReturn(null);

        $request  = $this->makeRequest(['perm' => 'daily_reports.create', 'membership' => true], 10, [], ['store_id' => '1']);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(403, $response->status());
    }

    public function testMembershipRuleRejectsWithoutStoreIdEvenForAMember(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        // Sans store_id ciblé, la portée 'membership' ne peut pas s'appliquer :
        // seule une permission RBAC globale peut passer.
        $this->assignments->method('findByUser')->willReturn([]);
        $this->storeUsers->expects($this->never())->method('findMembership');

        $request  = $this->makeRequest(['perm' => 'daily_reports.create', 'membership' => true], 10);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(403, $response->status());
    }

    public function testMembershipRuleStillGrantsViaPermission(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        // Un gestionnaire avec daily_reports.create sur le store passe par la
        // clé RBAC normale, sans même consulter l'adhésion.
        $this->grantStoreRole(10, 2, 1, ['daily_reports.create']);
        $this->storeUsers->expects($this->never())->method('findMembership');

        $request = $this->makeRequest(['perm' => 'daily_reports.create', 'membership' => true], 10, [], ['store_id' => '1']);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->status());
    }
}
