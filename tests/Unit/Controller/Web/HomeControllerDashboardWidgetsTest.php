<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
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
use kintai\UI\Controller\Web\HomeController;
use kintai\UI\ViewRenderer;
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
    private UserDashboardPrefsRepositoryInterface&MockObject $dashboardPrefs;
    private HomeController $controller;

    protected function setUp(): void
    {
        $this->ensureViewFile('dashboard.index');
        $this->ensureViewFile('layout.app');

        $this->dashboardPrefs = $this->createMock(UserDashboardPrefsRepositoryInterface::class);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findAll')->willReturn([]);
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findAll')->willReturn([]);
        $shifts = $this->createStub(ShiftRepositoryInterface::class);
        $shifts->method('findAllByDate')->willReturn([]);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturn([]);
        $shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
        $storeStats = $this->createStub(StoreStatsServiceInterface::class);
        $permissions = new PermissionService(
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
        );
        $dashboardAlerts = new DashboardAlertService($shifts, $users, $storeUsers);

        $this->controller = new HomeController(
            new ViewRenderer(sys_get_temp_dir()),
            $users,
            $stores,
            $shifts,
            $shiftTypes,
            $this->dashboardPrefs,
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
        $this->dashboardPrefs->method('getEnabledWidgets')->with(1, 'admin')->willReturn(['kpi_counters']);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => 1]);

        $response = $this->controller->index($req);
        $enabledWidgets = json_decode($response->body(), true);

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
