<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\UserDashboardPref;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\DashboardAlertService;
use App\Services\StoreStatsService;
use App\Http\Controllers\HomeController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * Widgets "financial_overview" (masse salariale du mois en cours vs précédent + tendance)
 * et "hr_absenteeism" (absentéisme, retards, heures sup) : n'apparaissent que si le
 * widget est activé ET que l'utilisateur a la permission payroll.view (même garde que
 * store_stats_summary).
 */
final class HomeControllerFinancialHrWidgetsTest extends TestCase
{
    use \Tests\Support\PrefsExpectations;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $this->ensureViewFile(
            'dashboard.index',
            "<?php echo json_encode(['financial' => \$financial_overview ?? null, 'hr' => \$hr_stats ?? null]);"
        );
        $this->ensureViewFile('layout.app', "<?php echo \$content ?? '';");
    }

    private function buildController(array $enabledWidgets, bool $isAdmin, array $storeStatsByStore): HomeController
    {
        kintai_test_users(findAll: fn() => []);
        kintai_test_stores(findById: fn() => ['id' => 1, 'name' => 'Store 1', 'currency' => 'JPY', 'currency_symbol_style' => 'kanji'], findAll: fn() => [['id' => 1, 'name' => 'Store 1', 'currency' => 'JPY', 'currency_symbol_style' => 'kanji']]);
        kintai_test_shifts([]);
        kintai_test_shifts([]);
        kintai_test_shift_types([]);
        $this->seedWidgets(1, 'admin', $enabledWidgets);
        kintai_test_store_users(findByStore: fn() => []);
        $storeStats = $this->createStub(StoreStatsService::class);
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
        kintai_test_assignments(findByUser: fn() => $isAdmin ? [] : [
            ['id' => 1, 'role_id' => 5, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 5 ? ['id' => 5, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 5 ? ['shifts.view'] : []);
        // Rôle "manager" sans payroll.view : la permission RH/financière n'est accordée
        // qu'à l'admin global (is_admin=1) dans ce test.
        $permissions = new PermissionService();

        $dashboardAlerts = new DashboardAlertService();

        return new HomeController(
            kintai_test_views(sys_get_temp_dir()),
            $storeStats,
            $dashboardAlerts,
            $permissions,
        );
    }

    private function request(bool $isAdmin): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => $isAdmin ? 1 : 0]);
        $req->attributes->set('managed_store_ids', $isAdmin ? null : [1]);
        return $req;
    }

    public function testFinancialAndHrAbsentWhenWidgetsDisabled(): void
    {
        $controller = $this->buildController(['kpi_counters'], true, []);
        $response = $controller->index($this->request(true));
        $data = json_decode($response->getContent(), true);

        $this->assertNull($data['financial']);
        $this->assertNull($data['hr']);
    }

    public function testHrAbsenteeismAbsentWithoutPayrollPermission(): void
    {
        $controller = $this->buildController(['hr_absenteeism', 'financial_overview'], false, []);
        $response = $controller->index($this->request(false));
        $data = json_decode($response->getContent(), true);

        $this->assertNull($data['financial']);
        $this->assertNull($data['hr']);
    }

    public function testFinancialOverviewComputesCurrentAndPreviousMonthDelta(): void
    {
        $currentMonth  = date('Y-m');
        $previousMonth = date('Y-m', strtotime('first day of last month'));

        $controller = $this->buildController(['financial_overview'], true, [
            1 => [
                'costByMonth'      => [$previousMonth => 1000.0, $currentMonth => 1500.0],
                'absRate'          => 0,
                'timeoffsByStatus' => [],
                'timeoffsByType'   => [],
            ],
        ]);
        $response = $controller->index($this->request(true));
        $data = json_decode($response->getContent(), true);

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
        $data = json_decode($response->getContent(), true);

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
