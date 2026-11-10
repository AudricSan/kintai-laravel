<?php

namespace Kintai\Bundles\Analytics\Tests;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kintai\Bundles\Analytics\AnalyticsAccess;
use Kintai\Bundles\Analytics\StoreStatsService;
use Kintai\Contracts\Planning\TimeOffPeriod;
use Kintai\Contracts\Planning\TimeOffProvider;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Statistiques d'un magasin : parité avec le service d'origine (valeurs manquantes, découpage des périodes, cache de la
 * requête, secrets absents de la liste d'utilisateurs, coûts), cas limites (magasin vide, shifts ouverts) et portée magasin
 * de la comparaison.
 */
class StoreStatsTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $store;

    private User $user;

    private ShiftType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->enableBundle('analytics');
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
        Cache::store('array')->flush();

        $this->store = Store::factory()->create(['name' => 'Alpha', 'currency' => 'JPY']);
        $this->user = $this->employeeOf($this->store, ['first_name' => 'Aiko', 'last_name' => 'Sato']);
        // Les coûts viennent des types de shift du magasin : un seul type, de 06:00 à 22:00, à 12,5 l'heure.
        $this->type = ShiftType::factory()->create(['starts_at' => '06:00', 'ends_at' => '22:00', 'hourly_rate' => 12.5]);
        $this->type->stores()->attach($this->store);
    }

    private function service(): StoreStatsService
    {
        return app(StoreStatsService::class);
    }

    private function day(int $daysAgo): string
    {
        return Carbon::parse('2026-11-11')->subDays($daysAgo)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(string $date, string $start = '09:00', string $end = '17:00', int $pause = 60, array $attributes = [], ?Store $store = null, ?User $user = null): Shift
    {
        $end = Shift::localTime($date, $end);
        $startsAt = Shift::localTime($date, $start);
        if ($end->lessThanOrEqualTo($startsAt)) {
            $end = $end->addDay();
        }

        return Shift::factory()->create([
            'store_id' => ($store ?? $this->store)->id, 'user_id' => ($user ?? $this->user)->id, 'shift_date' => $date,
            'starts_at' => $startsAt, 'ends_at' => $end, 'pause_minutes' => $pause, ...$attributes,
        ]);
    }

    private function shiftQueries(): int
    {
        return count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "shifts"')));
    }

    // -- Parité : valeurs manquantes -----------------------------------------------------------------------------

    public function test_a_shift_without_creator_or_type_falls_back_to_defaults(): void
    {
        $this->shift($this->day(1), attributes: ['created_by' => null, 'shift_type_id' => null]);

        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame(1, $stats['shiftsCreatedByManager'][0] ?? null);
        $this->assertArrayHasKey('Not defined', $stats['hoursByType']);
        $this->assertEquals(7.0, $stats['hoursByType']['Not defined']);
        $this->assertSame(1, $stats['n']);
    }

    // -- Parité : cache de la requête ----------------------------------------------------------------------------

    public function test_the_same_stats_are_computed_only_once_and_in_one_read(): void
    {
        $this->shift($this->day(1));
        DB::enableQueryLog();

        $first = $this->service()->storeStats($this->store->id, 30);
        $second = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->shiftQueries(), 'one shifts read for both calls');
        $this->assertTrue(Cache::store('array')->has('analytics:stats:'.$this->store->id.':30:0:0:2026-11-11'));
    }

    public function test_the_read_is_limited_to_the_previous_plus_current_period(): void
    {
        $this->shift($this->day(90));
        DB::enableQueryLog();

        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame(0, $stats['n']);
        $reads = array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "shifts"')));
        $this->assertCount(1, $reads);
        $this->assertContains($this->day(60), array_map(fn ($binding) => (string) $binding, $reads[0]['bindings']));
        $this->assertContains($this->day(0), array_map(fn ($binding) => (string) $binding, $reads[0]['bindings']));
    }

    public function test_different_arguments_are_not_served_from_the_same_cache_entry(): void
    {
        DB::enableQueryLog();

        $this->service()->storeStats($this->store->id, 30);
        $this->service()->storeStats($this->store->id, 180);
        $this->service()->storeStats($this->store->id, 30, 240, 600);

        $this->assertSame(3, $this->shiftQueries());
    }

    public function test_the_cache_does_not_outlive_the_day_or_a_flush(): void
    {
        $this->shift($this->day(1));
        $this->assertSame(1, $this->service()->storeStats($this->store->id, 30)['n']);
        $this->shift($this->day(2));

        $this->assertSame(1, $this->service()->storeStats($this->store->id, 30)['n'], 'served from the cache for the request');

        $this->travelTo(Carbon::parse('2026-11-12 12:00:00', 'UTC'));
        $this->assertSame(2, $this->service()->storeStats($this->store->id, 30)['n'], 'the day is part of the key');

        $this->shift($this->day(3));
        Cache::store('array')->flush();
        $this->assertSame(3, $this->service()->storeStats($this->store->id, 30)['n']);
    }

    public function test_the_cost_by_month_is_the_cost_of_the_full_stats_without_computing_them(): void
    {
        $this->shift($this->day(1));
        $this->shift($this->day(40));
        $full = $this->service()->storeStats($this->store->id, 60)['costByMonth'];
        Cache::store('array')->flush();

        $this->assertSame($full, $this->service()->costByMonth($this->store->id, 60));
        $this->assertSame(['2026-10' => 87.5, '2026-11' => 87.5], $full);
        $this->assertTrue(Cache::store('array')->has('analytics:cost:'.$this->store->id.':60:2026-11-11'));
        $this->assertFalse(Cache::store('array')->has('analytics:stats:'.$this->store->id.':60:0:0:2026-11-11'));
    }

    // -- Parité : périodes et secrets ----------------------------------------------------------------------------

    public function test_current_and_previous_periods_are_split_from_the_single_read(): void
    {
        $this->shift($this->day(1));
        $this->shift($this->day(10));
        $this->shift($this->day(45));
        $this->shift($this->day(2))->delete();

        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame(2, $stats['n']);
        $this->assertSame(100.0, $stats['delta']['n'], 'one shift in the previous period, two now');
    }

    public function test_the_users_list_never_carries_secrets(): void
    {
        $this->shift($this->day(1));

        $user = $this->service()->storeStats($this->store->id, 30)['usersMap'][$this->user->id];

        $this->assertSame('Sato', $user['last_name'], 'the other fields stay available');
        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $secret) {
            $this->assertArrayNotHasKey($secret, $user);
        }
    }

    // -- Calculs -------------------------------------------------------------------------------------------------

    public function test_costs_follow_the_calculator_of_the_core(): void
    {
        $this->shift($this->day(1), attributes: ['shift_type_id' => $this->type->id]);
        $this->shift($this->day(2), attributes: ['hourly_rate_override' => 15, 'net_minutes_override' => 420]);
        $this->user->personalRates()->attach($this->type, ['hourly_rate' => 20]);
        $this->shift($this->day(3));

        $stats = $this->service()->storeStats($this->store->id, 30);

        // 7 h au taux personnel (20) + 7 h au taux plat (15) + 7 h au taux personnel (20).
        $this->assertSame(385.0, $stats['totalCost']);
        $this->assertSame(21.0, $stats['totalNetHours']);
        $this->assertSame(18.33, $stats['avgCostPerHour']);
        $this->assertSame(128.33, $stats['avgCostPerShift']);
    }

    public function test_the_planning_indicators_of_an_employee_are_counted(): void
    {
        // Deux jours de suite, 9 h de repos entre les deux, puis un shift qui chevauche le précédent.
        $this->shift($this->day(5), '09:00', '17:00');
        $this->shift($this->day(4), '02:00', '10:00');
        $this->shift($this->day(4), '09:00', '13:00', 0);
        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame(3, $stats['n']);
        $this->assertSame(1, $stats['shortRestCount']);
        $this->assertSame(1, $stats['conflictsCount']);
        $this->assertSame(2, $stats['maxConsecDays']);
        $this->assertSame(2.0, $stats['avgConsecDays']);
        $this->assertSame(3, $stats['openingShifts']);
        $this->assertSame(0, $stats['closingShifts']);
    }

    public function test_modified_shifts_lower_the_stability_score(): void
    {
        $edited = $this->shift($this->day(1));
        $this->shift($this->day(2));
        $this->assertSame(100.0, $this->service()->storeStats($this->store->id, 30)['stabilityScore']);

        Cache::store('array')->flush();
        $this->travel(2)->hours();
        $edited->update(['notes' => 'changed']);
        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame(50.0, $stats['modRate']);
        $this->assertSame(50.0, $stats['stabilityScore']);
        $this->assertSame(2.0, $stats['avgModDelay']);
        $this->assertSame([3 => 1], array_filter($stats['modByDow']), 'modified on a Wednesday (2026-11-11)');
    }

    public function test_open_shifts_and_other_stores_are_left_out(): void
    {
        $this->shift($this->day(1));
        Shift::factory()->open()->create([
            'store_id' => $this->store->id, 'shift_date' => $this->day(1),
            'starts_at' => Shift::localTime($this->day(1), '09:00'), 'ends_at' => Shift::localTime($this->day(1), '17:00'),
        ]);
        $other = Store::factory()->create();
        $this->shift($this->day(1), store: $other);

        $this->assertSame(1, $this->service()->storeStats($this->store->id, 30)['n']);
    }

    public function test_an_empty_store_gives_neutral_statistics(): void
    {
        $empty = Store::factory()->create();

        $stats = $this->service()->storeStats($empty->id, 30);

        $this->assertSame(0, $stats['n']);
        $this->assertSame([], $stats['memberIds']);
        $this->assertSame(0.0, $stats['totalCost']);
        $this->assertSame(0, $stats['avgCostPerHour']);
        $this->assertSame(100.0, $stats['equityScore']);
        $this->assertEquals(100, $stats['efficiencyScore']);
        $this->assertSame(100.0, $stats['stabilityScore']);
        $this->assertSame(0, $stats['burnoutRisk']);
        $this->assertNull($stats['avgTimeBetweenShifts']);
        $this->assertSame([null, null, null], array_values($stats['delta']));
        $this->assertSame([], $this->service()->costByMonth($empty->id, 30));
    }

    public function test_an_empty_period_keeps_the_members_without_shifts(): void
    {
        $this->shift($this->day(200));

        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame(0, $stats['n']);
        $this->assertSame([$this->user->id], $stats['memberIds']);
        $this->assertEquals(0, $stats['absRate']);
    }

    public function test_the_duration_bounds_count_short_and_long_shifts(): void
    {
        $this->shift($this->day(1), '09:00', '12:00', 0);
        $this->shift($this->day(2), '08:00', '19:00', 0);

        $stats = $this->service()->storeStats($this->store->id, 30, 240, 600);

        $this->assertSame(1, $stats['shortRateShifts']);
        $this->assertSame(1, $stats['longRateShifts']);
        $this->assertNull($this->service()->storeStats($this->store->id, 30)['shortRateShifts']);
        $this->assertSame([1, 0, 1], [$stats['distShort'], $stats['distMedium'], $stats['distLong']]);
    }

    // -- Absences par le contrat ---------------------------------------------------------------------------------

    public function test_absences_come_from_the_approved_time_off_of_the_contract(): void
    {
        $second = $this->employeeOf($this->store);
        $user = $this->user;
        $this->app->bind(TimeOffProvider::class, fn () => new class($user->id) implements TimeOffProvider
        {
            public function __construct(private int $userId) {}

            public function approvedFor(int $userId, int $storeId, CarbonInterface $from, CarbonInterface $to): array
            {
                return $userId === $this->userId ? [
                    new TimeOffPeriod('timeoff-1', '2026-11-05', '2026-11-07'),
                    new TimeOffPeriod('timeoff-2', '2026-09-01', '2026-09-03'),
                ] : [];
            }
        });

        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame(3, $stats['approvedDays']);
        $this->assertSame(5.0, $stats['absRate']);
        $this->assertSame([$this->user->id => 1], $stats['timeoffsByUser']);
        $this->assertSame(1, $stats['timeoffsByStatus']['approved']);
        $this->assertSame(0, $stats['timeoffsByStatus']['pending']);
        $this->assertCount(2, $stats['memberIds']);
        $this->assertContains($second->id, $stats['memberIds']);
    }

    public function test_without_a_time_off_bundle_there_is_no_absence(): void
    {
        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame(0, $stats['approvedDays']);
        $this->assertSame(['pending' => 0, 'approved' => 0, 'refused' => 0, 'cancelled' => 0], $stats['timeoffsByStatus']);
    }

    // -- Rapport par employé et export ---------------------------------------------------------------------------

    public function test_the_employee_report_lists_every_member_by_net_hours(): void
    {
        $idle = $this->employeeOf($this->store);
        $busy = $this->employeeOf($this->store);
        $this->shift($this->day(1), user: $this->user);
        $this->shift($this->day(1), user: $busy);
        $this->shift($this->day(2), user: $busy);
        $this->shift($this->day(2), user: $busy)->delete();

        $report = $this->service()->employeeReport($this->store->id, 30);

        $this->assertSame([$busy->id, $this->user->id, $idle->id], array_keys($report['employeeStats']));
        $this->assertSame(14.0, $report['employeeStats'][$busy->id]['net_hours']);
        $this->assertSame(2, $report['employeeStats'][$busy->id]['max_consec']);
        $this->assertSame(0, $report['employeeStats'][$idle->id]['shifts']);
        $this->assertSame(3, $report['totalShifts']);
        $this->assertSame(2, $report['activeCount']);
        $this->assertSame(21.0, $report['totalNetHours']);
        $this->assertSame(262.5, $report['totalCost']);
        $this->assertSame('Employee', $report['membersMap'][$idle->id]['role_name']);
        $this->assertFalse($report['membersMap'][$idle->id]['role_is_managing']);
        $this->assertArrayNotHasKey('password', $report['usersMap'][$idle->id]);
    }

    public function test_the_employee_report_of_an_empty_store_is_empty(): void
    {
        $report = $this->service()->employeeReport(Store::factory()->create()->id, 30);

        $this->assertSame([], $report['employeeStats']);
        $this->assertSame(0, $report['totalShifts']);
        $this->assertFalse($report['anyHasRate']);
    }

    public function test_the_export_data_matches_the_statistics(): void
    {
        $this->shift($this->day(1));
        $this->shift($this->day(2), '09:00', '13:00', 0);

        $export = $this->service()->storeStatsExportData($this->store->id, 30);
        $stats = $this->service()->storeStats($this->store->id, 30);

        $this->assertSame($stats['n'], $export['n']);
        $this->assertEqualsWithDelta($stats['totalNetHours'], $export['totalNetHours'], 0.05);
        $this->assertSame($stats['costByMonth'], $export['costByMonth']);
        $this->assertEquals([$this->user->id => 11.0], $export['hoursByUser']);
        $this->assertSame($stats['equityScore'], $export['equityScore']);
    }

    // -- Comparaison et portée magasin ---------------------------------------------------------------------------

    public function test_the_comparison_gives_the_key_indicators_of_each_store(): void
    {
        $second = Store::factory()->create(['name' => 'Zulu', 'currency' => 'EUR']);
        $this->shift($this->day(1));

        $rows = $this->service()->multiStoreComparison([$this->store->id, $second->id, 999999], 30);

        $this->assertCount(2, $rows, 'an unknown store is ignored');
        $this->assertSame('Alpha', $rows[0]['store_name']);
        $this->assertSame(1, $rows[0]['total_shifts']);
        $this->assertSame(7.0, $rows[0]['total_hours']);
        $this->assertSame(87.5, $rows[0]['total_cost']);
        $this->assertSame(1, $rows[0]['employee_count']);
        $this->assertSame('EUR', $rows[1]['currency']);
        $this->assertSame(0, $rows[1]['total_shifts']);
        $this->assertSame([], $this->service()->multiStoreComparison([], 30));
    }

    public function test_the_comparison_is_limited_to_the_stores_the_viewer_may_see(): void
    {
        $second = Store::factory()->create(['name' => 'Zulu']);
        Store::factory()->create(['name' => 'Gone', 'is_active' => false]);
        $restricted = User::factory()->create();
        $restricted->assignRole($this->customRole(['payroll.view']), $this->store);
        $nobody = $this->employeeOf($this->store);
        $owner = $this->owner();

        $this->assertSame([$this->store->id], array_column($this->service()->comparisonFor($restricted, 30), 'store_id'));
        $this->assertSame([$this->store->id, $second->id], array_column($this->service()->comparisonFor($owner, 30), 'store_id'));
        $this->assertSame([], $this->service()->comparisonFor($nobody, 30));
    }

    public function test_access_follows_the_payroll_permissions_with_their_store_scope(): void
    {
        $second = Store::factory()->create();
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['payroll.view']), $this->store);
        $exporter = User::factory()->create();
        $exporter->assignRole($this->customRole(['payroll.view', 'payroll.export'], ['payroll.export']), $this->store);
        $access = app(AnalyticsAccess::class);

        $this->assertTrue($access->canView($viewer, $this->store->id));
        $this->assertFalse($access->canView($viewer, $second->id));
        $this->assertFalse($access->canExport($viewer, $this->store->id));
        $this->assertSame([$this->store->id], $access->viewableStoreIds($viewer));
        $this->assertSame([], $access->exportableStoreIds($viewer));
        $this->assertTrue($access->canExport($exporter, $second->id), 'all_stores lifts the store scope');
        $this->assertSame([$this->store->id, $second->id], $access->exportableStoreIds($exporter));
    }
}
