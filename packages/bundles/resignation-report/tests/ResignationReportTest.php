<?php

namespace Kintai\Bundles\ResignationReport\Tests;

use App\Actions\AnonymizeUser;
use App\Authorization\Authorizer;
use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Employees\EmployeeActionRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Rapports de démission : portée magasin, une permission par action sur chaque surface (routes, composants, PDF), journal
 * sans donnée personnelle, anonymisation, bundle désactivé. La désactivation du compte lié est couverte par `ResignationDeactivationTest`.
 */
class ResignationReportTest extends TestCase
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
        $this->b = Store::factory()->create(['name' => 'Zulu']);
    }

    private function enable(): void
    {
        $this->enableBundle('resignation-report');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('resignation-report') ?? throw new LogicException);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function holder(array $permissions, Store $store): User
    {
        $user = User::factory()->create(['name' => 'Holder Person']);
        $store->members()->attach($user);
        $user->assignRole($this->customRole($permissions), $store);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function report(Store $store, array $attributes = []): ResignationReport
    {
        return ResignationReport::factory()->create(['store_id' => $store->id, ...$attributes]);
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_and_grants_the_manager_defaults_only(): void
    {
        $this->enable();

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $this->assertTrue(app(PermissionRegistry::class)->has('resignation_reports.'.$action));
        }
        $this->assertTrue(app(NavigationRegistry::class)->has('resignation-report'));

        $manager = $this->managerOf($this->a);
        $employee = $this->employeeOf($this->a);
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $this->assertTrue(app(Authorizer::class)->allows($manager, 'resignation_reports.'.$action, $this->a));
            $this->assertFalse(app(Authorizer::class)->allows($manager, 'resignation_reports.'.$action, $this->b));
            $this->assertFalse(app(Authorizer::class)->allows($employee, 'resignation_reports.'.$action, $this->a));
        }
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('resignation_reports.view'));
        $this->assertFalse(app(NavigationRegistry::class)->has('resignation-report'));
        $this->assertFalse(app(EmployeeActionRegistry::class)->has('resignation-report.dismiss'));

        $this->actingAs($this->owner())->get('/resignation-report')->assertNotFound();
        $this->assertTrue(Schema::hasTable('resignation_reports'));
    }

    public function test_the_form_covers_exactly_the_columns_of_the_table(): void
    {
        $columns = array_diff(Schema::getColumnListing('resignation_reports'), ['id', 'store_id', 'user_id', 'created_by', 'deactivated_at', 'created_at', 'updated_at']);

        $this->assertEqualsCanonicalizing(array_values($columns), ResignationReport::FIELDS);
        $this->assertEqualsCanonicalizing(ResignationReport::FIELDS, array_values(array_diff((new ResignationReport)->getFillable(), ['store_id', 'user_id', 'created_by'])));
    }

    // -- Une permission par route ----------------------------------------------------------------------------------

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enable();
        $report = $this->report($this->a);

        foreach (['', '/create', '/'.$report->id, '/'.$report->id.'/edit', '/'.$report->id.'/pdf', '/'.$report->id.'/pdf/download'] as $path) {
            $this->get('/resignation-report'.$path)->assertRedirect(route('login'));
        }
    }

    public function test_every_web_route_needs_its_own_permission(): void
    {
        $this->enable();
        $employee = $this->employeeOf($this->a);
        $id = $this->report($this->a, ['user_id' => $employee->id])->id;

        $routes = [
            'view' => ['/resignation-report', '/resignation-report/'.$id, '/resignation-report/'.$id.'/pdf', '/resignation-report/'.$id.'/pdf/download'],
            'create' => ['/resignation-report/create'],
            'update' => ['/resignation-report/'.$id.'/edit'],
        ];

        foreach ($routes as $permission => $paths) {
            foreach ($paths as $path) {
                // Un employé ordinaire, y compris celui du rapport, n'a rien.
                $this->actingAs($employee)->get($path)->assertForbidden();
                // Une autre permission ne suffit pas.
                $other = $this->holder(['resignation_reports.'.($permission === 'view' ? 'delete' : 'view')], $this->a);
                $this->actingAs($other)->get($path)->assertForbidden();
                $this->actingAs($this->holder(array_values(array_unique(['resignation_reports.'.$permission, 'resignation_reports.view'])), $this->a))->get($path)->assertOk();
            }
        }
    }

    public function test_update_and_delete_need_view_on_the_report_too(): void
    {
        $this->enable();
        $report = $this->report($this->a);
        $updater = $this->holder(['resignation_reports.update'], $this->a);
        $deleter = $this->holder(['resignation_reports.delete'], $this->a);

        Livewire::actingAs($updater)->test('resignation-report.form', ['report' => $report->id])->assertNotFound();
        Livewire::actingAs($deleter)->test('resignation-report.show', ['report' => $report->id])->assertNotFound();
        $this->assertNotNull($report->fresh());
    }

    // -- Portée magasin et filtres ----------------------------------------------------------------------------------

    public function test_the_index_lists_every_store_for_an_owner_and_filters_by_store_year_month_and_person(): void
    {
        $this->enable();
        $this->report($this->a, ['employee_name' => 'Ana Alpha', 'resignation_date' => '2026-03-10', 'person_in_charge' => 'Boss One']);
        $this->report($this->a, ['employee_name' => 'Old Alpha', 'resignation_date' => '2025-12-01', 'person_in_charge' => 'Boss Two']);
        $this->report($this->b, ['employee_name' => 'Zed Zulu', 'resignation_date' => '2026-03-20', 'person_in_charge' => 'Boss One']);
        $owner = $this->owner();

        Livewire::actingAs($owner)->test('resignation-report.index')->assertSee('Ana Alpha')->assertSee('Old Alpha')->assertSee('Zed Zulu');
        Livewire::actingAs($owner)->test('resignation-report.index')->set('store', (string) $this->b->id)->assertSee('Zed Zulu')->assertDontSee('Ana Alpha');
        Livewire::actingAs($owner)->test('resignation-report.index')->set('year', '2025')->assertSee('Old Alpha')->assertDontSee('Ana Alpha')->assertDontSee('Zed Zulu');
        Livewire::actingAs($owner)->test('resignation-report.index')->set('month', '3')->assertSee('Ana Alpha')->assertSee('Zed Zulu')->assertDontSee('Old Alpha');
        Livewire::actingAs($owner)->test('resignation-report.index')->set('person', 'two')->assertSee('Old Alpha')->assertDontSee('Ana Alpha')->assertDontSee('Zed Zulu');
        Livewire::actingAs($owner)->test('resignation-report.index')->set('year', '2026')->set('month', '3')->set('store', (string) $this->a->id)->set('person', 'Boss One')
            ->assertSee('Ana Alpha')->assertDontSee('Zed Zulu')->assertDontSee('Old Alpha');
    }

    public function test_a_holder_only_sees_the_reports_of_their_stores_and_never_those_of_others(): void
    {
        $this->enable();
        $mine = $this->report($this->a, ['employee_name' => 'Ana Alpha']);
        $foreign = $this->report($this->b, ['employee_name' => 'Zed Zulu']);
        $viewer = $this->holder(['resignation_reports.view'], $this->a);

        Livewire::actingAs($viewer)->test('resignation-report.index')->assertSee('Ana Alpha')->assertDontSee('Zed Zulu');
        // Modifier le filtre de l'URL ne donne pas accès à un autre magasin.
        Livewire::actingAs($viewer)->withQueryParams(['store' => (string) $this->b->id])->test('resignation-report.index')->assertDontSee('Zed Zulu');

        $this->actingAs($viewer)->get('/resignation-report/'.$mine->id)->assertOk();
        $this->actingAs($viewer)->get('/resignation-report/'.$foreign->id)->assertNotFound();
        $this->actingAs($viewer)->get('/resignation-report/'.$foreign->id.'/pdf')->assertNotFound();
        $this->actingAs($viewer)->get('/resignation-report/'.$foreign->id.'/pdf/download')->assertNotFound();

        $all = $this->holder(['resignation_reports.view', 'resignation_reports.update', 'resignation_reports.delete'], $this->a);
        $this->actingAs($all)->get('/resignation-report/'.$foreign->id.'/edit')->assertNotFound();
        Livewire::actingAs($all)->test('resignation-report.show', ['report' => $foreign->id])->assertNotFound();
        Livewire::actingAs($all)->test('resignation-report.form', ['report' => $foreign->id])->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    public function test_a_user_outside_any_store_is_refused(): void
    {
        $this->enable();
        $report = $this->report($this->a);
        $stranger = User::factory()->create();

        foreach (['', '/create', '/'.$report->id, '/'.$report->id.'/edit', '/'.$report->id.'/pdf'] as $path) {
            $this->actingAs($stranger)->get('/resignation-report'.$path)->assertForbidden();
        }
    }

    // -- Écritures ------------------------------------------------------------------------------------------------------

    public function test_a_holder_of_create_creates_a_report(): void
    {
        $this->enable();
        $creator = $this->holder(['resignation_reports.view', 'resignation_reports.create', 'employees.update'], $this->a);
        $ana = $this->employeeOf($this->a);

        Livewire::actingAs($creator)->test('resignation-report.form')
            ->assertSet('values.person_in_charge', 'Holder Person')
            ->set('userId', (string) $ana->id)
            ->set('values.employee_name', 'Ana Member')->set('values.resignation_date', '2026-12-31')
            ->set('values.reason', 'Moving abroad')->set('values.resignation_notice', 'One month')->set('values.notes', 'Secret note')
            ->call('save')->assertHasNoErrors();

        $report = ResignationReport::firstOrFail();
        $this->assertSame($this->a->id, $report->store_id);
        $this->assertSame($ana->id, $report->user_id);
        $this->assertSame($creator->id, $report->created_by);
        $this->assertSame('Ana Member', $report->employee_name);
        $this->assertSame('2026-12-31', $report->resignation_date?->format('Y-m-d'));
        $this->assertSame('Moving abroad', $report->reason);
        $this->assertSame('One month', $report->resignation_notice);
        $this->assertSame('Holder Person', $report->person_in_charge);
    }

    public function test_choosing_an_employee_prefills_number_and_name(): void
    {
        $this->enable();
        $manager = $this->managerOf($this->a);
        $ana = $this->employeeOf($this->a, ['name' => 'Ana Member', 'employee_code' => 'E-77']);
        $foreigner = $this->employeeOf($this->b, ['name' => 'Far Away']);

        Livewire::actingAs($manager)->test('resignation-report.form')->set('userId', (string) $ana->id)
            ->assertSet('values.employee_name', 'Ana Member')->assertSet('values.employee_number', 'E-77');
        Livewire::actingAs($manager)->withQueryParams(['user' => (string) $ana->id])->test('resignation-report.form')
            ->assertSet('userId', (string) $ana->id)->assertSet('values.employee_name', 'Ana Member');
        // Un employé d'un autre magasin n'est jamais repris.
        Livewire::actingAs($manager)->withQueryParams(['user' => (string) $foreigner->id])->test('resignation-report.form')
            ->assertSet('userId', '')->assertSet('values.employee_name', '');
    }

    public function test_creation_is_refused_in_a_store_without_the_permission_and_for_a_foreign_employee(): void
    {
        $this->enable();
        $creator = $this->holder(['resignation_reports.view', 'resignation_reports.create'], $this->a);
        $foreigner = $this->employeeOf($this->b);

        // Le magasin demandé hors périmètre est remplacé par un magasin permis.
        Livewire::actingAs($creator)->withQueryParams(['store' => (string) $this->b->id])->test('resignation-report.form')->assertSet('storeId', $this->a->id);

        // Un identifiant de magasin falsifié est refusé à l'enregistrement.
        Livewire::actingAs($creator)->test('resignation-report.form')->set('storeId', $this->b->id)->set('values.employee_name', 'X')->call('save')->assertForbidden();
        $this->assertSame(0, ResignationReport::count());

        Livewire::actingAs($creator)->test('resignation-report.form')->set('userId', (string) $foreigner->id)->call('save')->assertHasErrors('userId');
        $this->assertSame(0, ResignationReport::count());
    }

    public function test_values_are_validated(): void
    {
        $this->enable();
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('resignation-report.form')
            ->set('values.resignation_date', 'not-a-date')->set('values.reason', str_repeat('x', 5001))
            ->call('save')->assertHasErrors(['values.resignation_date', 'values.reason']);
        $this->assertSame(0, ResignationReport::count());
    }

    public function test_updating_needs_update_and_never_moves_the_report_to_another_store(): void
    {
        $this->enable();
        $report = $this->report($this->a, ['employee_name' => 'Before']);
        $updater = $this->holder(['resignation_reports.view', 'resignation_reports.update'], $this->a);

        Livewire::actingAs($updater)->test('resignation-report.form', ['report' => $report->id])
            ->assertSet('values.employee_name', 'Before')
            ->set('storeId', $this->b->id)->set('values.employee_name', 'After')->call('save');

        $fresh = $report->fresh();
        $this->assertSame('After', $fresh?->employee_name);
        $this->assertSame($this->a->id, $fresh->store_id);

        $this->actingAs($this->holder(['resignation_reports.view'], $this->a))->get('/resignation-report/'.$report->id.'/edit')->assertForbidden();
    }

    public function test_deleting_needs_its_own_permission(): void
    {
        $this->enable();
        $report = $this->report($this->a);

        $viewer = $this->holder(['resignation_reports.view', 'resignation_reports.update'], $this->a);
        Livewire::actingAs($viewer)->test('resignation-report.show', ['report' => $report->id])->assertDontSee('data-test="delete-report-button"', false)->call('delete')->assertForbidden();
        $this->assertNotNull($report->fresh());

        $deleter = $this->holder(['resignation_reports.view', 'resignation_reports.delete'], $this->a);
        Livewire::actingAs($deleter)->test('resignation-report.show', ['report' => $report->id])->assertSee('data-test="delete-report-button"', false)->call('delete');
        $this->assertNull($report->fresh());
    }

    // -- PDF ----------------------------------------------------------------------------------------------------------------

    public function test_the_pdf_is_private_and_generated_on_the_fly(): void
    {
        $this->enable();
        $report = $this->report($this->a, ['employee_name' => 'Ana Member', 'reason' => "Moving\nabroad"]);
        $viewer = $this->holder(['resignation_reports.view'], $this->a);

        $preview = $this->actingAs($viewer)->get('/resignation-report/'.$report->id.'/pdf')->assertOk()->assertSee('Alpha')->assertSee('Ana Member');
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('onclick', (string) $preview->getContent());

        $download = $this->actingAs($viewer)->get('/resignation-report/'.$report->id.'/pdf/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $download->getContent());
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
    }

    // -- Journal ----------------------------------------------------------------------------------------------------------

    public function test_the_activity_log_records_actions_without_any_personal_data(): void
    {
        $this->enable();
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('resignation-report.form')
            ->set('values.employee_name', 'Secret Name')->set('values.reason', 'Secret reason')->set('values.resignation_notice', 'Secret notice')
            ->set('values.notes', 'Secret note')->call('save');
        $report = ResignationReport::firstOrFail();
        Livewire::actingAs($manager)->test('resignation-report.form', ['report' => $report->id])->set('values.reason', 'Secret other reason')->set('values.person_in_charge', 'Secret Boss')->call('save');
        $this->actingAs($manager)->get('/resignation-report/'.$report->id)->assertOk();
        Livewire::actingAs($manager)->test('resignation-report.show', ['report' => $report->id])->call('delete');

        foreach (['created', 'updated', 'viewed', 'deleted'] as $action) {
            $this->assertNotNull(ActivityEntry::where('action', 'resignation_report.'.$action)->first(), $action);
        }

        $log = ActivityEntry::query()->get()->map(fn (ActivityEntry $entry): string => json_encode($entry->properties))->implode('|');
        foreach (['Secret Name', 'Secret reason', 'Secret notice', 'Secret note', 'Secret other reason', 'Secret Boss'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertStringContainsString('reason', $log);
    }

    // -- Anonymisation ----------------------------------------------------------------------------------------------------

    public function test_anonymizing_an_employee_deletes_their_reports_and_keeps_the_others(): void
    {
        $this->enable();
        $ana = $this->employeeOf($this->a);
        $other = $this->employeeOf($this->a);
        $this->report($this->a, ['user_id' => $ana->id]);
        $this->report($this->b, ['user_id' => $ana->id]);
        $kept = $this->report($this->a, ['user_id' => $other->id]);
        $free = $this->report($this->a, ['user_id' => null]);

        app(AnonymizeUser::class)->handle($ana);

        $this->assertSame(0, ResignationReport::where('user_id', $ana->id)->count());
        $this->assertEqualsCanonicalizing([$kept->id, $free->id], ResignationReport::pluck('id')->all());
    }

    // -- Menu ------------------------------------------------------------------------------------------------------------

    public function test_the_menu_entry_follows_the_permission(): void
    {
        $this->enable();
        $keys = fn (User $user): array => array_map(fn ($item) => $item->key, app(NavigationRegistry::class)->visibleTo($user));

        $this->assertContains('resignation-report', $keys($this->managerOf($this->a)));
        $this->assertNotContains('resignation-report', $keys($this->employeeOf($this->a)));
        $this->assertNotContains('resignation-report', $keys($this->holder(['resignation_reports.create'], $this->a)));
    }
}
