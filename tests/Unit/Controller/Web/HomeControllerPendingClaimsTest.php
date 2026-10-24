<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\DashboardAlertService;
use kintai\Core\Services\StoreStatsServiceInterface;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Tests\Support\FakeBundleManagerFactory;
use kintai\Tests\Support\FakeClaimsProvider;
use kintai\Tests\Support\FakePendingTimeoffProvider;
use kintai\UI\Controller\Web\HomeController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Le KPI "Demandes en attente" du dashboard admin additionne aussi les
 * candidatures bourse aux shifts en attente, fournies par le bundle shift-claim
 * (fournisseur de demandes du contrat 1.9) : présentes quand il est actif,
 * absentes — sans planter le dashboard — quand il ne l'est pas.
 */
final class HomeControllerPendingClaimsTest extends TestCase
{
    protected function setUp(): void
    {
        $this->ensureViewFile('dashboard.index', '<?php ?>');
        $this->ensureViewFile('layout.app', "<?php echo json_encode(['stats' => \$stats]);");
    }

    protected function tearDown(): void
    {
        // Réinitialise le singleton Container pour ne pas propager le
        // BundleManager/ExtensionRegistry injectés vers d'autres tests.
        $instance = new \ReflectionProperty(Container::class, 'instance');
        $instance->setValue(null, null);
    }

    private function buildController(): HomeController
    {
        $usersRepo = $this->createStub(UserRepositoryInterface::class);
        $usersRepo->method('findAll')->willReturn([]);

        $storesRepo = $this->createStub(StoreRepositoryInterface::class);
        $storesRepo->method('findAll')->willReturn([['id' => 1, 'name' => 'Store 1']]);

        $shiftsRepo = $this->createStub(ShiftRepositoryInterface::class);
        $shiftsRepo->method('findAllByDate')->willReturn([]);

        $shiftTypesRepo = $this->createStub(ShiftTypeRepositoryInterface::class);

        $dashboardPrefs = $this->createStub(UserDashboardPrefsRepositoryInterface::class);
        $dashboardPrefs->method('getEnabledWidgets')->willReturn(['kpi_counters']);

        $storeUsersRepo = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsersRepo->method('findByStore')->willReturn([]);

        $storeStats = $this->createStub(StoreStatsServiceInterface::class);

        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $permissions = new PermissionService($roleAssignments, $roles);

        $dashboardAlerts = new DashboardAlertService($shiftsRepo, $usersRepo, $storeUsersRepo);

        // Le compteur lit les demandes à travers les fournisseurs (PendingRequests), qui prennent leurs dépôts dans le
        // conteneur.
        $container = Container::getInstance();
        $container->instance(ShiftRepositoryInterface::class, $shiftsRepo);
        $container->instance(StoreRepositoryInterface::class, $storesRepo);

        return new HomeController(
            new ViewRenderer(sys_get_temp_dir()),
            $usersRepo,
            $storesRepo,
            $shiftsRepo,
            $shiftTypesRepo,
            $dashboardPrefs,
            $storeUsersRepo,
            $storeStats,
            $dashboardAlerts,
            $permissions,
        );
    }

    /** Un congé en attente sur le magasin 1, fourni par timeoff. */
    private static function timeoff(): array
    {
        return ['timeoff' => [new FakePendingTimeoffProvider([['id' => 1, 'store_id' => 1, 'user_id' => 5, 'status' => 'pending']])]];
    }

    private function request(): Request
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 99, 'is_admin' => 1]);
        $req->setAttribute('managed_store_ids', null);
        return $req;
    }

    public function testDashboardDoesNotCrashAndExcludesClaimsWhenShiftClaimBundleDisabled(): void
    {
        $manager = FakeBundleManagerFactory::withActiveSlugs(['timeoff'], [], self::timeoff());
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));

        $controller = $this->buildController();
        $response   = $controller->index($this->request());
        $data       = json_decode($response->body(), true);

        // 1 congé pending, aucune candidature (bundle désactivé) : total = 1.
        $this->assertSame(1, $data['stats']['pending_requests']);
    }

    public function testPendingRequestsIncludesPendingClaimsWhenBundleEnabled(): void
    {
        // Candidatures en attente déclarées par shift-claim (fournisseur du contrat 1.9) : une sur le magasin 1.
        $manager = FakeBundleManagerFactory::withActiveSlugs(['timeoff', 'shift-claim'], [], self::timeoff() + ['shift-claim' => [
            new FakeClaimsProvider([FakeClaimsProvider::claim(1, 1, 5, '2026-08-10', '09:00:00', '17:00:00')]),
        ]]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));

        $controller = $this->buildController();
        $response   = $controller->index($this->request());
        $data       = json_decode($response->body(), true);

        // 1 congé pending + 1 candidature pending = 2.
        $this->assertSame(2, $data['stats']['pending_requests']);
    }

    private function ensureViewFile(string $view, string $content): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, $content);
    }
}
