<?php

namespace Tests\Feature\Shifts;

use App\Authorization\Authorizer;
use App\Models\Availability;
use App\Models\RoleAssignment;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ShiftPagesTest extends TestCase
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
        $this->store = Store::factory()->create();
        $this->manager = $this->userWith(['shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete'], $this->store);
        $this->employee = $this->employeeOf($this->store);
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

    private function shift(string $date = '2026-11-02', string $start = '09:00', string $end = '17:00', ?User $user = null): Shift
    {
        return Shift::factory()->create([
            'store_id' => $this->store->id, 'user_id' => ($user ?? $this->employee)->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ]);
    }

    private function fill(Testable $page, array $values = []): Testable
    {
        foreach ($values + [
            'store_id' => (string) $this->store->id, 'user_id' => (string) $this->employee->id, 'shift_date' => '2026-11-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => '60',
        ] as $field => $value) {
            $page->set($field, $value);
        }

        return $page;
    }

    // --- Formulaire ---

    public function test_routes_are_protected_by_permissions(): void
    {
        $viewer = $this->userWith(['shifts.view'], $this->store);
        $shift = $this->shift();

        $this->actingAs($viewer)->get(route('shifts.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('shifts.edit', $shift))->assertForbidden();
        $this->actingAs($viewer)->get(route('shifts.conflicts'))->assertOk();
        $this->actingAs($this->employee)->get(route('shifts.conflicts'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('shifts.create'))->assertOk();
        $this->actingAs($this->manager)->get(route('shifts.edit', $shift))->assertOk();
    }

    public function test_manager_of_another_store_cannot_open_the_edit_form(): void
    {
        $shift = $this->shift();
        $stranger = $this->userWith(['shifts.view', 'shifts.update'], Store::factory()->create());

        $this->actingAs($stranger)->get(route('shifts.edit', $shift))->assertForbidden();
    }

    public function test_creates_a_shift_and_redirects_to_its_edit_form(): void
    {
        $this->actingAs($this->manager);

        $page = $this->fill(Livewire::test('pages::shifts.form'))->call('save');

        $page->assertHasNoErrors();
        $shift = Shift::firstOrFail();
        $page->assertRedirect(route('shifts.edit', $shift));
        $this->assertSame($this->employee->id, $shift->user_id);
        $this->assertSame($this->manager->id, $shift->created_by);
    }

    public function test_warnings_open_the_modal_then_save_anyway_forces(): void
    {
        $this->shift();
        $this->actingAs($this->manager);

        $page = $this->fill(Livewire::test('pages::shifts.form'), ['start_time' => '16:00', 'end_time' => '17:00'])->call('save');

        $page->assertSet('warnings', fn (array $warnings): bool => count($warnings) === 2)->assertSee('Overlaps another shift');
        $this->assertSame(1, Shift::count());

        $page->call('saveAnyway');

        $this->assertSame(2, Shift::count());
        $page->assertSet('warnings', []);
    }

    public function test_blocking_errors_are_shown_on_the_field(): void
    {
        $this->actingAs($this->manager);

        $this->fill(Livewire::test('pages::shifts.form'), ['start_time' => '09:00', 'end_time' => '09:00'])
            ->call('save')
            ->assertHasErrors(['end_time']);
    }

    public function test_rights_are_rechecked_on_save(): void
    {
        $shift = $this->shift();
        $this->actingAs($this->manager);
        $page = Livewire::test('pages::shifts.form', ['shift' => $shift])->set('notes', 'Changé');

        // Droit retiré entre l'ouverture du formulaire et l'enregistrement.
        RoleAssignment::query()->where('user_id', $this->manager->id)->delete();
        app(Authorizer::class)->forget();
        $page->call('save')->assertForbidden();

        $this->assertNull($shift->fresh()?->notes);
    }

    public function test_edits_a_shift(): void
    {
        $shift = $this->shift();
        $this->actingAs($this->manager);

        Livewire::test('pages::shifts.form', ['shift' => $shift])
            ->assertSet('start_time', '09:00')
            ->set('notes', 'Inventaire')
            ->set('end_time', '15:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Inventaire', $shift->fresh()?->notes);
        $this->assertSame(1, $shift->fresh()?->ical_sequence);
    }

    public function test_changing_store_resets_a_non_member_employee(): void
    {
        $other = Store::factory()->create();
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::shifts.form')
            ->set('store_id', (string) $this->store->id)
            ->set('user_id', (string) $this->employee->id)
            ->set('store_id', (string) $other->id)
            ->assertSet('user_id', '');
    }

    public function test_cost_preview_needs_payroll_view(): void
    {
        $type = ShiftType::factory()->create(['starts_at' => '08:00', 'ends_at' => '20:00', 'hourly_rate' => 10]);
        $type->stores()->attach($this->store);

        $this->actingAs($this->manager);
        $this->fill(Livewire::test('pages::shifts.form'))->assertDontSee('wage-preview-total');

        $accountant = $this->userWith(['shifts.view', 'shifts.create', 'payroll.view'], $this->store);
        $this->actingAs($accountant);
        $this->fill(Livewire::test('pages::shifts.form'))
            ->assertSeeHtml('data-test="wage-preview-total"')
            ->assertSee('70,00 €')
            ->assertDontSeeHtml('data-test="overrides-section"');
    }

    public function test_overrides_are_shown_to_payroll_generate_and_applied(): void
    {
        $this->actingAs($this->manager);
        $this->fill(Livewire::test('pages::shifts.form'))->assertDontSeeHtml('data-test="overrides-section"');

        $payroll = $this->userWith(['shifts.view', 'shifts.create', 'payroll.generate'], $this->store);
        $this->actingAs($payroll);
        $this->fill(Livewire::test('pages::shifts.form'), ['hourly_rate_override' => '25'])
            ->assertSeeHtml('data-test="overrides-section"')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('25.00', Shift::firstOrFail()->hourly_rate_override);
    }

    public function test_deletes_then_restores(): void
    {
        $shift = $this->shift();
        $this->actingAs($this->manager);

        $page = Livewire::test('pages::shifts.form', ['shift' => $shift])->call('delete');
        $this->assertSoftDeleted($shift);

        $page->assertSeeHtml('data-test="deleted-banner"')->call('restore');
        $this->assertNotSoftDeleted($shift);
    }

    public function test_a_replaced_shift_shows_it_cannot_be_restored(): void
    {
        $old = $this->shift();
        $new = $this->shift();
        $old->replaceWith($new);
        $this->actingAs($this->manager);

        $this->get(route('shifts.edit', $old->id))->assertOk()
            ->assertSee('cannot be restored')
            ->assertDontSeeHtml('data-test="restore-shift-button"');
    }

    // --- Page des conflits ---

    public function test_conflicts_page_lists_overlaps_short_and_long_shifts(): void
    {
        $this->shift('2026-11-02', '09:00', '14:00');
        $this->shift('2026-11-02', '12:00', '16:00');
        $short = $this->shift('2026-11-03', '09:00', '10:00');
        $long = $this->shift('2026-11-04', '06:00', '18:00');
        $this->actingAs($this->manager);

        $page = Livewire::test('pages::shifts.conflicts')->set('month', '2026-11');

        $page->assertSeeHtml('data-test="overlap-row"')->assertSee('120 min of overlap');
        $page->assertSeeHtmlInOrder(['data-test="too-short-section"', '#'.$short->id, 'data-test="too-long-section"', '#'.$long->id]);
    }

    public function test_conflicts_page_is_limited_to_viewable_stores_and_the_selected_month(): void
    {
        $this->shift('2026-11-02', '09:00', '14:00');
        $this->shift('2026-11-02', '12:00', '16:00');
        $this->actingAs($this->manager);

        Livewire::test('pages::shifts.conflicts')->set('month', '2026-12')->assertDontSeeHtml('data-test="overlap-row"');

        $stranger = $this->userWith(['shifts.view'], Store::factory()->create());
        $this->actingAs($stranger);
        Livewire::test('pages::shifts.conflicts')->set('month', '2026-11')->assertDontSeeHtml('data-test="overlap-row"');
    }

    public function test_keep_newest_resolves_the_conflict(): void
    {
        $older = $this->shift('2026-11-02', '09:00', '14:00');
        $newer = $this->shift('2026-11-02', '12:00', '16:00');
        $this->actingAs($this->manager);

        Livewire::test('pages::shifts.conflicts')
            ->set('month', '2026-11')
            ->call('keepNewer', $older->id, $newer->id)
            ->assertDontSeeHtml('data-test="overlap-row"');

        $this->assertSoftDeleted($older);
        $this->assertNotSoftDeleted($newer);
    }

    public function test_viewer_without_update_does_not_get_the_resolve_button_nor_the_action(): void
    {
        $older = $this->shift('2026-11-02', '09:00', '14:00');
        $newer = $this->shift('2026-11-02', '12:00', '16:00');
        $viewer = $this->userWith(['shifts.view'], $this->store);
        $this->actingAs($viewer);

        $page = Livewire::test('pages::shifts.conflicts')->set('month', '2026-11');
        $page->assertSeeHtml('data-test="overlap-row"')->assertDontSeeHtml('data-test="keep-newer-button"');

        $page->call('keepNewer', $older->id, $newer->id)->assertForbidden();
        $this->assertNotSoftDeleted($older);
    }

    public function test_unavailability_declared_after_the_fact_does_not_hide_existing_shifts(): void
    {
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 1]);
        $this->shift('2026-11-02', '09:00', '17:00');
        $this->actingAs($this->manager);

        Livewire::test('pages::shifts.conflicts')->set('month', '2026-11')->assertDontSeeHtml('data-test="overlap-row"');
    }
}
