<?php

namespace Tests\Feature\Shifts;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Planning\TimelineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

/**
 * Écrans du planning : liste, calendrier, frise (création rapide, glisser-déposer), impression, vues employé.
 */
class ShiftScreensTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Alpha', 'week_start_day' => 3]);
        $this->manager = $this->userWith(['shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete'], $this->store);
        $this->employee = $this->employeeOf($this->store, ['name' => 'Emma']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions, Store $store): User
    {
        $user = User::factory()->create();
        $user->assignRole($this->customRole($permissions), $store);

        return $user;
    }

    private function shift(string $date = '2026-11-02', string $start = '09:00', string $end = '17:00', ?User $user = null, ?Store $store = null): Shift
    {
        $user ??= $this->employee;

        return Shift::factory()->create([
            'store_id' => ($store ?? $this->store)->id, 'user_id' => $user->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ]);
    }

    // --- Liste ---

    public function test_list_requires_shifts_view(): void
    {
        $this->actingAs($this->employee)->get(route('shifts.index'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('shifts.index'))->assertOk();
    }

    public function test_list_only_shows_shifts_of_visible_stores(): void
    {
        $other = Store::factory()->create(['name' => 'Beta']);
        $foreigner = $this->employeeOf($other, ['name' => 'Zoe']);
        $this->shift('2026-11-02');
        $this->shift('2026-11-02', '09:00', '17:00', $foreigner, $other);

        $this->actingAs($this->manager);
        Livewire::test('pages::shifts.index')->set('month', '2026-11')
            ->assertSee('Emma')->assertDontSee('Zoe')->assertDontSee('Beta');
    }

    public function test_list_filters_by_month_store_and_employee(): void
    {
        $other = User::factory()->create(['name' => 'Olivia']);
        $this->store->members()->attach($other);
        $this->shift('2026-11-02');
        $this->shift('2026-12-03', '09:00', '17:00', $other);
        $this->shift('2026-11-04', '09:00', '17:00', $other);
        Shift::factory()->open()->create([
            'store_id' => $this->store->id, 'shift_date' => '2026-11-05',
            'starts_at' => Shift::localTime('2026-11-05', '09:00'), 'ends_at' => Shift::localTime('2026-11-05', '17:00'),
        ]);

        $this->actingAs($this->manager);
        $page = Livewire::test('pages::shifts.index')->set('month', '2026-11');
        $page->assertSeeHtml('2026-11-02')->assertDontSeeHtml('2026-12-03')->assertSee('Open shift');

        $page->set('user_id', (string) $other->id);
        $this->assertSame([$other->id], $page->viewData('shifts')->pluck('user_id')->unique()->all());
        $page->set('user_id', 'open');
        $this->assertSame([null], $page->viewData('shifts')->pluck('user_id')->all());
        $page->set('user_id', '')->set('store_id', (string) $this->store->id)->assertSee('Emma');
    }

    public function test_cost_is_shown_only_with_payroll_view(): void
    {
        $this->shift();
        $viewer = $this->userWith(['shifts.view', 'payroll.view'], $this->store);

        $this->actingAs($this->manager);
        Livewire::test('pages::shifts.index')->set('month', '2026-11')->assertDontSeeHtml('data-test="shift-cost"');

        $this->actingAs($viewer);
        Livewire::test('pages::shifts.index')->set('month', '2026-11')->assertSeeHtml('data-test="shift-cost"');
    }

    public function test_bulk_deletion_and_restoration(): void
    {
        $first = $this->shift('2026-11-02');
        $second = $this->shift('2026-11-03');
        $kept = $this->shift('2026-11-04');

        $this->actingAs($this->manager);
        $page = Livewire::test('pages::shifts.index')->set('month', '2026-11')
            ->set('selected', [(string) $first->id, (string) $second->id])
            ->call('deleteSelected');

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($kept);
        $page->assertSet('selected', []);

        $page->set('showDeleted', true)->assertSee('Deleted')->call('restore', $first->id);
        $this->assertNotSoftDeleted($first);
    }

    public function test_bulk_deletion_is_all_or_nothing_across_stores(): void
    {
        $other = Store::factory()->create();
        $foreigner = $this->employeeOf($other);
        $mine = $this->shift('2026-11-02');
        $theirs = $this->shift('2026-11-03', '09:00', '17:00', $foreigner, $other);

        $this->actingAs($this->manager);
        Livewire::test('pages::shifts.index')->set('selected', [(string) $mine->id, (string) $theirs->id])
            ->call('deleteSelected')->assertForbidden();

        $this->assertNotSoftDeleted($mine);
    }

    public function test_deleting_requires_the_delete_permission(): void
    {
        $shift = $this->shift();
        $viewer = $this->userWith(['shifts.view'], $this->store);

        $this->actingAs($viewer);
        Livewire::test('pages::shifts.index')->set('month', '2026-11')
            ->assertDontSeeHtml('data-test="select-shift"')
            ->set('selected', [(string) $shift->id])->call('deleteSelected')->assertForbidden();
    }

    // --- Calendrier ---

    public function test_calendar_starts_on_the_store_week_start_day(): void
    {
        $this->shift('2026-11-04');
        $this->actingAs($this->manager);

        // week_start_day = 3 (mercredi) : le 1er novembre 2026 (dimanche) tombe dans la semaine du mercredi 28 octobre.
        $page = Livewire::test('pages::shifts.calendar')->set('month', '2026-11');
        $page->assertSeeHtmlInOrder(['data-date="2026-10-28"', 'data-date="2026-10-29"'])
            ->assertDontSeeHtml('data-date="2026-10-27"')
            ->assertSee('09:00-17:00')->assertSee('Emma');

        $this->assertStringContainsString(
            route('shifts.create', ['date' => '2026-11-04', 'store_id' => $this->store->id]),
            html_entity_decode($page->html()),
        );
    }

    public function test_calendar_requires_shifts_view_and_hides_other_stores(): void
    {
        $other = Store::factory()->create(['name' => 'Beta']);
        $foreigner = $this->employeeOf($other, ['name' => 'Zoe']);
        $this->shift('2026-11-04', '09:00', '17:00', $foreigner, $other);

        $this->actingAs($this->employee)->get(route('shifts.calendar'))->assertForbidden();
        $this->actingAs($this->manager);
        Livewire::test('pages::shifts.calendar')->set('month', '2026-11')->set('store_id', (string) $other->id)
            ->assertDontSee('Zoe');
    }

    public function test_create_form_is_prefilled_from_the_calendar_link(): void
    {
        $this->actingAs($this->manager)
            ->get(route('shifts.create', ['date' => '2026-11-04', 'store_id' => $this->store->id]))
            ->assertOk()->assertSee('2026-11-04');
    }

    // --- Frise ---

    private function timeline(string $view = 'day'): Testable
    {
        return Livewire::test('pages::shifts.timeline')
            ->set('store_id', (string) $this->store->id)->set('start', '2026-11-02')->set('view', $view);
    }

    public function test_timeline_builder_positions_bars_and_cuts_past_midnight(): void
    {
        $this->shift('2026-11-02', '06:00', '18:00');
        $night = $this->shift('2026-11-02', '22:00', '06:00', $this->manager);
        $night->update(['ends_at' => Shift::localTime('2026-11-03', '06:00')]);

        $days = app(TimelineBuilder::class)->build($this->store, CarbonImmutable::parse('2026-11-02'), 1, false);
        $rows = collect($days[0]['rows'])->keyBy(fn ($row) => $row['user']?->id ?? 0);

        $day = $rows[$this->employee->id]['bars'][0];
        $this->assertSame(25.0, $day['left']);
        $this->assertSame(50.0, $day['width']);
        $this->assertFalse($day['cutEnd']);

        $cut = $rows[$this->manager->id]['bars'][0];
        $this->assertTrue($cut['cutEnd']);
        $this->assertEqualsWithDelta(8.3333, $cut['width'], 0.001);
        $this->assertSame(480, $cut['duration']);
        $this->assertNull($rows[$this->employee->id]['cost']);
    }

    public function test_timeline_has_a_row_per_active_member_and_an_open_row(): void
    {
        $inactive = User::factory()->create(['name' => 'Inactive Ian']);
        $this->store->members()->attach($inactive, ['is_active' => false]);
        $this->shift();

        $days = app(TimelineBuilder::class)->build($this->store, CarbonImmutable::parse('2026-11-02'), 2, false);

        $this->assertCount(2, $days);
        $names = collect($days[0]['rows'])->map(fn ($row) => $row['user']?->name)->all();
        $this->assertContains('Emma', $names);
        $this->assertNotContains('Inactive Ian', $names);
        $this->assertNull(end($names));
    }

    public function test_timeline_flags_understaffed_days_and_hours(): void
    {
        $this->store->update(['min_staff_per_day' => 2, 'low_staff_hour_start' => 22, 'low_staff_hour_end' => 6]);
        $this->shift('2026-11-02', '09:00', '12:00');
        $this->shift('2026-11-02', '10:00', '12:00', $this->manager);

        $days = app(TimelineBuilder::class)->build($this->store->refresh(), CarbonImmutable::parse('2026-11-02'), 2, false);

        $this->assertFalse($days[0]['understaffed']);
        $this->assertSame(2, $days[0]['staff']);
        $this->assertTrue($days[0]['understaffedHours'][9]);
        $this->assertFalse($days[0]['understaffedHours'][10]);
        $this->assertFalse($days[0]['understaffedHours'][3]);
        $this->assertTrue($days[0]['lowHours'][23]);
        $this->assertTrue($days[0]['lowHours'][3]);
        $this->assertFalse($days[0]['lowHours'][12]);
        $this->assertTrue($days[1]['understaffed']);
    }

    public function test_timeline_shows_costs_only_with_payroll_view(): void
    {
        $this->shift();
        $viewer = $this->userWith(['shifts.view', 'payroll.view'], $this->store);

        $this->actingAs($this->manager);
        $this->timeline()->assertSeeHtml('data-test="period-totals"')->assertDontSee('€');

        $this->actingAs($viewer);
        $this->timeline()->assertSee('€');
    }

    public function test_timeline_is_not_available_for_other_stores(): void
    {
        $other = Store::factory()->create(['name' => 'Beta']);
        $this->actingAs($this->employee)->get(route('shifts.timeline'))->assertForbidden();
        $this->actingAs($this->manager);
        // Un magasin hors périmètre est ignoré : on retombe sur le premier magasin visible.
        Livewire::test('pages::shifts.timeline')->set('store_id', (string) $other->id)->assertDontSee('Beta');
    }

    public function test_quick_create_succeeds(): void
    {
        $this->actingAs($this->manager);

        $this->timeline()
            ->call('openQuickCreate', '2026-11-02', $this->employee->id, 543)
            ->assertSet('qc_start', '09:00')->assertSet('qc_end', '13:00')
            ->set('qc_end', '17:00')->call('quickCreate')->assertHasNoErrors();

        $shift = Shift::firstOrFail();
        $this->assertSame($this->employee->id, $shift->user_id);
        $this->assertSame('09:00', $shift->localStartsAt->format('H:i'));
        $this->assertSame('2026-11-02', $shift->shift_date->toDateString());
    }

    public function test_quick_create_warning_then_force(): void
    {
        $this->shift();
        $this->actingAs($this->manager);

        $page = $this->timeline()->call('openQuickCreate', '2026-11-02', $this->employee->id, 960)
            ->set('qc_start', '16:00')->set('qc_end', '17:00')->call('quickCreate');

        $page->assertSet('warnings', fn (array $w): bool => $w !== [])->assertSee('Overlaps another shift');
        $this->assertSame(1, Shift::count());

        $page->call('confirmWarnings');
        $this->assertSame(2, Shift::count());
        $page->assertSet('pending', []);
    }

    public function test_quick_create_blocking_error_is_displayed_and_nothing_is_created(): void
    {
        $this->actingAs($this->manager);

        $this->timeline()->call('openQuickCreate', '2026-11-02', $this->employee->id, 540)
            ->set('qc_end', '09:00')->call('quickCreate')->assertHasErrors();

        $this->assertSame(0, Shift::count());
    }

    public function test_quick_create_requires_the_create_permission(): void
    {
        $viewer = $this->userWith(['shifts.view', 'shifts.update'], $this->store);
        $this->actingAs($viewer);

        $this->timeline()->call('openQuickCreate', '2026-11-02', null, 540)->assertForbidden();
    }

    public function test_drag_and_drop_moves_the_hours_and_keeps_the_duration(): void
    {
        $shift = $this->shift('2026-11-02', '09:00', '17:00');
        $this->actingAs($this->manager);

        $this->timeline()->call('moveShift', $shift->id, '2026-11-02', 608, $this->employee->id);

        $shift->refresh();
        $this->assertSame('10:15', $shift->localStartsAt->format('H:i'));
        $this->assertSame('18:15', $shift->localEndsAt->format('H:i'));
        $this->assertSame($this->employee->id, $shift->user_id);
    }

    public function test_drag_and_drop_changes_the_employee_and_moves_to_the_open_row(): void
    {
        $colleague = $this->employeeOf($this->store, ['name' => 'Colin']);
        $shift = $this->shift();
        $this->actingAs($this->manager);

        $page = $this->timeline()->call('moveShift', $shift->id, '2026-11-02', 540, $colleague->id);
        $this->assertSame($colleague->id, $shift->refresh()->user_id);

        $page->call('moveShift', $shift->id, '2026-11-03', 540, null);
        $this->assertNull($shift->refresh()->user_id);
        $this->assertSame('2026-11-03', $shift->shift_date->toDateString());
    }

    public function test_drag_and_drop_warning_then_force(): void
    {
        $colleague = $this->employeeOf($this->store, ['name' => 'Colin']);
        $this->shift('2026-11-02', '09:00', '17:00', $colleague);
        $shift = $this->shift('2026-11-02', '18:00', '22:00');
        $this->actingAs($this->manager);

        $page = $this->timeline()->call('moveShift', $shift->id, '2026-11-02', 600, $colleague->id);

        $page->assertSet('warnings', fn (array $w): bool => $w !== []);
        $this->assertSame($this->employee->id, $shift->refresh()->user_id);

        $page->call('confirmWarnings');
        $this->assertSame($colleague->id, $shift->refresh()->user_id);
        $this->assertSame('10:00', $shift->localStartsAt->format('H:i'));
    }

    public function test_drag_and_drop_refuses_a_non_member(): void
    {
        $stranger = User::factory()->create();
        $shift = $this->shift();
        $this->actingAs($this->manager);

        $this->timeline()->call('moveShift', $shift->id, '2026-11-02', 540, $stranger->id);

        $this->assertSame($this->employee->id, $shift->refresh()->user_id);
    }

    public function test_drag_and_drop_requires_update_permission_and_the_displayed_store(): void
    {
        $shift = $this->shift();
        $viewer = $this->userWith(['shifts.view'], $this->store);

        $this->actingAs($viewer);
        $this->timeline()->call('moveShift', $shift->id, '2026-11-02', 600, $this->employee->id);
        $this->assertSame('09:00', $shift->refresh()->localStartsAt->format('H:i'));

        $other = Store::factory()->create();
        $foreign = $this->shift('2026-11-02', '09:00', '17:00', $this->employeeOf($other), $other);
        $this->actingAs($this->manager);
        $this->timeline()->call('moveShift', $foreign->id, '2026-11-02', 600, null);
        $this->assertSame('09:00', $foreign->refresh()->localStartsAt->format('H:i'));
    }

    public function test_timeline_bars_link_to_the_edit_form_and_expose_drag_data(): void
    {
        $shift = $this->shift();
        $this->actingAs($this->manager);

        $this->timeline()->assertSeeHtml('data-id="'.$shift->id.'"')->assertSeeHtml('data-minute="540"')
            ->assertSeeHtml(route('shifts.edit', $shift));
    }

    // --- Impression ---

    public function test_print_view_has_no_navigation(): void
    {
        $this->shift();

        $this->actingAs($this->employee)->get(route('shifts.timeline.print'))->assertForbidden();

        $this->actingAs($this->manager)
            ->get(route('shifts.timeline.print', ['store_id' => $this->store->id, 'start' => '2026-11-02', 'view' => 'day']))
            ->assertOk()->assertSee('@media print', false)->assertSee('Emma')->assertSee('09:00-17:00')
            ->assertDontSee('flux:sidebar', false)->assertDontSee('data-flux-sidebar', false);
    }

    // --- Vues employé ---

    public function test_employee_views_show_only_own_shifts(): void
    {
        $colleague = $this->employeeOf($this->store, ['name' => 'Colin']);
        $today = now()->toDateString();
        $this->shift($today, '09:00', '17:00');
        $this->shift($today, '10:00', '14:00', $colleague);
        $this->shift(now()->subDays(40)->toDateString());

        $this->actingAs($this->employee);
        foreach (['my-shifts.index', 'my-shifts.calendar', 'my-shifts.week'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('09:00')->assertDontSee('Colin')->assertDontSee('10:00 - 14:00');
        }
    }

    public function test_employee_index_splits_upcoming_and_past(): void
    {
        $this->shift(now()->subDay()->toDateString());
        $this->shift(now()->addDay()->toDateString(), '08:00', '12:00');
        $this->actingAs($this->employee);

        Livewire::test('pages::my-shifts.index')
            ->assertSeeInOrder(['Upcoming', '08:00', 'Past', '09:00']);
    }

    public function test_employee_week_starts_on_the_store_week_start_day(): void
    {
        $this->actingAs($this->employee);

        $page = Livewire::test('pages::my-shifts.week')->set('date', '2026-11-05');

        // week_start_day = 3 : la semaine du jeudi 5 novembre commence le mercredi 4.
        $page->assertSeeHtmlInOrder(['data-date="2026-11-04"', 'data-date="2026-11-10"']);
    }

    public function test_employee_day_shows_colleague_names_and_hours_without_costs(): void
    {
        $colleague = $this->employeeOf($this->store, ['name' => 'Colin']);
        $other = Store::factory()->create();
        $elsewhere = $this->employeeOf($other, ['name' => 'Zoe']);
        $this->shift('2026-11-02', '09:00', '17:00');
        $this->shift('2026-11-02', '10:00', '14:00', $colleague);
        $this->shift('2026-11-02', '10:00', '14:00', $elsewhere, $other);
        $this->actingAs($this->employee);

        $this->get(route('my-shifts.day', ['date' => '2026-11-02']))
            ->assertOk()->assertSee('Colin')->assertSee('10:00 - 14:00')->assertDontSee('Zoe')->assertDontSee('€');
    }

    public function test_employee_day_without_shift_shows_a_day_off(): void
    {
        $colleague = $this->employeeOf($this->store, ['name' => 'Colin']);
        $this->shift('2026-11-02', '10:00', '14:00', $colleague);
        $this->actingAs($this->employee);

        $this->get(route('my-shifts.day', ['date' => '2026-11-02']))->assertOk()->assertSee('Day off')->assertDontSee('Colin');
    }

    public function test_guests_are_redirected(): void
    {
        foreach (['my-shifts.index', 'shifts.index', 'shifts.timeline.print'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_sidebar_links(): void
    {
        $this->actingAs($this->manager)->get(route('dashboard'))
            ->assertSee(route('shifts.timeline'), false)->assertSee(route('my-shifts.index'), false);
        $this->actingAs($this->employee)->get(route('dashboard'))
            ->assertDontSee(route('shifts.timeline'), false)->assertSee(route('my-shifts.index'), false);
    }
}
