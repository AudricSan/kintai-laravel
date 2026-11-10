<?php

namespace Kintai\Bundles\DailyReport\Tests;

use App\Models\Store;
use App\Sales\NullDailySalesProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Kintai\Bundles\DailyReport\DailyReportSales;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Kintai\Contracts\Sales\DailySales;
use Kintai\Contracts\Sales\DailySalesProvider;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Ventes journalières fournies au contrat : jours triés, statuts filtrés avant la conversion des saisies cumulées en
 * valeurs du jour, magasins isolés, liaison remplacée seulement bundle activé.
 */
class DailyReportSalesTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private function report(Store $store, string $date, string $status, float $sales, float $labor = 0, int $customers = 0, float $waste = 0): DailyReport
    {
        return DailyReport::query()->create([
            'store_id' => $store->id, 'report_date' => $date, 'status' => $status,
            'sales_total' => $sales, 'labor_cost' => $labor, 'customer_count' => $customers, 'waste_total' => $waste,
        ]);
    }

    /**
     * @param  list<DailySales>  $days
     * @return list<float>
     */
    private function salesOf(array $days): array
    {
        return array_map(fn (DailySales $day): float => $day->sales, $days);
    }

    public function test_the_binding_is_replaced_only_while_the_bundle_is_enabled(): void
    {
        $this->assertInstanceOf(NullDailySalesProvider::class, app(DailySalesProvider::class));
        $this->assertFalse(app(DailySalesProvider::class)->available());

        $this->enableBundle('daily-report');

        $this->assertInstanceOf(DailyReportSales::class, app(DailySalesProvider::class));
        $this->assertTrue(app(DailySalesProvider::class)->available());
    }

    public function test_days_are_sorted_with_their_values_and_status(): void
    {
        $store = Store::factory()->create();
        $this->report($store, '2026-10-02', 'validated', 200, 50, 20, 3);
        $this->report($store, '2026-10-01', 'draft', 100);
        $this->report($store, '2026-11-01', 'validated', 999);
        $this->report(Store::factory()->create(), '2026-10-01', 'validated', 777);

        $days = (new DailyReportSales)->dailySales($store->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertSame(['2026-10-01', '2026-10-02'], array_map(fn (DailySales $day): string => $day->date, $days));
        $this->assertSame(['draft', 'validated'], array_map(fn (DailySales $day): string => $day->status, $days));
        $this->assertEquals(new DailySales('2026-10-02', 'validated', 200.0, 50.0, 20, 3.0), $days[1]);
    }

    public function test_an_empty_period_has_no_days(): void
    {
        $store = Store::factory()->create();

        $this->assertSame([], (new DailyReportSales)->dailySales($store->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));
    }

    public function test_cumulative_input_is_converted_after_the_status_filter(): void
    {
        $store = Store::factory()->create();
        DailyReportSetting::query()->create(['store_id' => $store->id, 'cumulative_mode' => 'cumulative_input']);
        $this->report($store, '2026-10-01', 'validated', 1000, 200, 10);
        $this->report($store, '2026-10-02', 'draft', 2100, 410, 22);
        $this->report($store, '2026-10-03', 'submitted', 3300, 600, 30);
        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-10-31');

        $all = (new DailyReportSales)->dailySales($store->id, $from, $to);
        $this->assertSame([1000.0, 1100.0, 1200.0], $this->salesOf($all));
        $this->assertSame([10, 12, 8], array_map(fn (DailySales $day): int => $day->customers, $all));
        $this->assertSame([200.0, 210.0, 190.0], array_map(fn (DailySales $day): float => $day->laborCost, $all));

        $kept = (new DailyReportSales)->dailySales($store->id, $from, $to, ['validated', 'submitted']);
        $this->assertSame([1000.0, 2300.0], $this->salesOf($kept));
    }

    public function test_per_day_values_are_kept_as_entered(): void
    {
        $store = Store::factory()->create();
        $this->report($store, '2026-10-01', 'validated', 1000);
        $this->report($store, '2026-10-02', 'validated', 2100);

        $days = (new DailyReportSales)->dailySales($store->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertSame([1000.0, 2100.0], $this->salesOf($days));
    }
}
