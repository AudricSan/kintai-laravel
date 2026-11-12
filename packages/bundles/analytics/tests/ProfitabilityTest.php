<?php

namespace Kintai\Bundles\Analytics\Tests;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\Analytics\ProfitabilityService;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Contracts\Sales\DailySales;
use Kintai\Contracts\Sales\DailySalesProvider;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Rentabilité : ventes, coût saisi et clients du jour lus auprès du fournisseur de ventes du contrat (valeurs du jour),
 * face au coût calculé depuis les shifts ; sans fournisseur de ventes, « aucune donnée » sans erreur.
 */
class ProfitabilityTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBundle('analytics');
        $this->store = Store::factory()->create();
        ShiftType::factory()->create(['starts_at' => '06:00', 'ends_at' => '22:00', 'hourly_rate' => 12.5])->stores()->attach($this->store);
    }

    private function service(): ProfitabilityService
    {
        return app(ProfitabilityService::class);
    }

    private function report(string $date, string $status, float $sales, float $labor = 0, int $customers = 0, ?Store $store = null): void
    {
        DailyReport::query()->create([
            'store_id' => ($store ?? $this->store)->id, 'report_date' => $date, 'status' => $status,
            'sales_total' => $sales, 'labor_cost' => $labor, 'customer_count' => $customers,
        ]);
    }

    private function shift(string $date, int $pause = 60, ?Store $store = null): void
    {
        Shift::factory()->create([
            'store_id' => ($store ?? $this->store)->id, 'user_id' => User::factory(), 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, '09:00'), 'ends_at' => Shift::localTime($date, '17:00'), 'pause_minutes' => $pause,
        ]);
    }

    public function test_rows_and_totals_come_from_the_day_values_of_the_provider(): void
    {
        $this->enableBundle('daily-report');
        $this->report('2026-07-01', 'validated', 1000, 200, 10);
        $this->report('2026-07-02', 'validated', 1100, 210, 12);
        $this->report('2026-07-03', 'submitted', 1200, 200, 11);
        $this->report('2026-07-04', 'draft', 999, 0, 0);
        $this->report('2026-08-01', 'validated', 5000, 0, 0);
        $this->report('2026-07-01', 'validated', 777, 0, 0, Store::factory()->create());

        $result = $this->service()->storeProfitability($this->store->id, '2026-07-01', '2026-07-03');

        $this->assertTrue($result['available']);
        $this->assertSame(3300.0, $result['totalSales']);
        $this->assertSame(610.0, $result['totalLaborMnl']);
        $this->assertSame(33, $result['totalCustomers']);
        $this->assertSame([1000.0, 1100.0, 1200.0], array_column($result['rows'], 'sales'));
        $this->assertSame(['validated', 'validated', 'submitted'], array_column($result['rows'], 'status'));
        $this->assertSame(['2026-07' => 3300.0], $result['salesByMonth']);
        $this->assertSame(3, $result['reportCount']);
        $this->assertSame(18.5, $result['avgLaborPct']);
        $this->assertSame(100.0, $result['revPerCust']);
        $this->assertSame(20.0, $result['rows'][0]['lc_pct']);
        $this->assertSame(100.0, $result['rows'][0]['rev_per_cust']);
        $this->assertSame(['2026-07-01' => 1000.0, '2026-07-02' => 1100.0, '2026-07-03' => 1200.0], $result['salesByDay']);
        $this->assertSame(['2026-07' => 18.5], $result['lcPctByMonth']);
        $this->assertSame(['2026-07' => 33], $result['customersByMonth']);
    }

    public function test_drafts_are_read_with_their_status(): void
    {
        $this->enableBundle('daily-report');
        $this->report('2026-07-04', 'draft', 999);

        $result = $this->service()->storeProfitability($this->store->id, '2026-07-01', '2026-07-31');

        $this->assertSame(['draft'], array_column($result['rows'], 'status'));
        $this->assertSame(999.0, $result['totalSales']);
        $this->assertNull($result['rows'][0]['rev_per_cust']);
    }

    public function test_the_computed_labor_cost_comes_from_the_shifts_of_each_day(): void
    {
        $this->enableBundle('daily-report');
        $this->report('2026-07-01', 'validated', 1000, 100);
        $this->report('2026-07-02', 'validated', 0, 40);
        $this->shift('2026-07-01');
        $this->shift('2026-07-01', 0);
        $this->shift('2026-07-01', 0, Store::factory()->create());

        $result = $this->service()->storeProfitability($this->store->id, '2026-07-01', '2026-07-02');

        // 7 h + 8 h à 12,5 : 87,5 + 100.
        $this->assertSame(187.5, $result['rows'][0]['labor_calc']);
        $this->assertSame(15.0, $result['rows'][0]['hours']);
        $this->assertSame(18.8, $result['rows'][0]['lc_pct_calc']);
        $this->assertSame(-87.5, $result['rows'][0]['variance']);
        $this->assertSame(0.0, $result['rows'][1]['labor_calc']);
        $this->assertNull($result['rows'][1]['lc_pct']);
        $this->assertSame(187.5, $result['totalLaborCalc']);
        $this->assertSame(140.0, $result['totalLaborMnl']);
        $this->assertSame(-47.5, $result['variance']);
        $this->assertSame(1, $result['reportCount'], 'only the days with sales count');
    }

    public function test_an_empty_period_has_no_rows_and_no_error(): void
    {
        $this->enableBundle('daily-report');
        $this->report('2026-08-01', 'validated', 1000);

        $result = $this->service()->storeProfitability($this->store->id, '2026-07-01', '2026-07-31');

        $this->assertTrue($result['available']);
        $this->assertSame([], $result['rows']);
        $this->assertSame(0.0, $result['totalSales']);
        $this->assertNull($result['avgLaborPct']);
        $this->assertNull($result['revPerCust']);
    }

    public function test_without_a_sales_provider_there_is_no_sales_data_and_no_error(): void
    {
        $this->shift('2026-07-01');

        $result = $this->service()->storeProfitability($this->store->id, '2026-07-01', '2026-07-03');

        $this->assertFalse($result['available']);
        $this->assertSame([], $result['rows']);
        $this->assertSame(0.0, $result['totalSales']);
        $this->assertSame(0.0, $result['totalLaborCalc']);
        $this->assertSame([], $result['salesByMonth']);
    }

    public function test_a_disabled_daily_report_bundle_leaves_the_empty_binding(): void
    {
        $this->report('2026-07-01', 'validated', 1000);

        $this->assertFalse(app(DailySalesProvider::class)->available());
        $this->assertFalse($this->service()->storeProfitability($this->store->id, '2026-07-01', '2026-07-03')['available']);
    }

    public function test_any_provider_of_the_contract_feeds_the_profitability(): void
    {
        $this->app->bind(DailySalesProvider::class, fn () => new class implements DailySalesProvider
        {
            public function dailySales(int $storeId, CarbonInterface $from, CarbonInterface $to, ?array $statuses = null): array
            {
                return [new DailySales('2026-07-02', 'validated', 500.0, 100.0, 5, 1.0)];
            }

            public function available(): bool
            {
                return true;
            }
        });

        $result = $this->service()->storeProfitability($this->store->id, '2026-07-01', '2026-07-03');

        $this->assertSame([500.0], array_column($result['rows'], 'sales'));
        $this->assertSame(20.0, $result['avgLaborPct']);
    }
}
