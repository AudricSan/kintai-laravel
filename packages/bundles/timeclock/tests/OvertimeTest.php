<?php

namespace Kintai\Bundles\Timeclock\Tests;

use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Bundles\Timeclock\TimeclockActions;
use Kintai\Bundles\Timeclock\TimeclockExport;
use Kintai\Contracts\Exports\ExportScope;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Settings\Settings;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Heures supplémentaires du pointage : réglages (Owner), notifications dédoublonnées, affichage, confidentialité, export.
 */
class OvertimeTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.timezone' => 'Asia/Tokyo']);
        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
        $this->enableBundle('timeclock');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function configure(array $values = []): void
    {
        $settings = app(Settings::class);
        $values += ['enabled' => true, 'daily_hours' => 8, 'weekly_hours' => 40, 'week_start' => 1];

        $settings->set('timeclock.overtime_enabled', $values['enabled']);
        $settings->set('timeclock.overtime_daily_hours', $values['daily_hours']);
        $settings->set('timeclock.overtime_weekly_hours', $values['weekly_hours']);
        $settings->set('timeclock.overtime_week_start', $values['week_start']);
    }

    /** Heure locale de Tokyo -> UTC (le serveur pointe avec l'heure courante). */
    private function clock(User $user, ?Store $store, string $in, string $out): Timeclock
    {
        $actions = app(TimeclockActions::class);

        Carbon::setTestNow(Carbon::parse($in, 'Asia/Tokyo'));
        $entry = $actions->clockIn($user, $store?->id);
        Carbon::setTestNow(Carbon::parse($out, 'Asia/Tokyo'));

        return $actions->clockOut($user);
    }

    private function manager(Store $store): User
    {
        $user = User::factory()->create();
        $store->members()->attach($user);
        $user->assignRole($this->customRole(['timeclock.view']), $store);

        return $user;
    }

    private function entry(User $user, Store $store, string $in, ?string $out): Timeclock
    {
        $inUtc = Carbon::parse($in, 'Asia/Tokyo')->utc();
        $outUtc = $out === null ? null : Carbon::parse($out, 'Asia/Tokyo')->utc();

        return Timeclock::create([
            'user_id' => $user->id, 'store_id' => $store->id, 'shift_date' => Carbon::parse($in, 'Asia/Tokyo')->toDateString(),
            'clock_in_at' => $inUtc, 'clock_out_at' => $outUtc, 'duration_minutes' => $outUtc === null ? null : (int) $inUtc->diffInMinutes($outUtc),
        ]);
    }

    private function scope(): ExportScope
    {
        return new ExportScope($this->owner(), false, [$this->a->id], null, [], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    }

    // -- Réglages ------------------------------------------------------------------------------------------------

    public function test_the_feature_is_off_by_default_with_neutral_values(): void
    {
        $settings = app(Settings::class);

        $this->assertFalse($settings->bool('timeclock.overtime_enabled'));
        $this->assertSame(8, $settings->int('timeclock.overtime_daily_hours'));
        $this->assertSame(40, $settings->int('timeclock.overtime_weekly_hours'));
        $this->assertSame(1, $settings->int('timeclock.overtime_week_start'));
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('timeclock_overtime'));
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('timeclock_overtime_manager'));
    }

    public function test_nothing_changes_while_the_feature_is_off(): void
    {
        $employee = $this->employeeOf($this->a);
        $manager = $this->manager($this->a);

        $this->clock($employee, $this->a, '2026-11-10 08:00:00', '2026-11-10 22:00:00');

        $this->assertSame(0, $employee->notifications()->count());
        $this->assertSame(0, $manager->notifications()->count());
        $this->assertSame(0, DB::table('timeclock_overtime_alerts')->count());
        Livewire::actingAs($employee)->test('timeclock.my-clock')->assertDontSee('Overtime');
    }

    public function test_only_the_owner_opens_and_saves_the_settings(): void
    {
        $manager = $this->manager($this->a);
        $this->actingAs($manager)->get('/timeclock/settings')->assertForbidden();
        Livewire::actingAs($manager)->test('timeclock.settings')->assertForbidden();
        Livewire::actingAs($this->employeeOf($this->a))->test('timeclock.settings')->assertForbidden();

        Livewire::actingAs($this->owner())->test('timeclock.settings')
            ->set('enabled', true)->set('dailyHours', 7)->set('weeklyHours', 35)->set('weekStart', 0)->call('save')->assertHasNoErrors();

        $settings = app(Settings::class);
        $this->assertTrue($settings->bool('timeclock.overtime_enabled'));
        $this->assertSame([7, 35, 0], [$settings->int('timeclock.overtime_daily_hours'), $settings->int('timeclock.overtime_weekly_hours'), $settings->int('timeclock.overtime_week_start')]);
        $this->assertNotNull(ActivityEntry::where('action', 'timeclock.settings_updated')->first());
    }

    public function test_the_settings_are_strictly_validated(): void
    {
        $page = fn () => Livewire::actingAs($this->owner())->test('timeclock.settings');

        $page()->set('dailyHours', -1)->call('save')->assertHasErrors(['dailyHours']);
        $page()->set('dailyHours', 25)->call('save')->assertHasErrors(['dailyHours']);
        $page()->set('weeklyHours', 169)->call('save')->assertHasErrors(['weeklyHours']);
        $page()->set('weekStart', 7)->call('save')->assertHasErrors(['weekStart']);
        $page()->set('enabled', true)->set('dailyHours', 0)->set('weeklyHours', 0)->call('save')->assertHasErrors(['dailyHours']);

        $this->assertFalse(app(Settings::class)->bool('timeclock.overtime_enabled'));
        $this->assertSame(8, app(Settings::class)->int('timeclock.overtime_daily_hours'));

        // Une valeur hors bornes écrite directement est ramenée dans les bornes par les réglages de l'instance.
        app(Settings::class)->set('timeclock.overtime_daily_hours', 99);
        $this->assertSame(24, app(Settings::class)->int('timeclock.overtime_daily_hours'));
    }

    public function test_the_owner_sees_the_settings_link_but_not_the_managers(): void
    {
        $manager = $this->manager($this->a);

        Livewire::actingAs($manager)->test('timeclock.manage')->assertDontSeeHtml('data-test="settings-link"');
        Livewire::actingAs($this->owner())->test('timeclock.manage')->assertSeeHtml('data-test="settings-link"');
    }

    // -- Notifications -------------------------------------------------------------------------------------------

    public function test_reaching_the_daily_threshold_notifies_the_employee_and_the_store_managers_once(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $employee = $this->employeeOf($this->a);
        $manager = $this->manager($this->a);
        $otherStoreManager = $this->manager($this->b);
        $plainMember = $this->employeeOf($this->a);

        $this->clock($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 18:30:00');

        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame('timeclock_overtime', $employee->notifications()->first()->type);
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertSame('timeclock_overtime_manager', $manager->notifications()->first()->type);
        $this->assertSame(0, $otherStoreManager->notifications()->count());
        $this->assertSame(0, $plainMember->notifications()->count());

        // Un second dépassement le même jour ne prévient plus personne.
        $this->clock($employee, $this->a, '2026-11-10 19:00:00', '2026-11-10 20:00:00');
        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());

        // Le jour suivant est une autre période.
        $this->clock($employee, $this->a, '2026-11-11 09:00:00', '2026-11-11 18:30:00');
        $this->assertSame(2, $employee->notifications()->count());
        $this->assertSame(2, $manager->notifications()->count());
    }

    public function test_exactly_reaching_the_threshold_notifies_but_staying_under_does_not(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $employee = $this->employeeOf($this->a);
        $under = $this->employeeOf($this->a);

        $this->clock($under, $this->a, '2026-11-10 09:00:00', '2026-11-10 16:59:00');
        $this->clock($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 17:00:00');

        $this->assertSame(0, $under->notifications()->count());
        $this->assertSame(1, $employee->notifications()->count());
    }

    public function test_the_weekly_threshold_notifies_once_per_week(): void
    {
        $this->configure(['daily_hours' => 0]);
        $employee = $this->employeeOf($this->a);
        $manager = $this->manager($this->a);

        foreach (['10', '11', '12', '13'] as $day) {
            $this->clock($employee, $this->a, "2026-11-{$day} 09:00:00", "2026-11-{$day} 19:00:00");
        }
        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertStringContainsString('week', json_encode($employee->notifications()->first()->data));

        $this->clock($employee, $this->a, '2026-11-14 09:00:00', '2026-11-14 12:00:00');
        $this->assertSame(1, $employee->notifications()->count());

        // La semaine suivante recommence.
        foreach (['16', '17', '18', '19'] as $day) {
            $this->clock($employee, $this->a, "2026-11-{$day} 09:00:00", "2026-11-{$day} 19:00:00");
        }
        $this->assertSame(2, $employee->notifications()->count());
        $this->assertSame(2, DB::table('timeclock_overtime_alerts')->where('kind', 'weekly')->count());
    }

    public function test_a_manager_who_is_also_the_employee_is_notified_only_as_employee(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $manager = $this->manager($this->a);

        $this->clock($manager, $this->a, '2026-11-10 09:00:00', '2026-11-10 19:00:00');

        $this->assertSame(['timeclock_overtime'], $manager->notifications()->pluck('type')->all());
    }

    public function test_a_correction_that_crosses_the_threshold_notifies_too(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $employee = $this->employeeOf($this->a);
        $manager = $this->manager($this->a);
        $updater = User::factory()->create();
        $this->a->members()->attach($updater);
        $updater->assignRole($this->customRole(['timeclock.view', 'timeclock.update']), $this->a);
        $entry = $this->clock($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 12:00:00');
        $this->assertSame(0, $employee->notifications()->count());

        app(TimeclockActions::class)->update(
            $updater, $entry, Carbon::parse('2026-11-10 09:00:00', 'Asia/Tokyo')->utc(), Carbon::parse('2026-11-10 20:00:00', 'Asia/Tokyo')->utc(), null,
        );

        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());
    }

    public function test_hours_in_several_stores_are_added_up(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $employee = $this->employeeOf($this->a);
        $this->b->members()->attach($employee);

        $this->clock($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 14:00:00');
        $this->assertSame(0, $employee->notifications()->count());
        $this->clock($employee, $this->b, '2026-11-10 15:00:00', '2026-11-10 20:00:00');

        $this->assertSame(1, $employee->notifications()->count());
    }

    // -- Affichage et confidentialité ----------------------------------------------------------------------------

    public function test_the_employee_sees_their_own_overtime(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $employee = $this->employeeOf($this->a);
        $this->clock($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 19:00:00');
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Asia/Tokyo'));

        Livewire::actingAs($employee)->test('timeclock.my-clock')
            ->assertSeeHtml('data-test="week-overtime"')->assertSee('Overtime: 2 h 0 min')->assertSeeHtml('data-test="overtime-badge"');
    }

    public function test_an_employee_never_sees_the_overtime_of_a_colleague(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $worker = $this->employeeOf($this->a);
        $colleague = $this->employeeOf($this->a);
        $this->clock($worker, $this->a, '2026-11-10 09:00:00', '2026-11-10 19:00:00');
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Asia/Tokyo'));

        Livewire::actingAs($colleague)->test('timeclock.my-clock')->assertSee('Overtime: 0 h 0 min')->assertDontSeeHtml('data-test="overtime-badge"');
        $this->actingAs($colleague)->get('/timeclock/manage')->assertForbidden();
    }

    public function test_managers_see_the_overtime_of_their_stores_only(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $manager = $this->manager($this->a);
        $inStore = $this->employeeOf($this->a, ['name' => 'Inside Worker']);
        $elsewhere = $this->employeeOf($this->b, ['name' => 'Outside Worker']);
        $this->clock($inStore, $this->a, '2026-11-10 09:00:00', '2026-11-10 19:00:00');
        $this->clock($elsewhere, $this->b, '2026-11-10 09:00:00', '2026-11-10 20:00:00');

        Livewire::actingAs($manager)->test('timeclock.manage')
            ->assertSeeHtml('data-test="overtime-badge"')->assertSee('Overtime: 120 min')
            ->assertSee('Inside Worker')->assertDontSee('Outside Worker')->assertDontSee('Overtime: 180 min');
    }

    // -- Export --------------------------------------------------------------------------------------------------

    public function test_the_export_carries_the_overtime_of_each_entry(): void
    {
        $this->configure(['weekly_hours' => 0]);
        $employee = $this->employeeOf($this->a);
        $this->clock($employee, $this->a, '2026-11-10 09:00:00', '2026-11-10 19:00:00');
        $this->clock($employee, $this->a, '2026-11-11 09:00:00', '2026-11-11 12:00:00');

        $rows = iterator_to_array((new TimeclockExport)->rows($this->scope()), false);

        $this->assertSame([120, 0], array_column($rows, 'overtime_minutes'));

        $this->configure(['enabled' => false]);
        $rows = iterator_to_array((new TimeclockExport)->rows($this->scope()), false);
        $this->assertSame([null, null], array_column($rows, 'overtime_minutes'));
    }
}
