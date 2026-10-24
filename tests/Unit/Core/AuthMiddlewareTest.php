<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\PermissionService;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\FeatureManager;
use kintai\Core\Middleware\AuthMiddleware;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Router;
use kintai\Core\Services\EmployeeStatsService;
use kintai\Core\Services\TranslationService;
use kintai\UI\ViewRenderer;
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
    private ViewRenderer $view;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->view      = new ViewRenderer(sys_get_temp_dir());
        $this->container->instance(ViewRenderer::class, $this->view);
        $this->container->instance(FeatureManager::class, new FeatureManager([]));
        // BundleManager::__construct() exige une vraie Application (lourde à construire
        // ici, et final) ; isActive() ne lit que $bundles (vide par défaut), donc une
        // instance sans constructeur suffit pour ce test (aucun bundle réellement actif).
        $this->container->instance(BundleManager::class, (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor());

        $this->container->instance(TranslationService::class, new TranslationService(
            $this->createStub(TranslationRepositoryInterface::class),
            $this->createStub(LanguageRepositoryInterface::class),
        ));
        $this->container->instance(
            LanguageRepositoryInterface::class,
            $this->createStub(LanguageRepositoryInterface::class),
        );
        $this->container->instance(
            UserNavPrefsRepositoryInterface::class,
            $this->createStub(UserNavPrefsRepositoryInterface::class),
        );
        $this->container->instance(EmployeeStatsService::class, new EmployeeStatsService(
            $this->createStub(ShiftRepositoryInterface::class),
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
        ));
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
    }

    /**
     * Câble AuthService + PermissionService : l'utilisateur 10 est connecté,
     * affecté au rôle 2 (portée store 1) accordant $permissions.
     */
    private function bindAuthenticatedUser(array $permissions, bool $isSystemRole = false, bool $isManagerRole = false): void
    {
        $_SESSION['auth_user_id'] = 10;

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(['id' => 10, 'display_name' => 'Test']);

        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 2, 'is_system' => $isSystemRole ? 1 : 0, 'is_manager' => $isManagerRole ? 1 : 0]);
        $roles->method('getPermissions')->willReturn($permissions);

        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturn([
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
            $users, $storeUsers, $stores, $roles, $assignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        ));
        $this->container->instance(PermissionService::class, new PermissionService($assignments, $roles));
        $this->container->instance(StoreUserRepositoryInterface::class, $storeUsers);
        $this->container->instance(StoreRepositoryInterface::class, $stores);
    }

    private function handle(): Response
    {
        $middleware = new AuthMiddleware($this->container);
        return $middleware->handle(new Request(), fn(Request $r) => Response::html('ok'));
    }

    public function testSharesUserCanReflectingRolePermissions(): void
    {
        $this->bindAuthenticatedUser(['shifts.view', 'timeoff.view']);

        $this->handle();

        $can = $this->view->get('user_can');
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

        $can = $this->view->get('user_can');
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

        $this->assertTrue($this->view->get('auth_is_manager'));
    }

    public function testSharesAuthIsManagerFalseForPlainEmployee(): void
    {
        $this->bindAuthenticatedUser([]);

        $this->handle();

        $this->assertFalse($this->view->get('auth_is_manager'));
    }

    public function testSharesManagedStoreIdsDefaultForManagerWhenNotAlreadySet(): void
    {
        $this->bindAuthenticatedUser([], isManagerRole: true);

        $this->handle();

        $this->assertSame([1], $this->view->get('managed_store_ids'));
    }

    public function testDoesNotShareManagedStoreIdsForPlainEmployee(): void
    {
        $this->bindAuthenticatedUser([]);

        $this->handle();

        $this->assertNull($this->view->get('managed_store_ids'));
    }

    /**
     * route_visible (RBAC-V2) : la visibilité de nav dérive de la permission
     * RÉELLEMENT déclarée sur la route (Route::$permission), jamais d'une clé
     * recopiée à la main à côté du lien.
     */
    public function testRouteVisibleReflectsThePermissionDeclaredOnTheRoute(): void
    {
        $router = new Router();
        $router->get('/admin/photos', [\kintai\UI\Controller\Web\HomeController::class, 'index'], name: 'admin.photos.index', permission: 'photos.view');
        $router->get('/admin/owner-settings', [\kintai\UI\Controller\Web\HomeController::class, 'index'], name: 'admin.owner_settings', permission: 'public');
        $this->container->instance(Router::class, $router);

        $this->bindAuthenticatedUser(['shifts.view']);
        $this->handle();

        $routeVisible = $this->view->get('route_visible');
        $this->assertIsCallable($routeVisible);
        $this->assertFalse($routeVisible('admin.photos.index'), 'shifts.view ne doit pas rendre visible un lien vers une route exigeant photos.view');
        $this->assertTrue($routeVisible('admin.owner_settings'), "une route 'public' reste toujours visible");
        $this->assertTrue($routeVisible('route.inexistante'), 'une route inconnue ne doit pas bloquer l’affichage');
    }

    public function testRouteVisibleTrueWhenPermissionGranted(): void
    {
        $router = new Router();
        $router->get('/admin/photos', [\kintai\UI\Controller\Web\HomeController::class, 'index'], name: 'admin.photos.index', permission: 'photos.view');
        $this->container->instance(Router::class, $router);

        $this->bindAuthenticatedUser(['photos.view']);
        $this->handle();

        $this->assertTrue($this->view->get('route_visible')('admin.photos.index'));
    }

    public function testRedirectsToLoginWhenNotAuthenticated(): void
    {
        unset($_SESSION['auth_user_id']);
        $users = $this->createStub(UserRepositoryInterface::class);
        $this->container->instance(AuthService::class, new AuthService(
            $users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->createStub(RememberTokenRepositoryInterface::class),
        ));

        $response = $this->handle();

        $this->assertSame(302, $response->status());
        $this->assertNull($this->view->get('user_can'));
    }
}
