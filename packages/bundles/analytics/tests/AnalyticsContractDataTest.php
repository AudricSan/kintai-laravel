<?php

namespace Kintai\Bundles\Analytics\Tests;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kintai\Bundles\Analytics\EmployeeStatsService;
use Kintai\Bundles\Analytics\StoreStatsService;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Congés par statut et par type et échanges de shifts, lus par les contrats `TimeOffStatistics` et `ShiftSwapStatistics`
 * avec les vraies données des bundles timeoff et shift-swap : services et pages, blocs masqués quand ils sont vides.
 */
class AnalyticsContractDataTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $store;

    private User $aiko;

    private User $bao;

    private User $chie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->enableBundle('analytics');
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
        Cache::store('array')->flush();

        $this->store = Store::factory()->create(['name' => 'Alpha']);
        $this->aiko = $this->employeeOf($this->store, ['name' => 'Aiko Sato', 'first_name' => 'Aiko', 'last_name' => 'Sato']);
        $this->bao = $this->employeeOf($this->store, ['name' => 'Bao Chen', 'first_name' => 'Bao', 'last_name' => 'Chen']);
        $this->chie = $this->employeeOf($this->store, ['name' => 'Chie Mori', 'first_name' => 'Chie', 'last_name' => 'Mori']);
    }

    private function shiftFor(User $user, string $date): Shift
    {
        return Shift::factory()->create([
            'store_id' => $this->store->id, 'user_id' => $user->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, '09:00'), 'ends_at' => Shift::localTime($date, '17:00'),
        ]);
    }

    private function makeSwap(User $requester, User $target, string $status, string $createdAt, ?Store $store = null): ShiftSwapRequest
    {
        $store ??= $this->store;
        $shift = fn (User $user): int => Shift::factory()->create([
            'store_id' => $store->id, 'user_id' => $user->id, 'shift_date' => '2026-11-20',
            'starts_at' => Shift::localTime('2026-11-20', '09:00'), 'ends_at' => Shift::localTime('2026-11-20', '17:00'),
        ])->id;
        $swap = ShiftSwapRequest::create([
            'store_id' => $store->id, 'requester_id' => $requester->id, 'target_user_id' => $target->id,
            'requester_shift_id' => $shift($requester), 'target_shift_id' => $shift($target), 'status' => $status,
        ]);
        $swap->forceFill(['created_at' => $createdAt])->save();

        return $swap;
    }

    private function seedSwaps(): void
    {
        $this->enableBundle('shift-swap');
        $this->makeSwap($this->aiko, $this->bao, 'accepted', '2026-11-03 09:00:00');
        $this->makeSwap($this->bao, $this->aiko, 'accepted', '2026-11-11 08:00:00');
        $this->makeSwap($this->aiko, $this->chie, 'pending', '2026-11-04 09:00:00');
        $this->makeSwap($this->aiko, $this->chie, 'refused', '2026-11-05 09:00:00');
        $this->makeSwap($this->chie, $this->aiko, 'cancelled', '2026-11-06 09:00:00');
        // Hors période et d'un autre magasin : ignorés.
        $this->makeSwap($this->aiko, $this->bao, 'accepted', '2026-08-01 09:00:00');
        $this->makeSwap($this->aiko, $this->bao, 'accepted', '2026-11-03 09:00:00', Store::factory()->create());
    }

    // -- Services ------------------------------------------------------------------------------------------------

    public function test_the_employee_report_counts_accepted_swaps_of_the_requester_and_the_target(): void
    {
        $this->seedSwaps();

        $report = app(StoreStatsService::class)->employeeReport($this->store->id, 30);

        $this->assertSame(2, $report['employeeStats'][$this->aiko->id]['swaps']);
        $this->assertSame(2, $report['employeeStats'][$this->bao->id]['swaps']);
        $this->assertSame(0, $report['employeeStats'][$this->chie->id]['swaps']);
        $this->assertTrue($report['anySwaps']);
    }

    public function test_the_employee_sheet_counts_accepted_swaps_of_the_period(): void
    {
        $this->seedSwaps();
        $service = app(EmployeeStatsService::class);

        $this->assertSame(2, $service->employeeStats($this->store->id, $this->aiko->id, 30)['swapCount']);
        $this->assertSame(0, $service->employeeStats($this->store->id, $this->chie->id, 30)['swapCount']);
        // Sur 180 jours, l'échange d'août compte aussi.
        $this->assertSame(3, $service->employeeStats($this->store->id, $this->aiko->id, 180)['swapCount']);
    }

    public function test_without_a_swap_bundle_swaps_are_zero(): void
    {
        $report = app(StoreStatsService::class)->employeeReport($this->store->id, 30);

        $this->assertSame(0, $report['employeeStats'][$this->aiko->id]['swaps']);
        $this->assertFalse($report['anySwaps']);
        $this->assertSame(0, app(EmployeeStatsService::class)->employeeStats($this->store->id, $this->aiko->id, 30)['swapCount']);
    }

    // -- Pages ---------------------------------------------------------------------------------------------------

    public function test_the_store_page_shows_the_leave_blocks_by_status_and_type(): void
    {
        $this->enableBundle('timeoff');
        $this->shiftFor($this->aiko, '2026-11-04');
        TimeoffRequest::create(['user_id' => $this->aiko->id, 'store_id' => $this->store->id, 'type' => 'sick', 'status' => 'approved', 'start_date' => '2026-11-05', 'end_date' => '2026-11-05']);
        TimeoffRequest::create(['user_id' => $this->bao->id, 'store_id' => $this->store->id, 'type' => 'vacation', 'status' => 'pending', 'start_date' => '2026-11-06', 'end_date' => '2026-11-06']);

        $this->actingAs($this->owner())->get('/analytics?store='.$this->store->id)
            ->assertOk()
            ->assertSee('data-test="leave-by-status"', false)
            ->assertSee('Leave requests by status')
            ->assertSee('data-test="leave-by-type"', false)
            ->assertSee('Sick leave')
            ->assertSee('Vacation');
    }

    public function test_the_store_page_hides_the_leave_blocks_without_any_request(): void
    {
        $this->shiftFor($this->aiko, '2026-11-04');

        $this->actingAs($this->owner())->get('/analytics?store='.$this->store->id)
            ->assertOk()
            ->assertSee('data-test="section-hr"', false)
            ->assertDontSee('data-test="leave-by-status"', false)
            ->assertDontSee('data-test="leave-by-type"', false);
    }

    public function test_the_swap_column_and_card_show_only_with_accepted_swaps(): void
    {
        $owner = $this->owner();
        $employees = '/analytics/employees?store='.$this->store->id;
        $sheet = fn (User $user): string => '/analytics/employee?user='.$user->id.'&store='.$this->store->id;

        $this->actingAs($owner)->get($employees)->assertOk()->assertDontSee('Swaps');
        $this->actingAs($owner)->get($sheet($this->aiko))->assertOk()->assertDontSee('Swaps');

        $this->seedSwaps();

        $this->actingAs($owner)->get($employees)->assertOk()->assertSee('Swaps');
        $this->actingAs($owner)->get($sheet($this->aiko))->assertOk()->assertSee('Swaps')->assertSee('accepted shift swaps');
        $this->actingAs($owner)->get($sheet($this->chie))->assertOk()->assertDontSee('Swaps');
    }
}
