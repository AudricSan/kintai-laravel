<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\DashboardScope;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->storeA = Store::factory()->create(['name' => 'Alpha', 'min_staff_per_day' => 0]);
        $this->storeB = Store::factory()->create(['name' => 'Bravo', 'min_staff_per_day' => 0]);
    }

    private function today(int $offset = 0): string
    {
        return app(DashboardScope::class)->today()->addDays($offset)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(Store $store, ?User $user, int $dayOffset = 0, string $start = '09:00', string $end = '17:00', array $attributes = []): Shift
    {
        $shift = Shift::fromLocalTimes($this->today($dayOffset), $start, $end, ['store_id' => $store->id, 'user_id' => $user?->id] + $attributes);
        $shift->save();

        return $shift;
    }

    /**
     * @return array<string, int>
     */
    private function counters(User $user): array
    {
        $this->actingAs($user);
        $component = Livewire::test('widgets::manager.counters');

        return array_map(fn (array $counter): int => $counter['value'], $component->viewData('counters'));
    }

    public function test_counters_cover_all_stores_for_the_owner(): void
    {
        $this->employeeOf($this->storeA);
        $this->employeeOf($this->storeB);
        $this->employeeOf($this->storeB, ['is_active' => false]);
        $this->shift($this->storeA, null, 3);
        $this->shift($this->storeB, null, 3);
        $this->shift($this->storeA, null, -2);
        $this->shift($this->storeA, $this->employeeOf($this->storeA));
        $this->shift($this->storeB, null, 0);

        $counters = $this->counters($this->owner());

        $this->assertSame(['employees' => 3, 'stores' => 2, 'today' => 2, 'open' => 3], $counters);
    }

    public function test_counters_of_a_restricted_manager_stay_within_his_stores(): void
    {
        $this->employeeOf($this->storeA);
        $this->employeeOf($this->storeB);
        $this->employeeOf($this->storeB);
        $this->shift($this->storeA, null, 3);
        $this->shift($this->storeB, null, 3);
        $this->shift($this->storeB, null, 0);
        $this->shift($this->storeA, null, 0);
        $this->shift($this->storeA, $this->employeeOf($this->storeA), 0, '18:00', '22:00');

        $counters = $this->counters($this->managerOf($this->storeA));

        $this->assertSame(['employees' => 2, 'stores' => 1, 'today' => 2, 'open' => 2], $counters);
    }

    public function test_inactive_stores_are_not_counted(): void
    {
        Store::factory()->inactive()->create();

        $this->assertSame(2, $this->counters($this->owner())['stores']);
    }

    public function test_alerts_count_conflicts_of_the_next_seven_days_only(): void
    {
        $user = User::factory()->create();
        $this->shift($this->storeA, $user, 2, '09:00', '17:00');
        $this->shift($this->storeA, $user, 2, '12:00', '18:00');
        // Hors de la fenêtre de 7 jours.
        $this->shift($this->storeA, $user, 9, '09:00', '17:00');
        $this->shift($this->storeA, $user, 9, '12:00', '18:00');
        // Même employé, pas de chevauchement.
        $this->shift($this->storeA, $user, 3, '09:00', '12:00');
        $this->shift($this->storeA, $user, 3, '13:00', '17:00');

        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('widgets::manager.alerts')->assertViewHas('conflicts', 1);
    }

    public function test_alerts_of_a_restricted_manager_ignore_other_stores(): void
    {
        $user = User::factory()->create();
        $this->shift($this->storeB, $user, 1, '09:00', '17:00');
        $this->shift($this->storeB, $user, 1, '12:00', '18:00');

        $this->actingAs($this->managerOf($this->storeA));
        Livewire::test('widgets::manager.alerts')->assertViewHas('conflicts', 0);

        $this->actingAs($this->owner());
        Livewire::test('widgets::manager.alerts')->assertViewHas('conflicts', 1);
    }

    public function test_alerts_count_understaffed_days_with_the_timeline_logic(): void
    {
        $this->storeA->update(['min_staff_per_day' => 2]);
        $this->storeB->update(['min_staff_per_day' => 2]);
        // Jour J : deux employés, effectif atteint ; jour J+1 : un seul.
        $this->shift($this->storeA, User::factory()->create(), 0);
        $this->shift($this->storeA, User::factory()->create(), 0);
        $this->shift($this->storeA, User::factory()->create(), 1);

        $this->actingAs($this->managerOf($this->storeA));
        $restricted = Livewire::test('widgets::manager.alerts')->viewData('understaffed');

        $this->assertCount(6, $restricted, '7 days minus the staffed one, store A only');
        $this->assertSame(['Alpha'], array_values(array_unique(array_column($restricted, 'store'))));

        $this->actingAs($this->owner());
        $this->assertCount(13, Livewire::test('widgets::manager.alerts')->viewData('understaffed'));
    }

    public function test_stores_without_minimum_staff_never_raise_the_alert(): void
    {
        $this->actingAs($this->owner());

        Livewire::test('widgets::manager.alerts')->assertViewHas('understaffed', []);
    }

    public function test_shifts_today_lists_the_visible_stores_only(): void
    {
        $this->shift($this->storeA, User::factory()->create(['name' => 'Alice Martin']));
        $this->shift($this->storeA, null, 0, '10:00', '14:00');
        $this->shift($this->storeB, User::factory()->create(['name' => 'Bob Durand']));
        $this->shift($this->storeA, User::factory()->create(['name' => 'Tomorrow Person']), 1);

        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('widgets::manager.shifts-today')
            ->assertSee('Alpha')
            ->assertSee('Alice Martin')
            ->assertSee('Open shift')
            ->assertDontSee('Bravo')
            ->assertDontSee('Bob Durand')
            ->assertDontSee('Tomorrow Person');

        $this->actingAs($this->owner());
        Livewire::test('widgets::manager.shifts-today')->assertSee('Bob Durand');
    }

    public function test_employee_widgets_only_show_the_employee_own_shifts(): void
    {
        $me = $this->employeeOf($this->storeA);
        $colleague = $this->employeeOf($this->storeA);
        $this->shift($this->storeA, $me, 0, '08:00', '12:00');
        $this->shift($this->storeA, $colleague, 0, '13:00', '17:00');
        $this->shift($this->storeA, $me, 2, '09:00', '17:00');
        $this->shift($this->storeA, $colleague, 3, '09:00', '17:00');

        $this->actingAs($me);

        Livewire::test('widgets::employee.shifts-today')
            ->assertSee('08:00 - 12:00')
            ->assertDontSee('13:00 - 17:00');

        $upcoming = Livewire::test('widgets::employee.upcoming');
        $this->assertCount(1, $upcoming->viewData('shifts'));
        $upcoming->assertSee('09:00 - 17:00');
    }

    public function test_employee_upcoming_excludes_today_and_is_limited_to_five(): void
    {
        $me = $this->employeeOf($this->storeA);
        $this->shift($this->storeA, $me, 0);
        foreach (range(1, 7) as $offset) {
            $this->shift($this->storeA, $me, $offset);
        }

        $this->actingAs($me);

        $shifts = Livewire::test('widgets::employee.upcoming')->viewData('shifts');
        $this->assertCount(5, $shifts);
        $this->assertSame($this->today(1), $shifts->first()->shift_date->toDateString());
    }

    public function test_employee_month_sums_planned_and_completed_hours_without_cost(): void
    {
        $me = $this->employeeOf($this->storeA);
        $first = app(DashboardScope::class)->today()->startOfMonth();
        $last = app(DashboardScope::class)->today()->endOfMonth();
        // 8 h - 1 h de pause = 7 h net chacun : un shift passé (début du mois), un futur (fin du mois).
        Shift::fromLocalTimes($first->toDateString(), '09:00', '17:00', ['store_id' => $this->storeA->id, 'user_id' => $me->id, 'pause_minutes' => 60])->save();
        Shift::fromLocalTimes($last->toDateString(), '09:00', '17:00', ['store_id' => $this->storeA->id, 'user_id' => $me->id, 'pause_minutes' => 60])->save();
        // Autre mois : ignoré.
        Shift::fromLocalTimes($last->addDays(2)->toDateString(), '09:00', '17:00', ['store_id' => $this->storeA->id, 'user_id' => $me->id])->save();

        $this->actingAs($me);

        $month = Livewire::test('widgets::employee.month');
        $month->assertViewHas('count', $first->equalTo($last) ? 1 : 2);
        $month->assertViewHas('plannedMinutes', $first->equalTo($last) ? 420 : 840);
        $month->assertDontSee('€');
    }
}
