<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\BundleManager;
use Illuminate\Container\Container;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserDashboardPref;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\DashboardAlertService;
use App\Services\StoreStatsServiceInterface;
use App\Extension\ExtensionRegistry;
use Tests\Support\FakeBundleManagerFactory;
use Tests\Support\FakeClaimsProvider;
use Tests\Support\FakePendingTimeoffProvider;
use App\Http\Controllers\HomeController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * Le KPI "Demandes en attente" du dashboard admin additionne aussi les
 * candidatures bourse aux shifts en attente, fournies par le bundle shift-claim
 * (fournisseur de demandes du contrat 1.9) : présentes quand il est actif,
 * absentes — sans planter le dashboard — quand il ne l'est pas.
 */
final class HomeControllerPendingClaimsTest extends TestCase
{
    use \Tests\Support\PrefsExpectations;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $this->ensureViewFile('dashboard.index', "<?php echo json_encode(['stats' => \$stats]);");
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
        kintai_test_shifts([]);
        $this->seedWidgets(99, 'admin', ['kpi_counters']);

        $storeUsersRepo = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsersRepo->method('findByStore')->willReturn([]);

        $storeStats = $this->createStub(StoreStatsServiceInterface::class);

        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $permissions = new PermissionService($roleAssignments, $roles);

        $dashboardAlerts = new DashboardAlertService($usersRepo, $storeUsersRepo);

        // Le compteur lit les demandes à travers les fournisseurs (PendingRequests), qui prennent leurs dépôts dans le
        // conteneur.
        $container = Container::getInstance();
        $container->instance(StoreRepositoryInterface::class, $storesRepo);

        return new HomeController(
            kintai_test_views(sys_get_temp_dir()),
            $usersRepo,
            $storesRepo,
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
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 99, 'is_admin' => 1]);
        $req->attributes->set('managed_store_ids', null);
        return $req;
    }

    public function testDashboardDoesNotCrashAndExcludesClaimsWhenShiftClaimBundleDisabled(): void
    {
        $manager = FakeBundleManagerFactory::withActiveSlugs(['timeoff'], [], self::timeoff());
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));

        $controller = $this->buildController();
        $response   = $controller->index($this->request());
        $data       = json_decode($response->getContent(), true);

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
        $data       = json_decode($response->getContent(), true);

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
