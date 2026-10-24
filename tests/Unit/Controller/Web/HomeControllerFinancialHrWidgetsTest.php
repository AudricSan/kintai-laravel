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
use PHPUnit\Framework\TestCase;

/**
 * Widgets "financial_overview" (masse salariale du mois en cours vs précédent + tendance)
 * et "hr_absenteeism" (absentéisme, retards, heures sup) : n'apparaissent que si le
 * widget est activé ET que l'utilisateur a la permission payroll.view (même garde que
 * store_stats_summary).
 */
final class HomeControllerFinancialHrWidgetsTest extends TestCase
{
    protected function setUp(): void
    {
        $this->ensureViewFile(
            'dashboard.index',
            "<?php echo json_encode(['financial' => \$financial_overview ?? null, 'hr' => \$hr_stats ?? null]);"
        );
        $this->ensureViewFile('layout.app', "<?php echo \$content ?? '';");
    }

    private function buildController(array $enabledWidgets, bool $isAdmin, array $storeStatsByStore): HomeController
    {
        $usersRepo = $this->createStub(UserRepositoryInterface::class);
        $usersRepo->method('findAll')->willReturn([]);

        $storesRepo = $this->createStub(StoreRepositoryInterface::class);
        $storesRepo->method('findAll')->willReturn([['id' => 1, 'name' => 'Store 1', 'currency' => 'JPY', 'currency_symbol_style' => 'kanji']]);
        $storesRepo->method('findById')->willReturn(['id' => 1, 'name' => 'Store 1', 'currency' => 'JPY', 'currency_symbol_style' => 'kanji']);

        $shiftsRepo = $this->createStub(ShiftRepositoryInterface::class);
        $shiftsRepo->method('findAllByDate')->willReturn([]);
        $shiftsRepo->method('findByStore')->willReturn([]);

        $shiftTypesRepo = $this->createStub(ShiftTypeRepositoryInterface::class);
        $shiftTypesRepo->method('findByStore')->willReturn([]);

        $dashboardPrefs = $this->createStub(UserDashboardPrefsRepositoryInterface::class);
        $dashboardPrefs->method('getEnabledWidgets')->willReturn($enabledWidgets);

        $storeUsersRepo = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsersRepo->method('findByStore')->willReturn([]);

        $storeStats = $this->createStub(StoreStatsServiceInterface::class);
        $storeStats->method('multiStoreComparison')->willReturn([]);
        // La vue financière ne lit plus que le coût par mois (costByMonth()), pas storeStats() complet.
        $storeStats->method('costByMonth')->willReturnCallback(
            fn(int $storeId, int $period) => $storeStatsByStore[$storeId]['costByMonth'] ?? []
        );
        $storeStats->method('storeStats')->willReturnCallback(
            fn(int $storeId, int $period) => $storeStatsByStore[$storeId] ?? [
                'costByMonth' => [], 'absRate' => 0, 'timeoffsByStatus' => [], 'timeoffsByType' => [],
            ]
        );

        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn($isAdmin ? [] : [
            ['id' => 1, 'role_id' => 5, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $roles = $this->createMock(RoleRepositoryInterface::class);
        $roles->method('findById')->with(5)->willReturn(['id' => 5, 'is_system' => 0]);
        // Rôle "manager" sans payroll.view : la permission RH/financière n'est accordée
        // qu'à l'admin global (is_admin=1) dans ce test.
        $roles->method('getPermissions')->with(5)->willReturn(['shifts.view']);
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

    private function request(bool $isAdmin): Request
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => $isAdmin ? 1 : 0]);
        $req->setAttribute('managed_store_ids', $isAdmin ? null : [1]);
        return $req;
    }

    public function testFinancialAndHrAbsentWhenWidgetsDisabled(): void
    {
        $controller = $this->buildController(['kpi_counters'], true, []);
        $response = $controller->index($this->request(true));
        $data = json_decode($response->body(), true);

        $this->assertNull($data['financial']);
        $this->assertNull($data['hr']);
    }

    public function testHrAbsenteeismAbsentWithoutPayrollPermission(): void
    {
        $controller = $this->buildController(['hr_absenteeism', 'financial_overview'], false, []);
        $response = $controller->index($this->request(false));
        $data = json_decode($response->body(), true);

        $this->assertNull($data['financial']);
        $this->assertNull($data['hr']);
    }

    public function testFinancialOverviewComputesCurrentAndPreviousMonthDelta(): void
    {
        $currentMonth  = date('Y-m');
        $previousMonth = date('Y-m', strtotime('-1 month'));

        $controller = $this->buildController(['financial_overview'], true, [
            1 => [
                'costByMonth'      => [$previousMonth => 1000.0, $currentMonth => 1500.0],
                'absRate'          => 0,
                'timeoffsByStatus' => [],
                'timeoffsByType'   => [],
            ],
        ]);
        $response = $controller->index($this->request(true));
        $data = json_decode($response->body(), true);

        $this->assertNotNull($data['financial']);
        $this->assertEqualsWithDelta(1500.0, $data['financial']['current_month'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $data['financial']['previous_month'], 0.001);
        $this->assertEqualsWithDelta(50.0, $data['financial']['delta_pct'], 0.001);
    }

    public function testHrAbsenteeismAggregatesAbsRateAndTimeoffTaken(): void
    {
        $controller = $this->buildController(['hr_absenteeism'], true, [
            1 => [
                'costByMonth'      => [],
                'absRate'          => 4.5,
                'timeoffsByStatus' => ['approved' => 3, 'pending' => 1],
                'timeoffsByType'   => ['vacation' => 3, 'sick' => 1],
            ],
        ]);
        $response = $controller->index($this->request(true));
        $data = json_decode($response->body(), true);

        $this->assertNotNull($data['hr']);
        $this->assertSame(4.5, $data['hr']['abs_rate']);
        $this->assertSame(3, $data['hr']['timeoff_taken']);
        $this->assertSame(0, $data['hr']['late_count']);
        $this->assertSame(0, $data['hr']['overtime_weeks']);
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
