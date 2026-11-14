<?php

namespace Kintai\Bundles\SalaryReport\Tests;

use App\Actions\AnonymizeUser;
use App\Authorization\Authorizer;
use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Bundles\SalaryReport\Models\SalaryReportDeduction;
use Kintai\Bundles\SalaryReport\Models\SalaryReportMember;
use Kintai\Bundles\SalaryReport\Models\SalaryReportSetting;
use Kintai\Bundles\SalaryReport\SalaryReportActions;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Stores\StoreActionRegistry;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Rapports de salaire : calcul depuis les shifts, retenues, enregistrement, portée magasin, une permission par action sur
 * chaque surface (routes, composants, PDF, exports), journal sans montant, anonymisation, bundle désactivé.
 */
class SalaryReportTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private ShiftType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Zulu']);
        // 09:00-17:00 à 12,50 : un shift de 8 h avec 60 minutes de pause coûte 7 h x 12,50 = 87,50.
        $this->type = ShiftType::factory()->create(['name' => 'Day', 'starts_at' => '09:00', 'ends_at' => '17:00', 'hourly_rate' => 12.50]);
        $this->type->stores()->attach([$this->a->id, $this->b->id]);
    }

    private function enable(): void
    {
        $this->enableBundle('salary-report');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('salary-report') ?? throw new LogicException);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function holder(array $permissions, Store $store, string $name = 'Holder Person'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $store->members()->attach($user);
        $user->assignRole($this->customRole($permissions), $store);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function report(Store $store, ?User $employee = null, array $attributes = []): SalaryReport
    {
        return SalaryReport::create([
            'store_id' => $store->id, 'user_id' => $employee?->id, 'target_month' => '2026-10', 'total_payment' => 500000,
            'staff_man_hours' => 160, 'staff_total_payment' => 200000, 'total_deductions' => 20000, 'net_payment' => 180000, ...$attributes,
        ]);
    }

    private function shift(Store $store, User $user, string $date): void
    {
        Shift::fromLocalTimes($date, '09:00', '17:00', ['store_id' => $store->id, 'user_id' => $user->id, 'shift_type_id' => $this->type->id, 'pause_minutes' => 60])->save();
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_and_grants_the_manager_defaults_only(): void
    {
        $this->enable();

        foreach (['view', 'generate', 'export', 'delete', 'settings'] as $action) {
            $this->assertTrue(app(PermissionRegistry::class)->has('salary_reports.'.$action));
        }
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('salary_reports'));
        $this->assertTrue(app(NavigationRegistry::class)->has('salary-report'));

        $manager = $this->managerOf($this->a);
        $employee = $this->employeeOf($this->a);
        foreach (['view', 'generate', 'export', 'delete', 'settings'] as $action) {
            $this->assertTrue(app(Authorizer::class)->allows($manager, 'salary_reports.'.$action, $this->a));
            $this->assertFalse(app(Authorizer::class)->allows($manager, 'salary_reports.'.$action, $this->b));
            $this->assertFalse(app(Authorizer::class)->allows($employee, 'salary_reports.'.$action, $this->a));
        }
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('salary_reports.view'));
        $this->assertFalse(app(NavigationRegistry::class)->has('salary-report'));

        $this->actingAs($this->owner())->get('/salary-report')->assertNotFound();
        $this->assertTrue(Schema::hasTable('salary_reports'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enable();
        $report = $this->report($this->a);

        foreach (['', '/create', '/settings', '/'.$report->id, '/'.$report->id.'/edit', '/'.$report->id.'/pdf', '/export/json'] as $path) {
            $this->get('/salary-report'.$path)->assertRedirect(route('login'));
        }
    }

    // -- Une permission par route --------------------------------------------------------------------------------

    public function test_every_web_route_needs_its_own_permission(): void
    {
        $this->enable();
        $employee = $this->employeeOf($this->a);
        $report = $this->report($this->a, $employee);
        $id = $report->id;

        $routes = [
            'view' => ['/salary-report', '/salary-report/'.$id],
            'generate' => ['/salary-report/create', '/salary-report/'.$id.'/edit'],
            'settings' => ['/salary-report/settings'],
            'export' => ['/salary-report/export/json', '/salary-report/export/pdf', '/salary-report/export/pdf/download', '/salary-report/'.$id.'/pdf', '/salary-report/'.$id.'/pdf/download'],
        ];

        foreach ($routes as $permission => $paths) {
            // Un employé ordinaire, y compris celui du rapport, n'a rien.
            foreach ($paths as $path) {
                $this->actingAs($employee)->get($path)->assertForbidden();
            }

            // Une autre permission du bundle ne suffit pas : view + export ouvrent les exports, view seul non.
            $others = array_values(array_diff(['view', 'generate', 'export', 'delete', 'settings'], [$permission, 'view', 'export']));
            foreach ($paths as $path) {
                $this->actingAs($this->holder(array_map(fn (string $p): string => 'salary_reports.'.$p, $others), $this->a))->get($path)->assertForbidden();
            }
        }

        // Avec la permission : view ouvre la liste et le détail ; generate le formulaire ; settings les retenues ; view + export les PDF.
        $viewer = $this->holder(['salary_reports.view'], $this->a);
        $this->actingAs($viewer)->get('/salary-report')->assertOk()->assertSee('Alpha');
        $this->actingAs($viewer)->get('/salary-report/'.$id)->assertOk();
        $this->actingAs($viewer)->get('/salary-report/'.$id.'/edit')->assertForbidden();
        $this->actingAs($viewer)->get('/salary-report/'.$id.'/pdf')->assertForbidden();
        $this->actingAs($viewer)->get('/salary-report/export/json')->assertForbidden();

        $this->actingAs($this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a))->get('/salary-report/create')->assertOk();
        $this->actingAs($this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a))->get('/salary-report/'.$id.'/edit')->assertOk();
        $this->actingAs($this->holder(['salary_reports.settings'], $this->a))->get('/salary-report/settings')->assertOk();

        $exporter = $this->holder(['salary_reports.view', 'salary_reports.export'], $this->a);
        $this->actingAs($exporter)->get('/salary-report/'.$id.'/pdf')->assertOk();
        $this->actingAs($exporter)->get('/salary-report/'.$id.'/pdf/download')->assertOk();
        $this->actingAs($exporter)->get('/salary-report/export/json')->assertOk();
        $this->actingAs($exporter)->get('/salary-report/export/pdf')->assertOk();
        $this->actingAs($exporter)->get('/salary-report/export/pdf/download')->assertOk();
    }

    public function test_export_without_view_cannot_read_a_report(): void
    {
        $this->enable();
        $report = $this->report($this->a);
        $exportOnly = $this->holder(['salary_reports.export'], $this->a);

        $this->actingAs($exportOnly)->get('/salary-report/'.$report->id.'/pdf')->assertNotFound();
        $this->actingAs($exportOnly)->get('/salary-report/'.$report->id.'/pdf/download')->assertNotFound();
        $this->actingAs($exportOnly)->get('/salary-report/export/pdf')->assertForbidden();
        $this->actingAs($exportOnly)->get('/salary-report/export/json')->assertForbidden();
    }

    // -- Portée magasin et confidentialité ----------------------------------------------------------------------

    public function test_a_holder_only_sees_the_reports_of_their_stores_and_never_those_of_others(): void
    {
        $this->enable();
        $colleague = $this->employeeOf($this->a, ['name' => 'Colleague Alpha']);
        $foreigner = $this->employeeOf($this->b, ['name' => 'Foreigner Zulu']);
        $mine = $this->report($this->a, $colleague);
        $foreign = $this->report($this->b, $foreigner, ['staff_total_payment' => 987654]);
        $viewer = $this->holder(['salary_reports.view'], $this->a);

        Livewire::actingAs($viewer)->test('salary-report.index')->assertSee('Colleague Alpha')->assertDontSee('Foreigner Zulu')->assertDontSee('987,654');
        // Modifier le filtre de l'URL ne donne pas accès à un autre magasin.
        Livewire::actingAs($viewer)->withQueryParams(['store' => (string) $this->b->id])->test('salary-report.index')->assertDontSee('Foreigner Zulu');
        Livewire::actingAs($viewer)->withQueryParams(['employee' => (string) $foreigner->id])->test('salary-report.index')->assertDontSee('Foreigner Zulu');

        $this->actingAs($viewer)->get('/salary-report/'.$mine->id)->assertOk();
        $this->actingAs($viewer)->get('/salary-report/'.$foreign->id)->assertNotFound();
        $this->actingAs($this->holder(['salary_reports.view', 'salary_reports.export'], $this->a))->get('/salary-report/'.$foreign->id.'/pdf')->assertNotFound();
    }

    public function test_an_employee_never_sees_a_salary_even_their_own(): void
    {
        $this->enable();
        $employee = $this->employeeOf($this->a);
        $own = $this->report($this->a, $employee);

        $this->actingAs($employee)->get('/salary-report')->assertForbidden();
        $this->actingAs($employee)->get('/salary-report/'.$own->id)->assertForbidden();
        Livewire::actingAs($employee)->test('salary-report.show', ['report' => $own->id])->assertNotFound();
    }

    public function test_a_store_without_the_feature_is_closed(): void
    {
        $this->enable();
        $report = $this->report($this->a);
        $viewer = $this->holder(['salary_reports.view', 'salary_reports.generate', 'salary_reports.export'], $this->a);
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'salary_reports', 'enabled' => false]);

        $this->actingAs($viewer)->get('/salary-report/'.$report->id)->assertNotFound();
        $this->actingAs($viewer)->get('/salary-report/'.$report->id.'/pdf')->assertNotFound();
        $this->actingAs($viewer)->get('/salary-report')->assertForbidden();
    }

    // -- Calcul ---------------------------------------------------------------------------------------------------

    public function test_the_calculation_comes_from_the_shifts_of_the_period_and_the_store(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a, ['name' => 'Ana Member']);
        $bob = $this->employeeOf($this->a, ['name' => 'Bob Member']);
        $this->shift($this->a, $ana, '2026-10-05');
        $this->shift($this->a, $ana, '2026-10-06');
        $this->shift($this->a, $bob, '2026-10-07');
        $this->shift($this->a, $bob, '2026-11-02');
        $this->shift($this->b, $this->employeeOf($this->b), '2026-10-08');

        $viewer = $this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a);
        $component = Livewire::actingAs($viewer)->test('salary-report.form')->set('month', '2026-10');

        $component->assertSet('values.staff_man_hours', '21')->assertSet('values.staff_total_payment', '262.5')
            ->assertSet('values.staff_avg_hourly_wage', '12.5')->assertSet('values.active_employees', '2')
            ->assertSet('values.total_payment', '0')->assertSet('values.total_deductions', '0')
            ->assertSet('values.net_payment', '262.5')->assertSet('values.bank_transfer_salary', '262.5');

        $component->set('employeeId', (string) $ana->id)
            ->assertSet('values.staff_man_hours', '14')->assertSet('values.staff_total_payment', '175')->assertSet('values.active_employees', '1');
    }

    public function test_deductions_apply_to_the_employees_subject_to_them_only(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        $bob = $this->employeeOf($this->a);
        $this->shift($this->a, $ana, '2026-10-05');
        $this->shift($this->a, $bob, '2026-10-06');
        SalaryReportSetting::create(['store_id' => $this->a->id, 'deductions_enabled' => true, 'health_insurance_rate' => 10, 'pension_rate' => 0, 'employment_insurance_rate' => 0, 'income_tax_rate' => 20, 'resident_tax_monthly' => 5]);
        SalaryReportMember::create(['store_id' => $this->a->id, 'user_id' => $ana->id, 'subject_to_deductions' => true]);

        $manager = $this->managerOf($this->a);
        // Ana : 87,50 brut -> santé 8,75, impôt 17,50, taxe fixe 5 ; Bob n'est pas assujetti.
        Livewire::actingAs($manager)->test('salary-report.form')->set('month', '2026-10')
            ->assertSet('values.staff_total_payment', '175')->assertSet('values.other_deductions', '8.75')
            ->assertSet('values.withholding_tax', '17.5')->assertSet('values.residence_tax', '5')
            ->assertSet('values.total_deductions', '31.25')->assertSet('values.net_payment', '143.75')
            ->assertSet('values.income_tax_base', '175');

        // Désactivées, plus aucune retenue.
        SalaryReportSetting::query()->update(['deductions_enabled' => false]);
        Livewire::actingAs($manager)->test('salary-report.form')->set('month', '2026-10')
            ->assertSet('values.total_deductions', '0')->assertSet('values.net_payment', '175');
    }

    public function test_the_calculation_refuses_a_store_without_generate_or_a_foreign_employee(): void
    {
        $this->enable();
        $viewerOnly = $this->holder(['salary_reports.view'], $this->a);
        Livewire::actingAs($viewerOnly)->test('salary-report.form')->assertForbidden();

        $generator = $this->holder(['salary_reports.generate'], $this->a);
        $stranger = $this->employeeOf($this->b);
        Livewire::actingAs($generator)->test('salary-report.form')->set('employeeId', (string) $stranger->id)->assertHasErrors('employeeId');

        // Changer le magasin dans le navigateur vers un magasin sans droit : refusé.
        Livewire::actingAs($generator)->test('salary-report.form')->set('storeId', $this->b->id)->assertForbidden();
    }

    public function test_an_invalid_period_is_refused_without_echoing_figures(): void
    {
        $this->enable();
        $generator = $this->holder(['salary_reports.generate'], $this->a);

        Livewire::actingAs($generator)->test('salary-report.form')->set('periodMode', 'custom')->set('from', '2026-10-31')->set('to', '2026-10-01')
            ->assertHasErrors('from');
        Livewire::actingAs($generator)->test('salary-report.form')->set('periodMode', 'custom')->set('from', '2020-01-01')->set('to', '2026-10-01')
            ->assertHasErrors('from');
    }

    // -- Enregistrer ----------------------------------------------------------------------------------------------

    public function test_a_holder_of_generate_creates_the_report_of_a_month(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        $this->shift($this->a, $ana, '2026-10-05');
        $generator = $this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a);

        Livewire::actingAs($generator)->test('salary-report.form')->set('month', '2026-10')->set('employeeId', (string) $ana->id)
            ->set('values.total_payment', '1234.5')->set('remarks', '  Paid on the 25th  ')->set('personInChargeId', (string) $generator->id)
            ->call('save')->assertHasNoErrors();

        $report = SalaryReport::firstOrFail();
        $this->assertSame([$this->a->id, $ana->id, '2026-10', $generator->id, $generator->id, 'Paid on the 25th'], [$report->store_id, $report->user_id, $report->target_month, $report->person_in_charge_id, $report->created_by, $report->remarks]);
        $this->assertSame(['87.50', '1234.50', '7.00'], [$report->staff_total_payment, $report->total_payment, $report->staff_man_hours]);
        $this->assertSame([['user_id' => $ana->id, 'minutes' => 420]], $report->employee_hours);
    }

    public function test_one_report_per_store_month_and_employee(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        $this->report($this->a);
        $this->report($this->a, $ana);
        $generator = $this->holder(['salary_reports.generate'], $this->a);

        Livewire::actingAs($generator)->test('salary-report.form')->set('month', '2026-10')->call('save')->assertHasErrors('month');
        Livewire::actingAs($generator)->test('salary-report.form')->set('month', '2026-10')->set('employeeId', (string) $ana->id)->call('save')->assertHasErrors('month');
        $this->assertSame(2, SalaryReport::count());

        // Un autre mois passe.
        Livewire::actingAs($generator)->test('salary-report.form')->set('month', '2026-09')->call('save')->assertHasNoErrors();
        $this->assertSame(3, SalaryReport::count());
    }

    public function test_the_person_in_charge_must_be_able_to_generate_in_the_store(): void
    {
        $this->enable();
        $generator = $this->holder(['salary_reports.generate'], $this->a);
        $plain = $this->employeeOf($this->a);

        Livewire::actingAs($generator)->test('salary-report.form')->set('month', '2026-10')->set('personInChargeId', (string) $plain->id)->call('save')->assertHasErrors('personInChargeId');
        $this->assertSame(0, SalaryReport::count());
    }

    public function test_amounts_are_validated(): void
    {
        $this->enable();
        $generator = $this->holder(['salary_reports.generate'], $this->a);

        Livewire::actingAs($generator)->test('salary-report.form')->set('month', '2026-10')->set('values.total_payment', '-5')->call('save')->assertHasErrors('values.total_payment');
        Livewire::actingAs($generator)->test('salary-report.form')->set('month', '2026-10')->set('values.new_hires', '1.5')->call('save')->assertHasErrors('values.new_hires');
        Livewire::actingAs($generator)->test('salary-report.form')->set('month', '2026-10')->set('values.net_payment', 'abc')->call('save')->assertHasErrors('values.net_payment');
        $this->assertSame(0, SalaryReport::count());
    }

    public function test_editing_needs_generate_and_never_changes_the_identity_of_the_report(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        $report = $this->report($this->a, $ana);

        Livewire::actingAs($this->holder(['salary_reports.view'], $this->a))->test('salary-report.form', ['report' => $report->id])->assertForbidden();
        Livewire::actingAs($this->holder(['salary_reports.generate'], $this->b))->test('salary-report.form', ['report' => $report->id])->assertNotFound();

        $editor = $this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a);
        Livewire::actingAs($editor)->test('salary-report.form', ['report' => $report->id])
            ->assertSet('values.total_payment', '500000')
            ->set('values.total_payment', '650000')->set('month', '2025-01')->set('employeeId', '')->set('storeId', $this->b->id)
            ->call('save')->assertHasNoErrors();

        $report->refresh();
        $this->assertSame('650000.00', $report->total_payment);
        $this->assertSame([$this->a->id, $ana->id, '2026-10'], [$report->store_id, $report->user_id, $report->target_month]);
    }

    public function test_recalculating_an_existing_report_uses_its_own_month_and_employee(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        $this->shift($this->a, $ana, '2026-10-05');
        $this->shift($this->a, $ana, '2026-11-05');
        $report = $this->report($this->a, $ana);
        $editor = $this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a);

        Livewire::actingAs($editor)->test('salary-report.form', ['report' => $report->id])->call('calculate')
            ->assertSet('values.staff_total_payment', '87.5')->assertSet('values.staff_man_hours', '7');
    }

    // -- Supprimer ------------------------------------------------------------------------------------------------

    public function test_deleting_needs_its_own_permission(): void
    {
        $this->enable();
        $report = $this->report($this->a);

        $generator = $this->holder(['salary_reports.view', 'salary_reports.generate', 'salary_reports.export', 'salary_reports.settings'], $this->a);
        Livewire::actingAs($generator)->test('salary-report.show', ['report' => $report->id])->assertDontSeeHtml('data-test="delete-report-button"')
            ->call('delete')->assertForbidden();
        Livewire::actingAs($generator)->test('salary-report.show', ['report' => $report->id])->call('confirmDelete')->assertForbidden();
        $this->assertSame(1, SalaryReport::count());

        $remover = $this->holder(['salary_reports.view', 'salary_reports.delete'], $this->a);
        Livewire::actingAs($remover)->test('salary-report.show', ['report' => $report->id])->assertSeeHtml('data-test="delete-report-button"')->call('delete')->assertRedirect();
        $this->assertSame(0, SalaryReport::count());
        $this->assertNotNull(ActivityEntry::where('action', 'salary_report.deleted')->first());
    }

    public function test_deleting_a_report_of_another_store_is_refused(): void
    {
        $this->enable();
        $foreign = $this->report($this->b);
        $remover = $this->holder(['salary_reports.view', 'salary_reports.delete'], $this->a);

        Livewire::actingAs($remover)->test('salary-report.show', ['report' => $foreign->id])->assertNotFound();
        $this->assertSame(1, SalaryReport::count());
    }

    // -- Détail ---------------------------------------------------------------------------------------------------

    public function test_the_page_of_an_employee_report_shows_the_day_by_day_detail_and_buttons_follow_the_permissions(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a, ['name' => 'Ana Member']);
        $this->shift($this->a, $ana, '2026-10-05');
        $report = $this->report($this->a, $ana);

        Livewire::actingAs($this->holder(['salary_reports.view'], $this->a))->test('salary-report.show', ['report' => $report->id])
            ->assertSee('Ana Member')->assertSee('2026-10-05')->assertSeeHtml('data-test="detail"')
            ->assertDontSeeHtml('data-test="pdf-link"')->assertDontSeeHtml('data-test="edit-report-button"')->assertDontSeeHtml('data-test="delete-report-button"');

        Livewire::actingAs($this->holder(['salary_reports.view', 'salary_reports.export', 'salary_reports.generate'], $this->a))->test('salary-report.show', ['report' => $report->id])
            ->assertSeeHtml('data-test="pdf-link"')->assertSeeHtml('data-test="edit-report-button"');
    }

    // -- PDF et exports ------------------------------------------------------------------------------------------

    public function test_the_pdf_is_private_generated_on_the_fly_and_logged(): void
    {
        $this->enable();
        $report = $this->report($this->a, $this->employeeOf($this->a, ['name' => 'Ana Member']));
        $exporter = $this->holder(['salary_reports.view', 'salary_reports.export'], $this->a);

        $preview = $this->actingAs($exporter)->get('/salary-report/'.$report->id.'/pdf')->assertOk()->assertSee('Alpha')->assertSee('Ana Member');
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $preview->headers->get('Cache-Control'));

        $download = $this->actingAs($exporter)->get('/salary-report/'.$report->id.'/pdf/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $download->getContent());
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertNotNull(ActivityEntry::where('action', 'salary_report.pdf_exported')->first());
    }

    public function test_the_list_exports_only_the_stores_with_view_and_export(): void
    {
        $this->enable();
        $this->report($this->a, null, ['target_month' => '2026-10']);
        $this->report($this->a, null, ['target_month' => '2026-09']);
        $this->report($this->b, null, ['target_month' => '2026-10']);
        $exporter = $this->holder(['salary_reports.view', 'salary_reports.export'], $this->a);

        $response = $this->actingAs($exporter)->get('/salary-report/export/json')->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertCount(2, $data);
        $this->assertSame([$this->a->id, $this->a->id], array_map(fn (array $row): int => $row['store']['id'], $data));

        $this->assertCount(1, json_decode((string) $this->actingAs($exporter)->get('/salary-report/export/json?month=9')->getContent(), true)['data']);
        // Un magasin demandé hors du périmètre est refusé.
        $this->actingAs($exporter)->get('/salary-report/export/json?store_id='.$this->b->id)->assertForbidden();
        $this->actingAs($exporter)->get('/salary-report/export/pdf?store_id='.$this->b->id)->assertForbidden();
        $this->actingAs($exporter)->get('/salary-report/export/pdf/download?store_id='.$this->b->id)->assertForbidden();

        $pdf = $this->actingAs($exporter)->get('/salary-report/export/pdf/download')->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertSame(3, ActivityEntry::where('action', 'salary_report.list_exported')->count());
    }

    public function test_the_export_stores_are_the_intersection_of_view_and_export(): void
    {
        $this->enable();
        $this->report($this->a);
        $this->report($this->b);
        $user = User::factory()->create();
        $this->a->members()->attach($user);
        $this->b->members()->attach($user);
        $user->assignRole($this->customRole(['salary_reports.view']), $this->a);
        $user->assignRole($this->customRole(['salary_reports.view']), $this->b);
        $user->assignRole($this->customRole(['salary_reports.export']), $this->b);

        // view sur A et B, export sur B seulement : seul B sort.
        $data = json_decode((string) $this->actingAs($user)->get('/salary-report/export/json')->assertOk()->getContent(), true)['data'];
        $this->assertSame([$this->b->id], array_map(fn (array $row): int => $row['store']['id'], $data));
    }

    // -- Journal --------------------------------------------------------------------------------------------------

    public function test_the_activity_log_never_contains_a_figure_or_a_note(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('salary-report.form')->set('month', '2026-10')->set('employeeId', (string) $ana->id)
            ->set('values.total_payment', '777777')->set('remarks', 'Secret remark')->call('save');
        $report = SalaryReport::firstOrFail();
        Livewire::actingAs($manager)->test('salary-report.form', ['report' => $report->id])->set('values.net_payment', '888888')->call('save');
        $this->actingAs($manager)->get('/salary-report/'.$report->id.'/pdf/download');
        $this->actingAs($manager)->get('/salary-report/export/json');

        $this->assertNotNull(ActivityEntry::where('action', 'salary_report.created')->first());
        $this->assertNotNull(ActivityEntry::where('action', 'salary_report.updated')->first());
        $this->assertNotNull(ActivityEntry::where('action', 'salary_report.list_exported')->first());

        $log = ActivityEntry::query()->get()->map(fn (ActivityEntry $entry): string => json_encode($entry->properties))->implode('|');
        foreach (['777777', '888888', 'Secret remark'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertStringContainsString('net_payment', $log);
    }

    // -- Réglages -------------------------------------------------------------------------------------------------

    public function test_settings_need_their_own_permission_on_the_store(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);

        Livewire::actingAs($this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a))->test('salary-report.settings')->assertForbidden();

        $admin = $this->holder(['salary_reports.settings'], $this->a);
        Livewire::actingAs($admin)->test('salary-report.settings')->assertSet('store', (string) $this->a->id)
            ->set('enabled', true)->set('healthRate', '4.99')->set('pensionRate', '9.15')->set('employmentRate', '0.6')->set('incomeTaxRate', '3')->set('residentTax', '1000')
            ->set('subjects', [$ana->id])->call('save')->assertHasNoErrors();

        $setting = SalaryReportSetting::query()->firstOrNew(['store_id' => $this->a->id], SalaryReportSetting::DEFAULTS);
        $this->assertTrue($setting->deductions_enabled);
        $this->assertSame(['4.99', '9.15', '0.60', '3.00', '1000.00'], [$setting->health_insurance_rate, $setting->pension_rate, $setting->employment_insurance_rate, $setting->income_tax_rate, $setting->resident_tax_monthly]);
        $this->assertTrue(SalaryReportMember::where(['store_id' => $this->a->id, 'user_id' => $ana->id])->firstOrFail()->subject_to_deductions);
        $this->assertNotNull(ActivityEntry::where('action', 'salary_report.settings_updated')->first());

        // Retirer l'employé le désassujettit.
        Livewire::actingAs($admin)->test('salary-report.settings')->assertSet('subjects', [$ana->id])->set('subjects', [])->call('save');
        $this->assertFalse(SalaryReportMember::where(['store_id' => $this->a->id, 'user_id' => $ana->id])->firstOrFail()->subject_to_deductions);
    }

    public function test_settings_of_another_store_cannot_be_reached_or_written(): void
    {
        $this->enable();
        $admin = $this->holder(['salary_reports.settings'], $this->a);

        Livewire::actingAs($admin)->test('salary-report.settings')->set('store', (string) $this->b->id)->assertForbidden();
        $this->assertNull(SalaryReportSetting::query()->find($this->b->id));

        // Un employé d'un autre magasin n'est jamais enregistré, ni par l'écran ni par l'action.
        $outsider = $this->employeeOf($this->b);
        Livewire::actingAs($admin)->test('salary-report.settings')->set('subjects', [$outsider->id])->call('save');
        $this->assertSame(0, SalaryReportMember::where('user_id', $outsider->id)->count());

        $this->expectException(ValidationException::class);
        app(SalaryReportActions::class)->saveSettings($admin, $this->a->id, ['deductions_enabled' => true, 'health_insurance_rate' => 1, 'pension_rate' => 1, 'employment_insurance_rate' => 1, 'income_tax_rate' => 1, 'resident_tax_monthly' => 0], [$outsider->id]);
    }

    public function test_settings_rates_are_validated(): void
    {
        $this->enable();
        $admin = $this->holder(['salary_reports.settings'], $this->a);

        Livewire::actingAs($admin)->test('salary-report.settings')->set('healthRate', '150')->call('save')->assertHasErrors('healthRate');
        Livewire::actingAs($admin)->test('salary-report.settings')->set('residentTax', '-1')->call('save')->assertHasErrors('residentTax');
    }

    // -- Retenues personnalisées ---------------------------------------------------------------------------------

    /**
     * Ana (assujettie) et Bob (non assujetti) ont chacun 87,50 de brut ; retenues activées, taux fixes à 0.
     *
     * @return array{0: User, 1: User}
     */
    private function customDeductionsSetup(): array
    {
        $ana = $this->employeeOf($this->a, ['name' => 'Ana Member']);
        $bob = $this->employeeOf($this->a, ['name' => 'Bob Member']);
        $this->shift($this->a, $ana, '2026-10-05');
        $this->shift($this->a, $bob, '2026-10-06');
        SalaryReportSetting::create(['store_id' => $this->a->id, 'deductions_enabled' => true, 'health_insurance_rate' => 0, 'pension_rate' => 0, 'employment_insurance_rate' => 0, 'income_tax_rate' => 0, 'resident_tax_monthly' => 0]);
        SalaryReportMember::create(['store_id' => $this->a->id, 'user_id' => $ana->id, 'subject_to_deductions' => true]);

        return [$ana, $bob];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customDeduction(Store $store, string $name, string $kind, float $value, array $attributes = []): SalaryReportDeduction
    {
        return SalaryReportDeduction::create(['store_id' => $store->id, 'name' => $name, 'kind' => $kind, 'value' => $value, 'sort_order' => SalaryReportDeduction::count(), ...$attributes]);
    }

    public function test_custom_deductions_are_computed_for_subject_employees_only_and_inactive_ones_are_ignored(): void
    {
        $this->enable();
        [$ana, $bob] = $this->customDeductionsSetup();
        $this->customDeduction($this->a, 'Mutual', 'percent', 10);
        $this->customDeduction($this->a, 'Union', 'fixed', 3);
        $this->customDeduction($this->a, 'Old', 'percent', 50, ['is_active' => false]);
        // Une autre boutique : jamais appliquée ici.
        $this->customDeduction($this->b, 'Foreign', 'fixed', 99);

        $manager = $this->managerOf($this->a);
        // Ana : 87,50 -> 8,75 (10 %) + 3 fixe ; Bob n'est pas assujetti. Les retenues fixes (santé, etc.) restent à 0.
        $component = Livewire::actingAs($manager)->test('salary-report.form')->set('month', '2026-10')
            ->assertSet('values.staff_total_payment', '175')->assertSet('values.other_deductions', '0')
            ->assertSet('values.withholding_tax', '0')->assertSet('values.residence_tax', '0')
            ->assertSet('values.total_deductions', '11.75')->assertSet('values.net_payment', '163.25')->assertSet('values.bank_transfer_salary', '163.25')
            ->assertSet('deductionLines', [
                ['name' => 'Mutual', 'kind' => 'percent', 'value' => 10.0, 'amount' => 8.75],
                ['name' => 'Union', 'kind' => 'fixed', 'value' => 3.0, 'amount' => 3.0],
            ]);

        // Employé non assujetti : aucune retenue personnalisée ; assujetti : les siennes.
        $component->set('employeeId', (string) $bob->id)->assertSet('values.total_deductions', '0')->assertSet('deductionLines', []);
        $component->set('employeeId', (string) $ana->id)->assertSet('values.total_deductions', '11.75')->assertSet('values.net_payment', '75.75');

        // Retenues du magasin désactivées : les personnalisées aussi.
        SalaryReportSetting::query()->update(['deductions_enabled' => false]);
        Livewire::actingAs($manager)->test('salary-report.form')->set('month', '2026-10')->assertSet('values.total_deductions', '0')->assertSet('deductionLines', []);
    }

    public function test_a_fixed_custom_deduction_skips_employees_without_gross_pay(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        SalaryReportSetting::create(['store_id' => $this->a->id, 'deductions_enabled' => true]);
        SalaryReportMember::create(['store_id' => $this->a->id, 'user_id' => $ana->id, 'subject_to_deductions' => true]);
        $this->customDeduction($this->a, 'Union', 'fixed', 3);

        // Aucun shift : brut à 0, rien à retenir.
        Livewire::actingAs($this->managerOf($this->a))->test('salary-report.form')->set('month', '2026-10')
            ->assertSet('values.total_deductions', '0')->assertSet('deductionLines', []);
    }

    public function test_a_saved_report_keeps_its_snapshot_when_the_deduction_changes_or_disappears(): void
    {
        $this->enable();
        [$ana] = $this->customDeductionsSetup();
        $deduction = $this->customDeduction($this->a, 'Mutual', 'percent', 10);
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('salary-report.form')->set('month', '2026-10')->set('employeeId', (string) $ana->id)->call('save')->assertHasNoErrors();
        $report = SalaryReport::firstOrFail();
        $this->assertEquals([['name' => 'Mutual', 'kind' => 'percent', 'value' => 10.0, 'amount' => 8.75]], $report->deduction_lines);
        $this->assertSame('8.75', $report->total_deductions);
        $this->assertSame('78.75', $report->net_payment);
        $this->assertSame('0.00', $report->other_deductions);

        // La retenue change puis disparaît : le détail enregistré ne bouge pas.
        $deduction->update(['name' => 'Renamed', 'value' => 50]);
        $this->actingAs($manager)->get('/salary-report/'.$report->id)->assertOk()->assertSee('Mutual')->assertSee('(10 %)', false)->assertDontSee('Renamed');
        $deduction->delete();
        $this->actingAs($manager)->get('/salary-report/'.$report->id)->assertOk()->assertSee('Mutual');
        $this->actingAs($manager)->get('/salary-report/'.$report->id.'/pdf')->assertOk()->assertSee('Mutual');
        $this->assertSame('8.75', $report->fresh()->total_deductions);

        // Modifier un texte garde l'instantané ; recalculer le remplace (la retenue n'existe plus).
        Livewire::actingAs($manager)->test('salary-report.form', ['report' => $report->id])->set('remarks', 'Edited')->call('save');
        $this->assertSame('Mutual', $report->fresh()->deduction_lines[0]['name']);
        Livewire::actingAs($manager)->test('salary-report.form', ['report' => $report->id])->call('calculate')->call('save');
        $this->assertNull($report->fresh()->deduction_lines);
        $this->assertSame('0.00', $report->fresh()->total_deductions);
    }

    public function test_a_store_report_lists_its_custom_deductions(): void
    {
        $this->enable();
        $this->customDeductionsSetup();
        $this->customDeduction($this->a, 'Mutual', 'percent', 10);
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('salary-report.form')->set('month', '2026-10')->call('save')->assertHasNoErrors();
        $report = SalaryReport::firstOrFail();
        $this->assertNull($report->user_id);
        $this->assertSame(8.75, $report->deduction_lines[0]['amount']);
        $this->actingAs($manager)->get('/salary-report/'.$report->id)->assertOk()->assertSee('Mutual')->assertSee('8.75');
    }

    public function test_custom_deductions_are_managed_from_the_settings_screen(): void
    {
        $this->enable();
        $admin = $this->holder(['salary_reports.settings'], $this->a);

        $component = Livewire::actingAs($admin)->test('salary-report.settings')
            ->set('deductionName', 'Mutual')->set('deductionKind', 'percent')->set('deductionValue', '2.5')->call('saveDeduction')->assertHasNoErrors()
            ->assertSet('deductionName', '')
            ->set('deductionName', 'Union')->set('deductionKind', 'fixed')->set('deductionValue', '1500')->call('saveDeduction')->assertHasNoErrors();

        $mutual = SalaryReportDeduction::where('name', 'Mutual')->firstOrFail();
        $union = SalaryReportDeduction::where('name', 'Union')->firstOrFail();
        $this->assertSame(['percent', '2.50', true, $this->a->id], [$mutual->kind, $mutual->value, $mutual->is_active, $mutual->store_id]);
        $this->assertSame(['fixed', '1500.00'], [$union->kind, $union->value]);
        $this->assertGreaterThan($mutual->sort_order, $union->sort_order);
        $component->assertSee('Mutual')->assertSee('Union');

        // Modifier (le nom garde sa propre unicité), désactiver, réactiver, supprimer.
        $component->call('editDeduction', $mutual->id)->assertSet('deductionName', 'Mutual')->assertSet('deductionValue', '2.5')
            ->set('deductionValue', '4')->call('saveDeduction')->assertHasNoErrors();
        $this->assertSame('4.00', $mutual->fresh()->value);
        $component->call('toggleDeduction', $mutual->id);
        $this->assertFalse($mutual->fresh()->is_active);
        $component->call('toggleDeduction', $mutual->id);
        $this->assertTrue($mutual->fresh()->is_active);
        $component->call('deleteDeduction', $union->id);
        $this->assertNull(SalaryReportDeduction::find($union->id));
        $this->assertSame(1, SalaryReportDeduction::count());
    }

    public function test_custom_deductions_are_validated(): void
    {
        $this->enable();
        $admin = $this->holder(['salary_reports.settings'], $this->a);
        $this->customDeduction($this->a, 'Mutual', 'percent', 2);
        // Même nom dans un autre magasin : sans effet sur l'unicité de celui-ci.
        $this->customDeduction($this->b, 'Union', 'fixed', 2);

        $screen = fn (string $name, string $kind, string $value) => Livewire::actingAs($admin)->test('salary-report.settings')
            ->set('deductionName', $name)->set('deductionKind', $kind)->set('deductionValue', $value)->call('saveDeduction');

        $screen('Mutual', 'percent', '1')->assertHasErrors('deductionName');
        $screen('', 'percent', '1')->assertHasErrors('deductionName');
        $screen(str_repeat('x', 101), 'percent', '1')->assertHasErrors('deductionName');
        $screen('Bad', 'weekly', '1')->assertHasErrors('deductionKind');
        $screen('Bad', 'percent', '100.01')->assertHasErrors('deductionValue');
        $screen('Bad', 'percent', '-1')->assertHasErrors('deductionValue');
        $screen('Bad', 'fixed', 'abc')->assertHasErrors('deductionValue');
        $screen('Union', 'fixed', '100')->assertHasNoErrors();
        $screen('Edge', 'percent', '100')->assertHasNoErrors();
        $screen('Zero', 'fixed', '0')->assertHasNoErrors();

        // Les mêmes règles tiennent dans l'action (autre surface que l'écran).
        $actions = app(SalaryReportActions::class);
        foreach ([['Mutual', 'percent', 1], ['Bad', 'nope', 1], ['Bad', 'percent', 101], ['Bad', 'fixed', -1]] as [$name, $kind, $value]) {
            try {
                $actions->saveDeduction($admin, $this->a->id, null, ['name' => $name, 'kind' => $kind, 'value' => $value]);
                $this->fail('The deduction should have been refused.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_store_has_twenty_custom_deductions_at_most(): void
    {
        $this->enable();
        $admin = $this->holder(['salary_reports.settings'], $this->a);
        for ($i = 1; $i <= SalaryReportDeduction::MAX_PER_STORE; $i++) {
            $this->customDeduction($this->a, 'Deduction '.$i, 'fixed', 1);
        }

        Livewire::actingAs($admin)->test('salary-report.settings')->set('deductionName', 'One too many')->set('deductionKind', 'fixed')->set('deductionValue', '1')
            ->call('saveDeduction')->assertHasErrors('deductionName');
        $this->assertSame(20, SalaryReportDeduction::where('store_id', $this->a->id)->count());

        // Modifier une retenue existante reste possible au plafond ; un autre magasin a son propre quota.
        Livewire::actingAs($admin)->test('salary-report.settings')->call('editDeduction', SalaryReportDeduction::firstOrFail()->id)->set('deductionValue', '2')->call('saveDeduction')->assertHasNoErrors();
        $this->customDeduction($this->b, 'Deduction 1', 'fixed', 1);
        $this->assertSame(1, SalaryReportDeduction::where('store_id', $this->b->id)->count());
    }

    public function test_custom_deductions_follow_the_settings_permission_and_the_store_scope(): void
    {
        $this->enable();
        $foreign = $this->customDeduction($this->b, 'Foreign', 'fixed', 9);
        $this->customDeduction($this->a, 'Own', 'fixed', 1);

        Livewire::actingAs($this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a))->test('salary-report.settings')->assertForbidden();

        $admin = $this->holder(['salary_reports.settings'], $this->a);
        $component = Livewire::actingAs($admin)->test('salary-report.settings')->assertSee('Own')->assertDontSee('Foreign');

        // Ni lire, ni modifier, ni supprimer, ni basculer la retenue d'un autre magasin.
        foreach (['editDeduction', 'toggleDeduction', 'deleteDeduction'] as $method) {
            Livewire::actingAs($admin)->test('salary-report.settings')->call($method, $foreign->id)->assertNotFound();
        }
        $component->set('store', (string) $this->b->id)->assertForbidden();
        $this->assertSame(['Foreign', true], [$foreign->fresh()->name, $foreign->fresh()->is_active]);

        // Même refus dans l'action, sans passer par l'écran.
        $actions = app(SalaryReportActions::class);
        $viewer = $this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a);
        try {
            $actions->saveDeduction($viewer, $this->a->id, null, ['name' => 'Nope', 'kind' => 'fixed', 'value' => 1]);
            $this->fail('A holder without settings must be refused.');
        } catch (AuthorizationException) {
            $this->assertSame(0, SalaryReportDeduction::where('name', 'Nope')->count());
        }
        $this->expectException(AuthorizationException::class);
        $actions->saveDeduction($admin, $this->a->id, $foreign->id, ['name' => 'Hijack', 'kind' => 'fixed', 'value' => 1]);
    }

    public function test_the_activity_log_of_custom_deductions_never_contains_a_name_or_a_figure(): void
    {
        $this->enable();
        $admin = $this->holder(['salary_reports.settings'], $this->a);

        $component = Livewire::actingAs($admin)->test('salary-report.settings')
            ->set('deductionName', 'Secret mutual')->set('deductionKind', 'fixed')->set('deductionValue', '424242')->call('saveDeduction');
        $deduction = SalaryReportDeduction::firstOrFail();
        $component->call('toggleDeduction', $deduction->id)->call('deleteDeduction', $deduction->id);

        $this->assertSame(2, ActivityEntry::where('action', 'salary_report.deduction_saved')->count());
        $this->assertSame(1, ActivityEntry::where('action', 'salary_report.deduction_deleted')->count());
        $log = ActivityEntry::query()->get()->map(fn (ActivityEntry $entry): string => json_encode($entry->properties))->implode('|');
        foreach (['Secret', 'mutual', '424242'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    // -- Données personnelles ------------------------------------------------------------------------------------

    public function test_anonymizing_an_employee_erases_the_free_text_and_keeps_the_figures(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        $report = $this->report($this->a, $ana, ['remarks' => 'Call Mr Tanaka', 'hire_registrations' => 'Hired Ana']);
        $other = $this->report($this->a, null, ['target_month' => '2026-09', 'remarks' => 'Keep me']);

        app(AnonymizeUser::class)->handle($ana);

        $this->assertNull($report->fresh()->remarks);
        $this->assertNull($report->fresh()->hire_registrations);
        $this->assertSame('200000.00', $report->fresh()->staff_total_payment);
        $this->assertSame('Keep me', $other->fresh()->remarks);
    }

    public function test_the_index_lists_reports_and_the_menu_entry_follows_the_permission(): void
    {
        $this->enable();
        $this->report($this->a);
        $viewer = $this->holder(['salary_reports.view'], $this->a);

        $keys = fn (User $user): array => array_map(fn ($item) => $item->key, app(NavigationRegistry::class)->visibleTo($user));

        $this->assertContains('salary-report', $keys($viewer));
        $this->assertNotContains('salary-report', $keys($this->employeeOf($this->a)));
        $this->assertNotContains('salary-report', $keys($this->holder(['salary_reports.generate'], $this->a)));
    }

    public function test_the_store_list_offers_the_deductions_to_who_can_manage_the_settings_of_the_store(): void
    {
        $this->enable();
        $registry = app(StoreActionRegistry::class);
        $keys = fn (User $viewer, Store $store): array => array_map(fn ($action): string => $action->key, $registry->actionsFor($viewer, $store->id));

        $manager = $this->holder(['salary_reports.settings'], $this->a);

        $this->assertContains('salary-report.settings', $keys($manager, $this->a));
        $this->assertNotContains('salary-report.settings', $keys($manager, $this->b));
        $this->assertNotContains('salary-report.settings', $keys($this->holder(['salary_reports.view'], $this->a, 'Viewer'), $this->a));
        $this->assertNotContains('salary-report.settings', $keys($this->employeeOf($this->a), $this->a));
    }
}
