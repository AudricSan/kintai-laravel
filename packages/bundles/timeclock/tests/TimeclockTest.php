<?php

namespace Kintai\Bundles\Timeclock\Tests;

use App\Actions\AnonymizeUser;
use App\Actions\Bundles\EnableBundle;
use App\Bundles\BundleManager;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Bundles\Timeclock\TimeclockServiceProvider;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Exports\ExportDatasetRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Settings\Settings;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Pointage : entrée et sortie, rattachement au shift, retard, correction et suppression par les gérants, portée magasin,
 * fonctionnalité de magasin, export, widgets, bundle désactivé.
 */
class TimeclockTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function managerWith(array $permissions, Store $store): User
    {
        $user = User::factory()->create();
        $store->members()->attach($user);
        $user->assignRole($this->customRole($permissions), $store);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(User $user, Store $store, string $in = '2026-11-10 09:00:00', ?string $out = '2026-11-10 17:30:00', array $attributes = []): Timeclock
    {
        return Timeclock::create([
            'user_id' => $user->id, 'store_id' => $store->id, 'shift_date' => substr($in, 0, 10),
            'clock_in_at' => $in, 'clock_out_at' => $out, 'duration_minutes' => $out === null ? null : 510, ...$attributes,
        ]);
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_when_enabled(): void
    {
        $this->enableBundle('timeclock');

        foreach (['timeclock.view', 'timeclock.update', 'timeclock.delete'] as $permission) {
            $this->assertTrue(app(PermissionRegistry::class)->has($permission));
        }
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('timeclock'));
        $this->assertTrue(app(NavigationRegistry::class)->has('timeclock'));
        $this->assertTrue(app(NavigationRegistry::class)->has('timeclock-manage'));
        $this->assertNotNull(app(ExportDatasetRegistry::class)->find('timeclock'));
        $this->assertTrue(app(ActivityActionRegistry::class)->has('timeclock.clock_in'));
        $this->assertSame(5, app(Settings::class)->int('timeclock.late_threshold_minutes'));
    }

    public function test_a_disabled_bundle_is_absent_everywhere_but_keeps_its_table(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('timeclock.view'));
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('timeclock'));
        $this->assertFalse(app(NavigationRegistry::class)->has('timeclock'));
        $this->assertNull(app(ExportDatasetRegistry::class)->find('timeclock'));
        $this->assertFalse(app(Settings::class)->has('timeclock.late_threshold_minutes'));

        $this->actingAs($this->employeeOf($this->a))->get('/timeclock')->assertNotFound();
        $this->actingAs($this->owner())->get('/timeclock/manage')->assertNotFound();
        $this->assertTrue(Schema::hasTable('timeclocks'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enableBundle('timeclock');

        $this->get('/timeclock')->assertRedirect(route('login'));
    }

    // -- Pointer -------------------------------------------------------------------------------------------------

    public function test_an_employee_clocks_in_and_out_without_any_permission(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        Carbon::setTestNow('2026-11-10 09:00:00');

        $page = Livewire::actingAs($employee)->test('timeclock.my-clock')
            ->assertSee('Not clocked in yet')->call('clockIn')->assertHasNoErrors()->assertSee('On duty');

        $entry = Timeclock::firstOrFail();
        $this->assertSame([$employee->id, $this->a->id, '2026-11-10'], [$entry->user_id, $entry->store_id, $entry->shift_date->toDateString()]);
        $this->assertTrue($entry->isActive());
        $this->assertNotNull(ActivityEntry::where('action', 'timeclock.clock_in')->where('actor_id', $employee->id)->first());

        Carbon::setTestNow('2026-11-10 17:30:00');
        $page->call('clockOut')->assertHasNoErrors()->assertSee('Not clocked in yet');

        $entry->refresh();
        $this->assertFalse($entry->isActive());
        $this->assertSame(510, $entry->duration_minutes);
        $this->assertNotNull(ActivityEntry::where('action', 'timeclock.clock_out')->first());
    }

    public function test_a_second_clock_in_is_refused_and_a_clock_out_needs_a_running_clock_in(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);

        Livewire::actingAs($employee)->test('timeclock.my-clock')->call('clockOut')->assertHasErrors(['clock']);

        Livewire::actingAs($employee)->test('timeclock.my-clock')->call('clockIn')->call('clockIn')->assertHasErrors(['clock']);
        $this->assertSame(1, Timeclock::count());
    }

    public function test_the_employee_chooses_among_their_stores_and_cannot_clock_in_elsewhere(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        $this->b->members()->attach($employee);

        Livewire::actingAs($employee)->test('timeclock.my-clock')->set('storeId', (string) $this->b->id)->call('clockIn');
        $this->assertSame($this->b->id, Timeclock::firstOrFail()->store_id);
        Timeclock::query()->delete();

        $stranger = $this->employeeOf($this->a);
        Livewire::actingAs($stranger)->test('timeclock.my-clock')->set('storeId', (string) $this->b->id)->call('clockIn')->assertHasErrors(['store_id']);
        $this->assertSame(0, Timeclock::count());
    }

    public function test_a_store_without_the_feature_or_no_store_means_no_page(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'timeclock', 'enabled' => false]);

        $this->actingAs($employee)->get('/timeclock')->assertNotFound();
        $this->actingAs($employee)->get(route('dashboard'))->assertDontSee(route('timeclock.index'), false);
        $this->actingAs(User::factory()->create())->get('/timeclock')->assertNotFound();
    }

    public function test_the_clock_in_is_tied_to_the_shift_of_the_day_and_late_beyond_the_threshold_is_flagged(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        $shift = Shift::factory()->for($this->a)->create(['user_id' => $employee->id, 'shift_date' => '2026-11-10', 'starts_at' => Shift::localTime('2026-11-10', '09:00'), 'ends_at' => Shift::localTime('2026-11-10', '17:00')]);
        Shift::factory()->for($this->a)->create(['user_id' => $employee->id, 'shift_date' => '2026-11-11']);
        $started = $shift->starts_at->copy();

        // 9 minutes après le début du shift.
        Carbon::setTestNow($started->copy()->addMinutes(9));
        Livewire::actingAs($employee)->test('timeclock.my-clock')->call('clockIn')->assertSee('Late: 9 min');

        $entry = Timeclock::firstOrFail();
        $this->assertSame($shift->id, $entry->shift_id);
        $this->assertSame(9, $entry->lateMinutes(5));
        $this->assertNull($entry->lateMinutes(10), 'Within the threshold, no lateness.');
        $this->assertNull($entry->lateMinutes(9), 'Exactly at the threshold, no lateness.');
    }

    public function test_without_a_shift_there_is_no_lateness(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        Carbon::setTestNow('2026-11-10 14:00:00');

        Livewire::actingAs($employee)->test('timeclock.my-clock')->call('clockIn');

        $this->assertNull(Timeclock::firstOrFail()->shift_id);
        $this->assertNull(Timeclock::firstOrFail()->lateMinutes(5));
    }

    public function test_the_page_shows_the_week_and_its_total(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        $other = $this->employeeOf($this->a);
        Carbon::setTestNow('2026-11-11 12:00:00'); // mercredi
        $this->entry($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 17:30:00');
        $this->entry($employee, $this->a, '2026-11-09 09:00:00', '2026-11-09 10:00:00', ['duration_minutes' => 60]);
        $this->entry($employee, $this->a, '2026-11-02 09:00:00', '2026-11-02 18:00:00', ['duration_minutes' => 540]);
        $this->entry($other, $this->a, '2026-11-10 08:00:00', '2026-11-10 08:30:00', ['duration_minutes' => 30]);

        Livewire::actingAs($employee)->test('timeclock.my-clock')
            ->assertSee('Total: 9 h 30 min')->assertSee('2026-11-10')->assertSee('2026-11-09')->assertDontSee('2026-11-02')
            ->assertDontSee('08:00');
    }

    // -- Gérants -------------------------------------------------------------------------------------------------

    public function test_the_manage_page_needs_the_view_permission_and_lists_only_the_stores_of_the_manager(): void
    {
        $this->enableBundle('timeclock');
        $this->actingAs($this->employeeOf($this->a))->get('/timeclock/manage')->assertForbidden();

        $this->entry($this->employeeOf($this->a), $this->a, '2026-11-10 09:00:00', '2026-11-10 17:00:00', ['notes' => 'In Alpha']);
        $this->entry($this->employeeOf($this->b), $this->b, '2026-11-10 09:00:00', '2026-11-10 17:00:00', ['notes' => 'In Beta']);
        $manager = $this->managerWith(['timeclock.view'], $this->a);

        Livewire::actingAs($manager)->test('timeclock.manage')->assertSee('In Alpha')->assertDontSee('In Beta');
        Livewire::actingAs($this->owner())->test('timeclock.manage')->assertSee('In Alpha')->assertSee('In Beta');
    }

    public function test_the_manage_page_filters_by_day_and_store(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        $this->b->members()->attach($employee);
        $this->entry($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 17:00:00', ['notes' => 'Monday note']);
        $this->entry($employee, $this->b, '2026-11-11 09:00:00', '2026-11-11 17:00:00', ['notes' => 'Tuesday note']);

        Livewire::actingAs($this->owner())->test('timeclock.manage')
            ->set('date', '2026-11-10')->assertSee('Monday note')->assertDontSee('Tuesday note')
            ->set('date', '')->set('store', (string) $this->b->id)->assertSee('Tuesday note')->assertDontSee('Monday note');
    }

    public function test_a_manager_corrects_the_hours_of_an_entry(): void
    {
        $this->enableBundle('timeclock');
        $entry = $this->entry($this->employeeOf($this->a), $this->a);
        $manager = $this->managerWith(['timeclock.view', 'timeclock.update'], $this->a);

        Livewire::actingAs($manager)->test('timeclock.manage')
            ->call('startEditing', $entry->id)->assertSet('editIn', '2026-11-10T09:00')
            ->set('editIn', '2026-11-10T08:30')->set('editOut', '2026-11-10T12:30')->set('editNotes', 'Forgot to clock')
            ->call('saveEditing')->assertHasNoErrors();

        $fresh = $entry->fresh();
        $this->assertSame([240, 'Forgot to clock'], [$fresh->duration_minutes, $fresh->notes]);
        $this->assertSame('08:30', $fresh->localIn()->format('H:i'));
        $this->assertNotNull(ActivityEntry::where('action', 'timeclock.edited')->first());
    }

    public function test_an_edit_cannot_end_before_it_starts_nor_double_a_running_clock_in(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        $entry = $this->entry($employee, $this->a);
        $this->entry($employee, $this->a, '2026-11-12 09:00:00', null);
        $manager = $this->managerWith(['timeclock.view', 'timeclock.update'], $this->a);

        Livewire::actingAs($manager)->test('timeclock.manage')
            ->call('startEditing', $entry->id)->set('editOut', '2026-11-10T07:00')->call('saveEditing')->assertHasErrors(['clock_out_at']);
        Livewire::actingAs($manager)->test('timeclock.manage')
            ->call('startEditing', $entry->id)->set('editOut', '')->call('saveEditing')->assertHasErrors(['clock_out_at']);

        $this->assertSame(510, $entry->fresh()->duration_minutes);
    }

    public function test_without_the_update_permission_or_outside_the_scope_nothing_can_be_edited_or_deleted(): void
    {
        $this->enableBundle('timeclock');
        $entry = $this->entry($this->employeeOf($this->a), $this->a);
        $elsewhere = $this->entry($this->employeeOf($this->b), $this->b);
        $viewer = $this->managerWith(['timeclock.view'], $this->a);

        Livewire::actingAs($viewer)->test('timeclock.manage')->call('startEditing', $entry->id)->assertForbidden();
        Livewire::actingAs($viewer)->test('timeclock.manage')->call('confirmDeletion', $entry->id)->assertForbidden();
        Livewire::actingAs($viewer)->test('timeclock.manage')->call('startEditing', $elsewhere->id)->assertNotFound();
        $this->assertDatabaseHas('timeclocks', ['id' => $entry->id]);
        $this->assertDatabaseHas('timeclocks', ['id' => $elsewhere->id]);
    }

    public function test_deleting_needs_a_confirmation_and_the_delete_permission(): void
    {
        $this->enableBundle('timeclock');
        $entry = $this->entry($this->employeeOf($this->a), $this->a);
        $deleter = $this->managerWith(['timeclock.view', 'timeclock.delete'], $this->a);

        Livewire::actingAs($deleter)->test('timeclock.manage')
            ->call('confirmDeletion', $entry->id)->assertSet('deletingId', $entry->id)->call('delete');

        $this->assertDatabaseMissing('timeclocks', ['id' => $entry->id]);
        $this->assertNotNull(ActivityEntry::where('action', 'timeclock.deleted')->first());
    }

    // -- Export, widgets, données --------------------------------------------------------------------------------

    public function test_managers_export_their_stores_and_employees_their_own_entries(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a, ['name' => 'Eve Employee']);
        $colleague = $this->employeeOf($this->a, ['name' => 'Carl Colleague']);
        $this->entry($employee, $this->a);
        $this->entry($colleague, $this->a, '2026-11-10 10:00:00', '2026-11-10 11:00:00', ['duration_minutes' => 60]);
        $this->entry($this->employeeOf($this->b, ['name' => 'Bob Elsewhere']), $this->b);
        $manager = $this->managerWith(['timeclock.view'], $this->a);
        $query = ['dataset' => 'timeclock', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'];

        $csv = $this->actingAs($manager)->get(route('exports.download', $query + ['target' => 'stores', 'stores' => [$this->a->id]]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Eve Employee', $csv);
        $this->assertStringContainsString('Carl Colleague', $csv);
        $this->assertStringNotContainsString('Bob Elsewhere', $csv);
        $this->assertStringContainsString('09:00', $csv);

        $mine = $this->actingAs($employee)->get(route('exports.download', $query + ['target' => 'me']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Eve Employee', $mine);
        $this->assertStringNotContainsString('Carl Colleague', $mine);
    }

    public function test_the_widgets_show_the_active_clock_ins_and_the_clock_buttons(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a, ['name' => 'Eve Employee']);
        $this->entry($employee, $this->a, now()->toDateTimeString(), null);
        $this->entry($this->employeeOf($this->b, ['name' => 'Bob Elsewhere']), $this->b, now()->toDateTimeString(), null);
        $this->entry($this->employeeOf($this->a, ['name' => 'Done Donna']), $this->a);
        $manager = $this->managerWith(['timeclock.view'], $this->a);

        Livewire::actingAs($manager)->test('timeclock.manager-widget')
            ->assertSee('Eve Employee')->assertDontSee('Bob Elsewhere')->assertDontSee('Done Donna');

        Livewire::actingAs($employee)->test('timeclock.employee-widget')->assertSee('Clocked in since')->assertSee('Clock out');
        Livewire::actingAs($this->employeeOf($this->a))->test('timeclock.employee-widget')->assertSee('Not clocked in yet')->call('clockIn')->assertSee('Clocked in since');
    }

    public function test_the_first_activation_gives_the_manager_role_every_right_and_employees_none(): void
    {
        app(EnableBundle::class)->handle($this->owner(), 'timeclock');
        $this->app->register(TimeclockServiceProvider::class, true);
        $manager = $this->managerOf($this->a);
        $employee = $this->employeeOf($this->a);

        foreach (['timeclock.view', 'timeclock.update', 'timeclock.delete'] as $permission) {
            $this->assertTrue(Gate::forUser($manager)->allows($permission, $this->a), $permission);
            $this->assertFalse(Gate::forUser($employee)->allows($permission, $this->a), $permission);
        }
    }

    public function test_anonymising_keeps_the_hours_but_erases_ips_and_notes(): void
    {
        $this->enableBundle('timeclock');
        $employee = $this->employeeOf($this->a);
        $entry = $this->entry($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 17:30:00', ['clock_in_ip' => '10.0.0.1', 'notes' => 'Private']);

        app(AnonymizeUser::class)->handle($employee);

        $fresh = $entry->fresh();
        $this->assertSame([null, null], [$fresh->clock_in_ip, $fresh->notes]);
        $this->assertSame(510, $fresh->duration_minutes);
    }

    public function test_disabling_the_bundle_keeps_the_data(): void
    {
        $this->enableBundle('timeclock');
        $entry = $this->entry($this->employeeOf($this->a), $this->a);

        app(BundleManager::class)->disable('timeclock');

        $this->assertDatabaseHas('timeclocks', ['id' => $entry->id]);
    }
}
