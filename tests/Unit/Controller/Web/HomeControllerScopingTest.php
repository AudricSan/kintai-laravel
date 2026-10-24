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
use kintai\Core\Response;
use kintai\Core\Services\DashboardAlertService;
use kintai\Core\Services\StoreStatsServiceInterface;
use kintai\UI\Controller\Web\HomeController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Régression : le dashboard admin (HomeController::index) ignorait totalement
 * managed_store_ids et affichait toujours les données de TOUS les stores
 * (utilisateurs, shifts, congés, échanges, pointages), même pour un manager
 * restreint par PermissionMiddleware à un sous-ensemble de stores.
 */
final class HomeControllerScopingTest extends TestCase
{
    protected function setUp(): void
    {
        $this->ensureViewFile('dashboard.index', '<?php ?>');
        $this->ensureViewFile('layout.app', "<?php echo json_encode(['stats' => \$stats, 'shifts_today' => \$shifts_today]);");
    }

    private function buildController(
        array $users,
        array $stores,
        array $shiftsToday,
        array $timeoff,
        array $storeMembers,
    ): HomeController {
        $usersRepo = $this->createStub(UserRepositoryInterface::class);
        $usersRepo->method('findAll')->willReturn($users);

        $storesRepo = $this->createStub(StoreRepositoryInterface::class);
        $storesRepo->method('findAll')->willReturn($stores);

        $shiftsRepo = $this->createStub(ShiftRepositoryInterface::class);
        $shiftsRepo->method('findAllByDate')->willReturn($shiftsToday);

        $dashboardPrefs = $this->createStub(UserDashboardPrefsRepositoryInterface::class);
        // Widgets financial_overview/hr_absenteeism exclus : ces tests portent sur le
        // scoping managed_store_ids, pas sur le calcul RH/financier (storeStats() n'est
        // pas mocké pour ces widgets ici, voir HomeControllerFinancialHrWidgetsTest).
        $dashboardPrefs->method('getEnabledWidgets')->willReturn([
            'kpi_counters', 'store_stats_summary',
            'shifts_today', 'pending_timeoff', 'timeclocks_today',
        ]);

        $storeUsersRepo = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsersRepo->method('findByStore')->willReturnCallback(
            fn(int $storeId) => $storeMembers[$storeId] ?? []
        );

        $shiftTypesRepo = $this->createStub(ShiftTypeRepositoryInterface::class);

        $storeStats = $this->createStub(StoreStatsServiceInterface::class);
        $storeStats->method('multiStoreComparison')->willReturn([]);

        // Rôle non-système accordant payroll.view à n'importe quel store, pour que
        // canViewStats soit vrai aussi côté manager (is_admin = 0) dans ces tests.
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([
            ['id' => 1, 'role_id' => 5, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $roles = $this->createMock(RoleRepositoryInterface::class);
        $roles->method('findById')->with(5)->willReturn(['id' => 5, 'is_system' => 0]);
        $roles->method('getPermissions')->with(5)->willReturn(['payroll.view']);
        $permissions = new PermissionService($roleAssignments, $roles);

        $dashboardAlerts = new DashboardAlertService($shiftsRepo, $usersRepo, $storeUsersRepo);

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

    /** @return array{0: array, 1: array, 2: array, 3: array, 4: array} */
    private function fixtures(): array
    {
        $users  = [['id' => 1, 'email' => 'a@test.com'], ['id' => 2, 'email' => 'b@test.com']];
        $stores = [['id' => 1, 'name' => 'Store 1'], ['id' => 2, 'name' => 'Store 2']];
        $shifts = [
            ['id' => 10, 'store_id' => 1, 'user_id' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['id' => 11, 'store_id' => 2, 'user_id' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
        ];
        $timeoff = [['id' => 1, 'store_id' => 1, 'user_id' => 1, 'status' => 'pending']];
        $storeMembers = [
            1 => [['user_id' => 1]],
            2 => [['user_id' => 2]],
        ];

        return [$users, $stores, $shifts, $timeoff, $storeMembers];
    }

    public function testGlobalAdminSeesAllStores(): void
    {
        [$users, $stores, $shifts, $timeoff, $storeMembers] = $this->fixtures();
        $controller = $this->buildController($users, $stores, $shifts, $timeoff, $storeMembers);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 99, 'is_admin' => 1]);
        $req->setAttribute('managed_store_ids', null);

        $response = $controller->index($req);
        $data     = json_decode($this->body($response), true);

        $this->assertSame(2, $data['stats']['users']);
        $this->assertSame(2, $data['stats']['stores']);
        $this->assertCount(2, $data['shifts_today']);
    }

    public function testScopedManagerOnlySeesOwnStore(): void
    {
        [$users, $stores, $shifts, $timeoff, $storeMembers] = $this->fixtures();
        $controller = $this->buildController($users, $stores, $shifts, $timeoff, $storeMembers);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => 0]);
        $req->setAttribute('managed_store_ids', [1]);

        $response = $controller->index($req);
        $data     = json_decode($this->body($response), true);

        $this->assertSame(1, $data['stats']['users'], 'ne doit compter que les membres du store géré');
        $this->assertSame(1, $data['stats']['stores']);
        $this->assertCount(1, $data['shifts_today']);
        $this->assertSame(1, (int) $data['shifts_today'][0]['store_id']);
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

    private function body(Response $response): string
    {
        return $response->body();
    }
}
