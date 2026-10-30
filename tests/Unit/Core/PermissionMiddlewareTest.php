<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Http\Middleware\PermissionMiddleware;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class PermissionMiddlewareTest extends TestCase
{
    private ?PermissionMiddleware $middleware = null;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function middleware(): PermissionMiddleware
    {
        if ($this->middleware === null) {
            $this->middleware  = new PermissionMiddleware(
                new PermissionService(),
                kintai_test_views(sys_get_temp_dir()),
            );
        }

        return $this->middleware;
    }

    /** @param string|array|null $permission Règle telle que déclarée par Route::$permission. */
    private function makeRequest(string|array|null $permission, array $authUser, ?array $managedIds, array $routeParams = []): Request
    {
        $request = Request::capture();
        $request->attributes->set('route_permission', $permission);
        $request->attributes->set('auth_user', $authUser);
        $request->attributes->set('managed_store_ids', $managedIds);
        $request->setRouteParams($routeParams);
        return $request;
    }

    private function next(): \Closure
    {
        return fn(Request $r) => response('ok');
    }

    /**
     * Route sans permission déclarée (null) ou 'public' (self-service, agrégat, ou déjà
     * protégée par requireOwner()) : porte grossière héritée de l'ancien AdminMiddleware,
     * fusionné dans ce middleware en RBAC-V2 — au moins une permission RBAC quelque part
     * suffit, aucune clé précise n'est exigée.
     */
    public function testRouteWithoutPermissionDeclaredPassesThroughWhenAnyPermissionIsGranted(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['photos.view'] : []);
        $request  = $this->makeRequest(null, ['id' => 10], [1]);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPublicRoutePassesThroughWhenAnyPermissionIsGranted(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['photos.view'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 2 ? [] : []);
        $request = $this->makeRequest('public', ['id' => 10], [1]);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        // Non-régression : sans permission globale, managed_store_ids reste borné au
        // store réel — le fix ci-dessous ne doit pas sur-octroyer un accès illimité.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1], json_decode($response->getContent(), true)['managed']);
    }

    /**
     * Repro du bug rapporté : photos.view marquée globale ("Toutes les boutiques") sur
     * un rôle dont l'affectation reste store-scope. anyGrantedStoreIds() (utilisée par
     * la porte grossière ci-dessus) ne consulte jamais getGlobalPermissionKeys() et
     * aurait borné managed_store_ids au seul store d'origine — exactement ce qui faisait
     * échouer le chargement des photos des autres magasins sur /storage/{path*} ('public')
     * alors que la liste des rapports (route à permission précise photos.view) les
     * montrait déjà correctement.
     */
    public function testPublicRoutePassesWithUnlimitedScopeWhenRoleHasGlobalPermissionOnStoreScopedAssignment(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn() => ['photos.view'], getGlobalPermissionKeys: fn(int $id) => $id === 2 ? ['photos.view'] : []);
        $request  = $this->makeRequest('public', ['id' => 10], [1]);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(json_decode($response->getContent(), true)['managed']);
    }

    /** Même scénario que ci-dessus mais pour une route sans permission déclarée du tout ($key = null). */
    public function testRouteWithoutPermissionDeclaredPassesWithUnlimitedScopeForGlobalPermissionOnRole(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn() => ['photos.view'], getGlobalPermissionKeys: fn(int $id) => $id === 2 ? ['photos.view'] : []);
        $request  = $this->makeRequest(null, ['id' => 10], [1]);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(json_decode($response->getContent(), true)['managed']);
    }

    /**
     * Non-régression critique : le court-circuit "permission globale" ne doit jamais
     * contourner le refus d'une clé de permission PRÉCISE non accordée. Le rôle rend
     * employees.view globale (donc hasAnyGlobalPermissionGrant() = true), mais la route
     * exige stores.view, jamais accordée — doit toujours lever ForbiddenException.
     */
    public function testForbiddenWithoutTheDeclaredPermissionStaysForbiddenEvenWithUnrelatedGlobalPermission(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 3],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['employees.view'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 2 ? ['employees.view'] : []);
        $this->expectException(ForbiddenException::class);
        $this->middleware()->handle($this->makeRequest('stores.view', ['id' => 10], [3]), $this->next());
    }

    /** Sans aucune permission RBAC nulle part, la porte grossière redirige vers /employee. */
    public function testPublicRouteRedirectsToEmployeeWithoutAnyPermission(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [] : []);
        $request  = $this->makeRequest('public', ['id' => 10], null);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testScopedByStoresView(): void
    {
        // Ex. admin.activity → 'stores.view' : un manager scopé sur un seul
        // store ne doit voir que le journal de ce store.
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 5, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 3],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['stores.view'] : []);
        $request = $this->makeRequest('stores.view', ['id' => 10], [1, 3]);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([3], json_decode($response->getContent(), true)['managed']);
    }

    public function testForbiddenWithoutTheDeclaredPermission(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 5, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 3],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['shifts.view'] : []);
        $this->expectException(ForbiddenException::class);
        $this->middleware()->handle($this->makeRequest('stores.view', ['id' => 10], [3]), $this->next());
    }

    public function testOwnerBypassesPermissionCheck(): void
    {
        $request = $this->makeRequest('employees.view', ['id' => 1, 'is_admin' => 1], null);
        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->getStatusCode());
    }

    public function testManagerWithScopedPermissionPassesAndNarrowsManagedStores(): void
    {
        // Rôle 2 accorde employees.view sur le store 1 uniquement ; le store 2
        // est géré via un rôle 3 qui ne l'accorde pas.
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 5, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
            ['id' => 6, 'user_id' => 10, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 2],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => array_column([
            [2, ['id' => 2, 'is_system' => 0]],
            [3, ['id' => 3, 'is_system' => 0]],
        ], 1, 0)[$id] ?? null, getPermissions: static fn(int $id) => array_column([
            [2, ['employees.view']],
            [3, ['shifts.view']],
        ], 1, 0)[$id] ?? []);
        $request = $this->makeRequest('employees.view', ['id' => 10], [1, 2]);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1], json_decode($response->getContent(), true)['managed']);
    }

    public function testManagerWithoutPermissionIsForbidden(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 6, 'user_id' => 10, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 2],
        ] : []);
        kintai_test_roles(findById: fn() => ['id' => 3, 'is_system' => 0], getPermissions: fn() => ['shifts.view']);
        $this->expectException(ForbiddenException::class);

        $this->middleware()->handle($this->makeRequest('employees.view', ['id' => 10], [2]), $this->next());
    }

    public function testGlobalScopeGrantPassesWithoutNarrowing(): void
    {
        // Affectation de portée globale (rôle non-système, ex. futur "Auditor" global) :
        // aucune restriction par store pour CETTE permission, managed_store_ids = null
        // (pas un heuristique calculé ailleurs).
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 7, 'user_id' => 10, 'role_id' => 4, 'scope_type' => 'global', 'scope_id' => null],
        ] : []);
        kintai_test_roles(findById: fn() => ['id' => 4, 'is_system' => 0], getPermissions: fn() => ['employees.view']);
        $request = $this->makeRequest('employees.view', ['id' => 10], null);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(json_decode($response->getContent(), true)['managed']);
    }

    /**
     * Un rôle store-scope (ex. Manager sur le store 3) peut accorder une
     * permission précise en portée globale (case "Toutes les boutiques"
     * cochée dans l'éditeur de rôle) : managed_store_ids devient null pour
     * CETTE permission uniquement, sans changer la portée de l'affectation.
     */
    public function testPerPermissionGlobalScopeOnRoleNarrowsToNullForThatKey(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 3],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['shifts.view'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 2 ? ['shifts.view'] : []);
        $request  = $this->makeRequest('shifts.view', ['id' => 10], [3]);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(json_decode($response->getContent(), true)['managed']);
    }

    /** La même affectation reste restreinte à son store pour une permission non global-flaggée. */
    public function testPerPermissionGlobalScopeDoesNotLeakToOtherPermissionsOnTheSameRole(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 3],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['shifts.view', 'shifts.update'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 2 ? ['shifts.view'] : []);
        $request  = $this->makeRequest('shifts.update', ['id' => 10], [3]);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([3], json_decode($response->getContent(), true)['managed']);
    }

    // -------------------------------------------------------------------------
    // Règle 'membership' (bundle DailyReport) : accès en libre-service pour
    // tout membre du store ciblé (paramètre de route 'id'), sans permission
    // RBAC dédiée.
    // -------------------------------------------------------------------------

    public function testMembershipRuleAllowsAnyStoreMemberWithoutPermission(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [] : []);
        kintai_test_store_users(findMembership: fn() => ['store_id' => 1, 'user_id' => 10]);
        $request = $this->makeRequest(['perm' => 'daily_reports.create', 'membership' => true], ['id' => 10], null, ['id' => '1']);

        $this->assertSame(200, $this->middleware()->handle($request, $this->next())->getStatusCode());
    }

    /**
     * Ni membre du store ciblé, ni aucune permission RBAC nulle part : retombe sur la
     * porte grossière (ex-AdminMiddleware), qui redirige vers /employee — pas un 403,
     * cohérent avec le comportement d'origine où AdminMiddleware aurait de toute façon
     * intercepté cet utilisateur avant qu'il n'atteigne la vérification de permission.
     */
    public function testMembershipRuleRejectsNonMemberWithoutPermission(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [] : []);
        kintai_test_store_users(findMembership: fn() => null);
        $request  = $this->makeRequest(['perm' => 'daily_reports.create', 'membership' => true], ['id' => 10], null, ['id' => '1']);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testMembershipRuleStillGrantsViaScopedPermissionAndNarrows(): void
    {
        // Un gestionnaire avec daily_reports.create sur le store passe par la
        // clé RBAC normale (managed_store_ids narrowé), sans consulter l'adhésion.
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['daily_reports.create'] : []);
        $request  = $this->makeRequest(['perm' => 'daily_reports.create', 'membership' => true], ['id' => 10], null, ['id' => '1']);
        $response = $this->middleware()->handle($request, function (Request $r) {
            return response()->json(['managed' => $r->attributes->get('managed_store_ids')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1], json_decode($response->getContent(), true)['managed']);
    }

    public function testNonMembershipRuleNeverConsultsMembershipAndRedirectsWithoutAnyPermission(): void
    {
        // Une règle sans 'membership' n'a pas d'exception libre-service (ex. admin.daily_reports.delete).
        // Aucune permission nulle part → porte grossière → /employee (pas de findMembership consulté).
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [] : []);
        $request  = $this->makeRequest('daily_reports.delete', ['id' => 10], null, ['id' => '1']);
        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(302, $response->getStatusCode());
    }

    /** Une permission RBAC existe ailleurs (porte grossière franchie) mais pas la clé exacte requise : 403. */
    public function testNonMembershipRuleForbiddenWhenSomePermissionExistsButNotTheRequiredOne(): void
    {
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['photos.view'] : []);
        $request = $this->makeRequest('daily_reports.delete', ['id' => 10], null, ['id' => '1']);

        $this->expectException(ForbiddenException::class);
        $this->middleware()->handle($request, $this->next());
    }
}
