<?php

namespace Kintai\Bundles\Analytics\Tests;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Kintai\Bundles\Analytics\EmployeeStatsService;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Le salaire estimé de `calculate` alimente le tableau de bord de l'employé et la liste du personnel : mêmes garanties que le
 * service d'origine (shifts supprimés exclus, pause déduite, taux personnel prioritaire, mois demandé), plus la fiche d'un
 * employé dans un magasin.
 */
class EmployeeStatsTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private Store $store;

    private User $user;

    private ShiftType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBundle('analytics');
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'UTC'));

        $this->store = Store::factory()->create(['currency' => 'eur', 'currency_symbol_style' => 'symbol']);
        $this->user = User::factory()->create();
        $this->store->members()->attach($this->user);
        $this->type = ShiftType::factory()->create(['name' => 'Matin', 'starts_at' => '06:00', 'ends_at' => '22:00', 'hourly_rate' => 1000]);
        $this->type->stores()->attach($this->store);
    }

    private function service(): EmployeeStatsService
    {
        return app(EmployeeStatsService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(string $date, string $start = '08:00', string $end = '16:00', int $pause = 60, array $attributes = [], ?Store $store = null): Shift
    {
        return Shift::factory()->create([
            'store_id' => ($store ?? $this->store)->id, 'user_id' => $this->user->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end), 'pause_minutes' => $pause, ...$attributes,
        ]);
    }

    // -- calculate : parité --------------------------------------------------------------------------------------

    public function test_deleted_shifts_are_excluded(): void
    {
        $this->shift('2026-11-05');
        $this->shift('2026-11-06')->delete();

        $stats = $this->service()->calculate($this->user->id);

        // 7 h nettes (480 - 60 de pause) pour le seul shift non supprimé.
        $this->assertEquals(7.0, $stats['hours_month']);
        $this->assertSame(7000.0, $stats['estimated_pay']);
        $this->assertTrue($stats['has_rate']);
    }

    public function test_the_pause_is_deducted_from_the_net_hours(): void
    {
        $this->shift('2026-11-05');

        $stats = $this->service()->calculate($this->user->id);

        $this->assertEquals(7.0, $stats['hours_month']);
        $this->assertSame(7000.0, $stats['estimated_pay']);
        $this->assertSame(420, $stats['shift_details'][0]['net_min']);
        $this->assertSame(60, $stats['shift_details'][0]['pause_min']);
    }

    public function test_the_personal_rate_overrides_the_shift_type_rate(): void
    {
        $this->user->personalRates()->attach($this->type, ['hourly_rate' => 1500]);
        $this->shift('2026-11-05');

        $stats = $this->service()->calculate($this->user->id);

        $this->assertSame(10500.0, $stats['estimated_pay']);
        $this->assertSame(1500.0, $stats['shift_details'][0]['rate']);
    }

    public function test_shifts_outside_the_requested_month_are_ignored(): void
    {
        $this->shift('2020-01-05');

        $stats = $this->service()->calculate($this->user->id);

        $this->assertEquals(0.0, $stats['hours_month']);
        $this->assertSame(0.0, $stats['estimated_pay']);
        $this->assertFalse($stats['has_rate']);
        $this->assertSame([], $stats['shift_details']);
    }

    // -- calculate : mois, devise, détail ------------------------------------------------------------------------

    public function test_the_requested_month_is_navigable_and_an_invalid_month_falls_back_to_the_current_one(): void
    {
        $this->shift('2026-10-05');
        $this->shift('2026-11-05');

        $october = $this->service()->calculate($this->user->id, '2026-10');
        $this->assertSame('2026-10', $october['month_key']);
        $this->assertSame('2026-09', $october['prev_month']);
        $this->assertSame('2026-11', $october['next_month']);
        $this->assertFalse($october['is_current']);
        $this->assertSame(7000.0, $october['estimated_pay']);
        $this->assertEqualsWithDelta(7 / 5, $october['hours_week'], 0.001, 'October 2026 touches five ISO weeks');

        foreach (['', 'nope', '2026-13', '2026-1'] as $invalid) {
            $stats = $this->service()->calculate($this->user->id, $invalid);
            $this->assertSame('2026-11', $stats['month_key']);
            $this->assertTrue($stats['is_current']);
        }
    }

    public function test_the_month_lists_the_shifts_of_every_store_in_order_with_the_currency_of_the_first_store(): void
    {
        $other = Store::factory()->create(['currency' => 'USD']);
        $this->type->stores()->attach($other);
        $this->shift('2026-11-07', '09:00', '17:00', 0);
        $this->shift('2026-11-02', '08:00', '12:00', 0, store: $other);
        $this->shift('2026-11-02', '06:00', '07:00', 0);

        $stats = $this->service()->calculate($this->user->id, '2026-11');

        $this->assertSame(['2026-11-02', '2026-11-02', '2026-11-07'], array_column($stats['shift_details'], 'date'));
        $this->assertSame(['06:00', '08:00', '09:00'], array_column($stats['shift_details'], 'start'));
        $this->assertSame('—', $stats['shift_details'][0]['type_name'], 'no type on the shift');
        $this->assertSame('EUR', $stats['currency']);
        $this->assertSame('symbol', $stats['currency_symbol_style']);
        $this->assertEqualsWithDelta(13.0, $stats['hours_month'], 0.001);
    }

    public function test_without_a_store_the_currency_defaults_to_yen(): void
    {
        $loner = User::factory()->create();

        $stats = $this->service()->calculate($loner->id);

        $this->assertSame('JPY', $stats['currency']);
        $this->assertSame('kanji', $stats['currency_symbol_style']);
        $this->assertSame([], $stats['shift_details']);
    }

    // -- employeeStats -------------------------------------------------------------------------------------------

    public function test_the_sheet_of_an_employee_sums_the_period(): void
    {
        $this->shift('2026-11-05', attributes: ['shift_type_id' => $this->type->id]);
        $this->shift('2026-11-06', '09:00', '13:00', 0);
        $this->shift('2026-11-08', '09:00', '13:00', 0)->delete();
        $this->shift('2026-06-01');
        $other = User::factory()->create();
        Shift::factory()->create([
            'store_id' => $this->store->id, 'user_id' => $other->id, 'shift_date' => '2026-11-05',
            'starts_at' => Shift::localTime('2026-11-05', '09:00'), 'ends_at' => Shift::localTime('2026-11-05', '17:00'),
        ]);

        $stats = $this->service()->employeeStats($this->store->id, $this->user->id, 30);

        $this->assertSame(2, $stats['totalShifts']);
        $this->assertSame(12.0, $stats['grossHours']);
        $this->assertSame(11.0, $stats['netHours']);
        $this->assertSame(11000.0, $stats['cost']);
        $this->assertTrue($stats['anyRate']);
        $this->assertSame(2, $stats['workDays']);
        $this->assertSame(2, $stats['maxConsec']);
        $this->assertSame(0, $stats['absDays']);
        $this->assertSame(0, $stats['swapCount']);
        $this->assertEquals(['Matin' => 7.0, 'No type' => 4.0], $stats['typeHours']);
    }

    public function test_the_sheet_has_twelve_months_a_week_chart_and_the_last_fifteen_shifts(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->shift(Carbon::parse('2026-11-10')->subDays($i)->toDateString(), '09:00', '10:00', 0);
        }
        $this->shift('2026-11-20');

        $stats = $this->service()->employeeStats($this->store->id, $this->user->id, 30);

        $this->assertCount(12, $stats['monthlyHours']);
        $this->assertSame('2025-12', array_key_first($stats['monthlyHours']));
        $this->assertSame('2026-11', array_key_last($stats['monthlyHours']));
        $this->assertEquals(9.0, $stats['monthlyHours']['2026-11']);
        $this->assertCount(15, $stats['recentShifts']);
        $this->assertSame('2026-11-09', $stats['recentShifts'][0]->shift_date->toDateString());
        $this->assertSame(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], array_keys($stats['dowChart']));
        $this->assertSame(array_sum($stats['dowChart']), array_sum($stats['typeHours']));
    }

    public function test_the_sheet_of_an_employee_without_shifts_is_empty(): void
    {
        $stats = $this->service()->employeeStats($this->store->id, $this->user->id, 30);

        $this->assertSame(0, $stats['totalShifts']);
        $this->assertSame(0.0, $stats['cost']);
        $this->assertFalse($stats['anyRate']);
        $this->assertSame(0, $stats['maxConsec']);
        $this->assertSame([], $stats['recentShifts']);
        $this->assertSame([], $stats['typeHours']);
    }
}
