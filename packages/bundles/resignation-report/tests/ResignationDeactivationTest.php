<?php

namespace Kintai\Bundles\ResignationReport\Tests;

use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Kintai\Bundles\ResignationReport\ResignationDeactivation;
use Kintai\Bundles\ResignationReport\ResignationReportActions;
use Kintai\Contracts\Employees\EmployeeActionRegistry;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Désactivation du compte à la date de départ (démission ou licenciement) : application une seule fois, réactivation par
 * report de date, suppression ou action manuelle, tâche planifiée, garde-fous du Core, droit sur l'employé, journal.
 */
class ResignationDeactivationTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private const TODAY = '2026-11-06';

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
        $this->enableBundle('resignation-report');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('resignation-report') ?? throw new LogicException);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function report(User $employee, array $attributes = []): ResignationReport
    {
        return ResignationReport::factory()->create(['store_id' => $this->store->id, 'user_id' => $employee->id, ...$attributes]);
    }

    private function service(): ResignationDeactivation
    {
        return app(ResignationDeactivation::class);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function holder(array $permissions): User
    {
        $holder = User::factory()->create();
        $this->store->members()->attach($holder);
        $holder->assignRole($this->customRole($permissions), $this->store);

        return $holder;
    }

    // -- sync ---------------------------------------------------------------------------------------------------------

    public function test_sync_deactivates_when_the_date_is_reached_or_absent_and_not_before(): void
    {
        foreach ([['2026-11-06', true], ['2026-01-01', true], [null, true], ['2026-11-07', false]] as [$date, $deactivated]) {
            $employee = $this->employeeOf($this->store);
            $report = $this->report($employee, ['resignation_date' => $date]);

            $this->service()->sync($report, null, self::TODAY);

            $this->assertSame($deactivated, ! $employee->fresh()->is_active, 'date '.var_export($date, true));
            $this->assertSame($deactivated, $report->fresh()->deactivated_at !== null, 'date '.var_export($date, true));
        }
    }

    public function test_sync_ignores_a_report_without_account(): void
    {
        $report = ResignationReport::factory()->create(['store_id' => $this->store->id, 'user_id' => null, 'resignation_date' => '2020-01-01']);

        $this->service()->sync($report, null, self::TODAY);

        $this->assertNull($report->fresh()->deactivated_at);
    }

    public function test_sync_is_idempotent_and_a_manual_reactivation_stays(): void
    {
        $employee = $this->employeeOf($this->store);
        $report = $this->report($employee, ['resignation_date' => '2026-01-01']);

        $this->service()->sync($report, null, self::TODAY);
        $this->assertNotNull($report->fresh()->deactivated_at);
        $this->assertSame(1, ActivityEntry::where('action', 'resignation_report.deactivated')->count());

        $this->service()->sync($report->fresh(), null, self::TODAY);
        $this->assertSame(1, ActivityEntry::where('action', 'resignation_report.deactivated')->count());

        User::whereKey($employee->id)->update(['is_active' => true]);
        $this->service()->sync($report->fresh(), null, self::TODAY);
        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_postponing_the_date_reactivates_the_account(): void
    {
        $employee = $this->employeeOf($this->store);
        $report = $this->report($employee, ['resignation_date' => '2026-01-01']);
        $this->service()->sync($report, null, self::TODAY);
        $this->assertFalse($employee->fresh()->is_active);

        $report->resignation_date = '2027-01-01';
        $this->service()->sync($report, null, self::TODAY);

        $this->assertTrue($employee->fresh()->is_active);
        $this->assertNull($report->fresh()->deactivated_at);
        $this->assertSame(1, ActivityEntry::where('action', 'resignation_report.reactivated')->count());
    }

    // -- Saisie manuelle ------------------------------------------------------------------------------------------------

    public function test_saving_a_due_report_deactivates_the_account_and_a_later_date_reactivates_it(): void
    {
        $manager = $this->managerOf($this->store);
        $employee = $this->employeeOf($this->store);
        $employee->createToken('mobile');

        Livewire::actingAs($manager)->test('resignation-report.form')
            ->set('userId', (string) $employee->id)->set('values.resignation_date', '2000-01-01')->call('save')->assertHasNoErrors();

        $report = ResignationReport::firstOrFail();
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertNotNull($report->deactivated_at);
        $this->assertSame(0, $employee->tokens()->count());

        Livewire::actingAs($manager)->test('resignation-report.form', ['report' => $report->id])
            ->set('values.resignation_date', now()->addYear()->toDateString())->call('save')->assertHasNoErrors();

        $this->assertTrue($employee->fresh()->is_active);
        $this->assertNull($report->fresh()->deactivated_at);
    }

    public function test_a_future_report_leaves_the_account_alone(): void
    {
        $manager = $this->managerOf($this->store);
        $employee = $this->employeeOf($this->store);

        Livewire::actingAs($manager)->test('resignation-report.form')
            ->set('userId', (string) $employee->id)->set('values.resignation_date', now()->addYear()->toDateString())->call('save')->assertHasNoErrors();

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_changing_the_linked_employee_gives_the_previous_account_back(): void
    {
        $manager = $this->managerOf($this->store);
        $first = $this->employeeOf($this->store);
        $second = $this->employeeOf($this->store);
        $report = $this->report($first, ['resignation_date' => '2000-01-01']);
        $this->service()->sync($report, null, self::TODAY);
        $this->assertFalse($first->fresh()->is_active);

        Livewire::actingAs($manager)->test('resignation-report.form', ['report' => $report->id])
            ->set('userId', (string) $second->id)->set('values.resignation_date', now()->addYear()->toDateString())->call('save')->assertHasNoErrors();

        $this->assertTrue($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertNull($report->fresh()->deactivated_at);
    }

    public function test_deleting_an_applied_report_reactivates_the_account(): void
    {
        $manager = $this->managerOf($this->store);
        $employee = $this->employeeOf($this->store);
        $report = $this->report($employee, ['resignation_date' => '2000-01-01']);
        $this->service()->sync($report, null, self::TODAY);

        app(ResignationReportActions::class)->delete($manager, $report->fresh());

        $this->assertTrue($employee->fresh()->is_active);
        $this->assertSame(0, ResignationReport::count());
    }

    public function test_the_reactivate_action_makes_the_account_active_for_good(): void
    {
        $manager = $this->managerOf($this->store);
        $employee = $this->employeeOf($this->store);
        $report = $this->report($employee, ['resignation_date' => '2000-01-01']);
        $this->service()->sync($report, null, self::TODAY);

        Livewire::actingAs($manager)->test('resignation-report.show', ['report' => $report->id])
            ->assertSeeHtml('data-test="reactivate-account-button"')
            ->call('reactivateAccount');

        $this->assertTrue($employee->fresh()->is_active);
        $this->assertNotNull($report->fresh()->deactivated_at);

        // Le rapport reste appliqué : la tâche planifiée ne désactive pas de nouveau.
        Artisan::call('kintai:resignation-deactivate');
        $this->assertTrue($employee->fresh()->is_active);

        Livewire::actingAs($manager)->test('resignation-report.show', ['report' => $report->id])->assertDontSeeHtml('data-test="reactivate-account-button"');
    }

    public function test_the_reactivate_action_needs_update_and_the_right_on_the_employee(): void
    {
        $employee = $this->employeeOf($this->store);
        $report = $this->report($employee, ['resignation_date' => '2000-01-01']);
        $this->service()->sync($report, null, self::TODAY);

        foreach ([['resignation_reports.view'], ['resignation_reports.view', 'resignation_reports.update']] as $permissions) {
            try {
                app(ResignationReportActions::class)->reactivateAccount($this->holder($permissions), $report->fresh());
                $this->fail('The reactivation must be refused.');
            } catch (AuthorizationException) {
                $this->assertFalse($employee->fresh()->is_active);
            }
        }
    }

    // -- Droit sur l'employé et garde-fous ------------------------------------------------------------------------------

    public function test_linking_a_report_to_an_account_needs_the_right_to_edit_that_employee(): void
    {
        $employee = $this->employeeOf($this->store);
        $holder = $this->holder(['resignation_reports.view', 'resignation_reports.create', 'resignation_reports.update']);

        Livewire::actingAs($holder)->test('resignation-report.form')
            ->set('userId', (string) $employee->id)->set('values.resignation_date', '2000-01-01')->call('save')->assertForbidden();

        $this->assertSame(0, ResignationReport::count());
        $this->assertTrue($employee->fresh()->is_active);

        // Le même rapport sans compte lié reste permis.
        Livewire::actingAs($holder)->test('resignation-report.form')->set('values.resignation_date', '2000-01-01')->call('save')->assertHasNoErrors();
        $this->assertSame(1, ResignationReport::count());

        // Modifier un rapport déjà lié exige aussi ce droit.
        $linked = $this->report($employee, ['resignation_date' => '2030-01-01']);
        Livewire::actingAs($holder)->test('resignation-report.form', ['report' => $linked->id])->set('values.reason', 'x')->call('save')->assertForbidden();
    }

    public function test_the_right_on_employees_is_scoped_to_the_store(): void
    {
        $other = Store::factory()->create();
        $employee = $this->employeeOf($this->store);
        $holder = $this->holder(['resignation_reports.view', 'resignation_reports.create']);
        $other->members()->attach($holder);
        $holder->assignRole($this->customRole(['employees.update']), $other);

        Livewire::actingAs($holder)->test('resignation-report.form')
            ->set('userId', (string) $employee->id)->call('save')->assertForbidden();
        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_a_manual_save_that_would_deactivate_oneself_is_a_validation_error_and_saves_nothing(): void
    {
        $manager = $this->managerOf($this->store);
        $this->store->members()->syncWithoutDetaching([$manager->id]);

        Livewire::actingAs($manager)->test('resignation-report.form')
            ->set('userId', (string) $manager->id)->set('values.resignation_date', '2000-01-01')->call('save')->assertHasErrors('userId');

        $this->assertSame(0, ResignationReport::count());
        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_the_last_owner_cannot_be_deactivated_manually(): void
    {
        $owner = $this->owner();
        $this->store->members()->attach($owner);
        $manager = $this->managerOf($this->store);

        // Un gérant n'agit pas sur un Owner.
        Livewire::actingAs($manager)->test('resignation-report.form')
            ->set('userId', (string) $owner->id)->set('values.resignation_date', '2000-01-01')->call('save')->assertForbidden();

        // Un Owner qui vise le dernier Owner (lui-même) est refusé par le Core : erreur de validation, rien d'enregistré.
        Livewire::actingAs($owner)->test('resignation-report.form')
            ->set('userId', (string) $owner->id)->set('values.resignation_date', '2000-01-01')->call('save')->assertHasErrors('userId');

        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame(0, ResignationReport::count());
    }

    // -- Tâche planifiée --------------------------------------------------------------------------------------------------

    public function test_the_command_applies_due_reports_once_and_skips_the_future_ones(): void
    {
        $due = $this->employeeOf($this->store);
        $undated = $this->employeeOf($this->store);
        $future = $this->employeeOf($this->store);
        $dueReport = $this->report($due, ['resignation_date' => '2000-01-01']);
        $this->report($undated, ['resignation_date' => null]);
        $this->report($future, ['resignation_date' => now()->addYear()->toDateString()]);
        ResignationReport::factory()->create(['store_id' => $this->store->id, 'user_id' => null, 'resignation_date' => '2000-01-01']);

        $this->assertSame(0, Artisan::call('kintai:resignation-deactivate'));

        $this->assertFalse($due->fresh()->is_active);
        $this->assertFalse($undated->fresh()->is_active);
        $this->assertTrue($future->fresh()->is_active);
        $this->assertNotNull($dueReport->fresh()->deactivated_at);

        Artisan::call('kintai:resignation-deactivate');
        $this->assertSame(2, ActivityEntry::where('action', 'resignation_report.deactivated')->count());
    }

    public function test_the_command_skips_and_logs_a_refusal_of_the_core_without_failing(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeOf($this->store);
        $ownerReport = $this->report($owner, ['resignation_date' => '2000-01-01']);
        $this->report($employee, ['resignation_date' => '2000-01-01']);

        $this->assertSame(0, Artisan::call('kintai:resignation-deactivate'));

        $this->assertTrue($owner->fresh()->is_active);
        $this->assertNull($ownerReport->fresh()->deactivated_at);
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertSame(1, ActivityEntry::where('action', 'resignation_report.deactivation_skipped')->count());
    }

    public function test_the_command_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'kintai:resignation-deactivate'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('5 0 * * *', $event->expression);
    }

    // -- Journal ---------------------------------------------------------------------------------------------------------

    public function test_the_log_only_carries_identifiers(): void
    {
        $manager = $this->managerOf($this->store);
        $employee = $this->employeeOf($this->store, ['name' => 'Secret Person']);

        Livewire::actingAs($manager)->test('resignation-report.form')
            ->set('userId', (string) $employee->id)->set('values.employee_name', 'Secret Person')->set('values.reason', 'Secret reason')
            ->set('values.resignation_date', '2000-01-01')->call('save')->assertHasNoErrors();
        app(ResignationReportActions::class)->reactivateAccount($manager, ResignationReport::firstOrFail());

        $this->assertSame(1, ActivityEntry::where('action', 'resignation_report.deactivated')->count());
        $this->assertSame(1, ActivityEntry::where('action', 'resignation_report.reactivated')->count());

        $log = ActivityEntry::query()->where('action', 'like', 'resignation_report.%')->get()->map(fn (ActivityEntry $entry): string => json_encode($entry->properties))->implode('|');
        $this->assertStringContainsString((string) $employee->id, $log);
        foreach (['Secret Person', 'Secret reason'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    // -- Action « Licencier » ------------------------------------------------------------------------------------------

    public function test_the_dismiss_action_shows_for_a_creator_on_a_member_of_their_store_but_never_on_oneself(): void
    {
        $manager = $this->managerOf($this->store);
        $this->store->members()->attach($manager);
        $employee = $this->employeeOf($this->store);
        $outsider = $this->employeeOf(Store::factory()->create());
        $registry = app(EmployeeActionRegistry::class);

        $keys = fn (User $viewer, User $target): array => array_map(fn ($action): string => $action->key, $registry->actionsFor($viewer, $target->id));

        $this->assertSame(['resignation-report.dismiss'], $keys($manager, $employee));
        $this->assertSame([], $keys($manager, $manager));
        $this->assertSame([], $keys($manager, $outsider));
        $this->assertSame([], $keys($this->employeeOf($this->store), $employee));
        $this->assertStringContainsString('/resignation-report/create?user='.$employee->id, $registry->actionsFor($manager, $employee->id)[0]->url($employee->id));
    }

    public function test_the_dismiss_action_shows_on_the_employee_list(): void
    {
        $manager = $this->managerOf($this->store);
        $this->store->members()->attach($manager);
        $employee = $this->employeeOf($this->store);

        Livewire::actingAs($manager)->test('pages::employees.index')->assertSeeHtml('data-test="employee-action-resignation-report.dismiss"');
        $this->assertNotNull($employee);
    }

    public function test_the_form_opened_from_the_action_prefills_only_an_employee_in_scope(): void
    {
        $manager = $this->managerOf($this->store);
        $employee = $this->employeeOf($this->store, ['name' => 'Ana Member']);
        $foreign = $this->employeeOf(Store::factory()->create(), ['name' => 'Far Away']);

        Livewire::actingAs($manager)->withQueryParams(['user' => (string) $employee->id])->test('resignation-report.form')
            ->assertSet('userId', (string) $employee->id)->assertSet('values.employee_name', 'Ana Member');
        Livewire::actingAs($manager)->withQueryParams(['user' => (string) $foreign->id])->test('resignation-report.form')
            ->assertSet('userId', '')->assertSet('values.employee_name', '');
    }
}
