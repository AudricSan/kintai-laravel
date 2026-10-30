<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\AuthService;
use App\Auth\PermissionService;
use App\BundleManager;
use Illuminate\Container\Container;
use App\FeatureManager;
use App\Http\Middleware\AuthMiddleware;
use App\Repositories\LanguageRepositoryInterface;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\TranslationRepositoryInterface;
use App\Models\UserNavPref;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\EmployeeStatsService;
use Illuminate\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * AuthMiddleware partage le helper de vue `user_can` pour TOUTES les pages
 * authentifiées (et non plus PermissionMiddleware, limité aux routes admin
 * mappées) : la navigation (sidebar, bottom-nav) doit refléter les mêmes
 * droits quelle que soit la page affichée.
 */
final class AuthMiddlewareTest extends TestCase
{
    private Container $container;
    private ViewFactory $view;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $this->container = new \Tests\Support\TestContainer();
        $this->view      = kintai_test_views(sys_get_temp_dir());
        $this->container->instance(ViewFactory::class, $this->view);
        $this->container->instance(FeatureManager::class, new FeatureManager([]));
        // BundleManager::__construct() exige une vraie Application (lourde à construire
        // ici, et final) ; isActive() ne lit que $bundles (vide par défaut), donc une
        // instance sans constructeur suffit pour ce test (aucun bundle réellement actif).
        $this->container->instance(BundleManager::class, (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor());

        kintai_test_translator();
        $this->container->instance(
            LanguageRepositoryInterface::class,
            $this->createStub(LanguageRepositoryInterface::class),
        );
        $this->container->instance(EmployeeStatsService::class, new EmployeeStatsService(
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
        ));
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
    }

    /**
     * Câble AuthService + PermissionService : l'utilisateur 10 est connecté,
     * affecté au rôle 2 (portée store 1) accordant $permissions.
     */
    private function bindAuthenticatedUser(array $permissions, bool $isSystemRole = false, bool $isManagerRole = false): void
    {
        session()->put(auth()->getName(), 10);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(['id' => 10, 'display_name' => 'Test']);
        kintai_test_roles(findById: fn() => ['id' => 2, 'is_system' => $isSystemRole ? 1 : 0, 'is_manager' => $isManagerRole ? 1 : 0], getPermissions: fn() => $permissions);
        kintai_test_assignments(findByUser: fn() => [
            [
                'id'         => 1,
                'user_id'    => 10,
                'role_id'    => 2,
                'scope_type' => $isSystemRole ? 'global' : 'store',
                'scope_id'   => $isSystemRole ? null : 1,
            ],
        ]);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $stores     = $this->createStub(StoreRepositoryInterface::class);

        $this->container->instance(AuthService::class, new AuthService(
            $users, $storeUsers, $stores,
            $this->createStub(UserRepositoryInterface::class),
        ));
        $this->container->instance(PermissionService::class, new PermissionService());
        $this->container->instance(StoreUserRepositoryInterface::class, $storeUsers);
        $this->container->instance(StoreRepositoryInterface::class, $stores);
    }

    private function handle(): Response
    {
        $middleware = new AuthMiddleware($this->container);
        return $middleware->handle(Request::capture(), fn(Request $r) => response('ok'));
    }

    public function testSharesUserCanReflectingRolePermissions(): void
    {
        $this->bindAuthenticatedUser(['shifts.view', 'timeoff.view']);

        $this->handle();

        $can = $this->view->shared('user_can');
        $this->assertIsCallable($can);
        $this->assertTrue($can('shifts.view'));
        $this->assertTrue($can('timeoff.view'));
        $this->assertFalse($can('employees.view'));
        $this->assertFalse($can('stores.delete'));
    }

    public function testOwnerSystemRoleGrantsEveryKey(): void
    {
        $this->bindAuthenticatedUser([], isSystemRole: true);

        $this->handle();

        $can = $this->view->shared('user_can');
        $this->assertTrue($can('employees.view'));
        $this->assertTrue($can('stores.delete'));
    }

    /**
     * auth_is_manager doit refléter le rôle réel de l'utilisateur, pas la route
     * visitée — c'est ce qui permet à app.php d'afficher la même topbar/bottomnav
     * sur /admin/* et sur les pages sans PermissionMiddleware (/employee, /profile...).
     */
    public function testSharesAuthIsManagerTrueForManagerRoleRegardlessOfRoute(): void
    {
        $this->bindAuthenticatedUser([], isManagerRole: true);

        $this->handle();

        $this->assertTrue($this->view->shared('auth_is_manager'));
    }

    public function testSharesAuthIsManagerFalseForPlainEmployee(): void
    {
        $this->bindAuthenticatedUser([]);

        $this->handle();

        $this->assertFalse($this->view->shared('auth_is_manager'));
    }

    public function testSharesManagedStoreIdsDefaultForManagerWhenNotAlreadySet(): void
    {
        $this->bindAuthenticatedUser([], isManagerRole: true);

        $this->handle();

        $this->assertSame([1], $this->view->shared('managed_store_ids'));
    }

    public function testDoesNotShareManagedStoreIdsForPlainEmployee(): void
    {
        $this->bindAuthenticatedUser([]);

        $this->handle();

        $this->assertNull($this->view->shared('managed_store_ids'));
    }

    /**
     * route_visible (RBAC-V2) : la visibilité de nav dérive de la permission
     * RÉELLEMENT déclarée sur la route (Route::$permission), jamais d'une clé
     * recopiée à la main à côté du lien.
     */
    public function testRouteVisibleReflectsThePermissionDeclaredOnTheRoute(): void
    {
        $router = kintai_test_registrar();
        $router->get('/admin/photos', [\App\Http\Controllers\HomeController::class, 'index'], name: 'admin.photos.index', permission: 'photos.view');
        $router->get('/admin/owner-settings', [\App\Http\Controllers\HomeController::class, 'index'], name: 'admin.owner_settings', permission: 'public');

        $this->bindAuthenticatedUser(['shifts.view']);
        $this->handle();

        $routeVisible = $this->view->shared('route_visible');
        $this->assertIsCallable($routeVisible);
        $this->assertFalse($routeVisible('admin.photos.index'), 'shifts.view ne doit pas rendre visible un lien vers une route exigeant photos.view');
        $this->assertTrue($routeVisible('admin.owner_settings'), "une route 'public' reste toujours visible");
        $this->assertTrue($routeVisible('route.inexistante'), 'une route inconnue ne doit pas bloquer l’affichage');
    }

    public function testRouteVisibleTrueWhenPermissionGranted(): void
    {
        $router = kintai_test_registrar();
        $router->get('/admin/photos', [\App\Http\Controllers\HomeController::class, 'index'], name: 'admin.photos.index', permission: 'photos.view');

        $this->bindAuthenticatedUser(['photos.view']);
        $this->handle();

        $this->assertTrue($this->view->shared('route_visible')('admin.photos.index'));
    }

    public function testRedirectsToLoginWhenNotAuthenticated(): void
    {
        session()->forget(auth()->getName());
        $users = $this->createStub(UserRepositoryInterface::class);
        $this->container->instance(AuthService::class, new AuthService(
            $users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
        ));

        $response = $this->handle();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->view->shared('user_can'));
    }
}
