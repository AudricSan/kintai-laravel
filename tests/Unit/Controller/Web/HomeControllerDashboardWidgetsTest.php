<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\ShiftRepositoryInterface;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserDashboardPref;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\DashboardAlertService;
use App\Services\StoreStatsServiceInterface;
use App\Http\Controllers\HomeController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Régression : le panneau "Personnaliser" du dashboard admin décochait bien les widgets
 * à l'écran, mais un widget décoché puis sauvegardé réapparaissait toujours au chargement
 * suivant. Cause : index() recalculait les widgets affichés avec
 * array_intersect(array_unique(array_merge($saved, ADMIN_WIDGETS)), ADMIN_WIDGETS), qui
 * réintroduit systématiquement la totalité de ADMIN_WIDGETS quel que soit $saved (ADMIN_WIDGETS
 * est fusionné dans l'ensemble avant l'intersection, donc il en fait toujours partie).
 */
final class HomeControllerDashboardWidgetsTest extends TestCase
{
    use \Tests\Support\PrefsExpectations;

    private HomeController $controller;

    protected function setUp(): void
    {
        $this->ensureViewFile('dashboard.index');
        $this->ensureViewFile('layout.app');

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findAll')->willReturn([]);
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findAll')->willReturn([]);
        $shifts = $this->createStub(ShiftRepositoryInterface::class);
        $shifts->method('findAllByDate')->willReturn([]);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturn([]);
        $storeStats = $this->createStub(StoreStatsServiceInterface::class);
        $permissions = new PermissionService(
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
        );
        $dashboardAlerts = new DashboardAlertService($shifts, $users, $storeUsers);

        $this->controller = new HomeController(
            kintai_test_views(sys_get_temp_dir()),
            $users,
            $stores,
            $shifts,
            $storeUsers,
            $storeStats,
            $dashboardAlerts,
            $permissions,
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testWidgetsUncheckedAndSavedByTheUserStayHiddenOnNextLoad(): void
    {
        // L'utilisateur n'a gardé que 'kpi_counters' coché lors de la dernière sauvegarde.
        $this->seedWidgets(1, 'admin', ['kpi_counters']);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => 1]);

        $response = $this->controller->index($req);
        $enabledWidgets = json_decode($response->getContent(), true);

        $this->assertSame(['kpi_counters'], $enabledWidgets);
    }

    private function ensureViewFile(string $view): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, "<?php echo json_encode(array_keys(\$enabled_widgets ?? []));");
    }
}
