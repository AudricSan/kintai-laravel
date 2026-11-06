<?php

namespace Kintai\Bundles\HiringReport\Tests;

use App\Actions\AnonymizeUser;
use App\Actions\Employees\CreateEmployee;
use App\Authorization\Authorizer;
use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\ActivityEntry;
use App\Models\EmployeeProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\HiringReport\HiringReportOnEmployeeCreated;
use Kintai\Bundles\HiringReport\Models\HiringReport;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Events\EmployeeCreated;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Rapports d'embauche : portée magasin, une permission par action sur chaque surface (routes, composants, PDF), journal
 * sans donnée personnelle, création automatique à la création de l'employé, anonymisation, bundle désactivé.
 */
class HiringReportTest extends TestCase
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
        $this->enableBundle('hiring-report');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('hiring-report') ?? throw new LogicException);
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
    private function report(Store $store, array $attributes = []): HiringReport
    {
        return HiringReport::factory()->create(['store_id' => $store->id, ...$attributes]);
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_and_grants_the_manager_defaults_only(): void
    {
        $this->enable();

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $this->assertTrue(app(PermissionRegistry::class)->has('hiring_reports.'.$action));
        }
        $this->assertTrue(app(NavigationRegistry::class)->has('hiring-report'));

        $manager = $this->managerOf($this->a);
        $employee = $this->employeeOf($this->a);
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $this->assertTrue(app(Authorizer::class)->allows($manager, 'hiring_reports.'.$action, $this->a));
            $this->assertFalse(app(Authorizer::class)->allows($manager, 'hiring_reports.'.$action, $this->b));
            $this->assertFalse(app(Authorizer::class)->allows($employee, 'hiring_reports.'.$action, $this->a));
        }
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('hiring_reports.view'));
        $this->assertFalse(app(NavigationRegistry::class)->has('hiring-report'));

        $this->actingAs($this->owner())->get('/hiring-report')->assertNotFound();
        $this->assertTrue(Schema::hasTable('hiring_reports'));
    }

    public function test_a_disabled_bundle_creates_no_report_on_employee_creation(): void
    {
        $manager = $this->owner();
        app(CreateEmployee::class)->handle($manager, ['last_name' => 'Martin', 'first_name' => 'Alice', 'furigana_last_name' => 'マ', 'furigana_first_name' => 'ア', 'store_id' => $this->a->id]);

        $this->assertSame(0, HiringReport::count());
    }

    public function test_the_form_covers_exactly_the_columns_of_the_table(): void
    {
        $columns = array_diff(Schema::getColumnListing('hiring_reports'), ['id', 'store_id', 'user_id', 'created_by', 'created_at', 'updated_at']);

        $this->assertEqualsCanonicalizing(array_values($columns), HiringReport::FIELDS);
        $this->assertEqualsCanonicalizing(HiringReport::FIELDS, array_values(array_diff((new HiringReport)->getFillable(), ['store_id', 'user_id', 'created_by'])));
    }

    // -- Une permission par route ----------------------------------------------------------------------------------

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enable();
        $report = $this->report($this->a);

        foreach (['', '/create', '/'.$report->id, '/'.$report->id.'/edit', '/'.$report->id.'/pdf', '/'.$report->id.'/pdf/download'] as $path) {
            $this->get('/hiring-report'.$path)->assertRedirect(route('login'));
        }
    }

    public function test_every_web_route_needs_its_own_permission(): void
    {
        $this->enable();
        $employee = $this->employeeOf($this->a);
        $id = $this->report($this->a, ['user_id' => $employee->id])->id;

        $routes = [
            'view' => ['/hiring-report', '/hiring-report/'.$id, '/hiring-report/'.$id.'/pdf', '/hiring-report/'.$id.'/pdf/download'],
            'create' => ['/hiring-report/create'],
            'update' => ['/hiring-report/'.$id.'/edit'],
        ];

        foreach ($routes as $permission => $paths) {
            foreach ($paths as $path) {
                // Un employé ordinaire, y compris celui du rapport, n'a rien.
                $this->actingAs($employee)->get($path)->assertForbidden();
                // Une autre permission ne suffit pas.
                $other = $this->holder(['hiring_reports.'.($permission === 'view' ? 'delete' : 'view')], $this->a);
                $this->actingAs($other)->get($path)->assertForbidden();
                $this->actingAs($this->holder(array_values(array_unique(['hiring_reports.'.$permission, 'hiring_reports.view'])), $this->a))->get($path)->assertOk();
            }
        }
    }

    public function test_update_and_delete_need_view_on_the_report_too(): void
    {
        $this->enable();
        $report = $this->report($this->a);
        $updater = $this->holder(['hiring_reports.update'], $this->a);
        $deleter = $this->holder(['hiring_reports.delete'], $this->a);

        Livewire::actingAs($updater)->test('hiring-report.form', ['report' => $report->id])->assertNotFound();
        Livewire::actingAs($deleter)->test('hiring-report.show', ['report' => $report->id])->assertNotFound();
        $this->assertNotNull($report->fresh());
    }

    // -- Portée magasin ----------------------------------------------------------------------------------------------

    public function test_the_index_lists_every_store_for_an_owner_and_filters_by_store_year_and_month(): void
    {
        $this->enable();
        $this->report($this->a, ['employee_name' => 'Ana Alpha', 'hire_date' => '2026-03-10']);
        $this->report($this->a, ['employee_name' => 'Old Alpha', 'hire_date' => '2025-12-01']);
        $this->report($this->b, ['employee_name' => 'Zed Zulu', 'hire_date' => '2026-03-20']);
        $owner = $this->owner();

        Livewire::actingAs($owner)->test('hiring-report.index')->assertSee('Ana Alpha')->assertSee('Old Alpha')->assertSee('Zed Zulu');
        Livewire::actingAs($owner)->test('hiring-report.index')->set('store', (string) $this->b->id)->assertSee('Zed Zulu')->assertDontSee('Ana Alpha');
        Livewire::actingAs($owner)->test('hiring-report.index')->set('year', '2025')->assertSee('Old Alpha')->assertDontSee('Ana Alpha')->assertDontSee('Zed Zulu');
        Livewire::actingAs($owner)->test('hiring-report.index')->set('month', '3')->assertSee('Ana Alpha')->assertSee('Zed Zulu')->assertDontSee('Old Alpha');
        Livewire::actingAs($owner)->test('hiring-report.index')->set('year', '2026')->set('month', '3')->set('store', (string) $this->a->id)
            ->assertSee('Ana Alpha')->assertDontSee('Zed Zulu')->assertDontSee('Old Alpha');
    }

    public function test_a_holder_only_sees_the_reports_of_their_stores_and_never_those_of_others(): void
    {
        $this->enable();
        $mine = $this->report($this->a, ['employee_name' => 'Ana Alpha']);
        $foreign = $this->report($this->b, ['employee_name' => 'Zed Zulu']);
        $viewer = $this->holder(['hiring_reports.view'], $this->a);

        Livewire::actingAs($viewer)->test('hiring-report.index')->assertSee('Ana Alpha')->assertDontSee('Zed Zulu');
        // Modifier le filtre de l'URL ne donne pas accès à un autre magasin.
        Livewire::actingAs($viewer)->withQueryParams(['store' => (string) $this->b->id])->test('hiring-report.index')->assertDontSee('Zed Zulu');

        $this->actingAs($viewer)->get('/hiring-report/'.$mine->id)->assertOk();
        $this->actingAs($viewer)->get('/hiring-report/'.$foreign->id)->assertNotFound();
        $this->actingAs($viewer)->get('/hiring-report/'.$foreign->id.'/pdf')->assertNotFound();
        $this->actingAs($viewer)->get('/hiring-report/'.$foreign->id.'/pdf/download')->assertNotFound();

        $all = $this->holder(['hiring_reports.view', 'hiring_reports.update', 'hiring_reports.delete'], $this->a);
        $this->actingAs($all)->get('/hiring-report/'.$foreign->id.'/edit')->assertNotFound();
        Livewire::actingAs($all)->test('hiring-report.show', ['report' => $foreign->id])->assertNotFound();
        Livewire::actingAs($all)->test('hiring-report.form', ['report' => $foreign->id])->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    public function test_a_user_outside_any_store_is_refused(): void
    {
        $this->enable();
        $report = $this->report($this->a);
        $stranger = User::factory()->create();

        foreach (['', '/create', '/'.$report->id, '/'.$report->id.'/edit', '/'.$report->id.'/pdf'] as $path) {
            $this->actingAs($stranger)->get('/hiring-report'.$path)->assertForbidden();
        }
    }

    // -- Écritures ------------------------------------------------------------------------------------------------------

    public function test_a_holder_of_create_creates_a_report(): void
    {
        $this->enable();
        $creator = $this->holder(['hiring_reports.view', 'hiring_reports.create'], $this->a);
        $ana = $this->employeeOf($this->a);

        Livewire::actingAs($creator)->test('hiring-report.form')
            ->set('userId', (string) $ana->id)
            ->set('values.employee_name', 'Ana Member')->set('values.birth_date', '1990-05-17')->set('values.gender', 'female')
            ->set('values.email', 'ana@example.test')->set('values.notes', 'Secret note')
            ->call('save')->assertHasNoErrors();

        $report = HiringReport::firstOrFail();
        $this->assertSame($this->a->id, $report->store_id);
        $this->assertSame($ana->id, $report->user_id);
        $this->assertSame($creator->id, $report->created_by);
        $this->assertSame('Ana Member', $report->employee_name);
        $this->assertSame('1990-05-17', $report->birth_date?->format('Y-m-d'));
        $this->assertNull($report->phone);
    }

    public function test_creation_is_refused_in_a_store_without_the_permission_and_for_a_foreign_employee(): void
    {
        $this->enable();
        $creator = $this->holder(['hiring_reports.view', 'hiring_reports.create'], $this->a);
        $foreigner = $this->employeeOf($this->b);

        // Le magasin demandé hors périmètre est remplacé par un magasin permis.
        Livewire::actingAs($creator)->withQueryParams(['store' => (string) $this->b->id])->test('hiring-report.form')->assertSet('storeId', $this->a->id);

        // Un identifiant de magasin falsifié est refusé à l'enregistrement.
        Livewire::actingAs($creator)->test('hiring-report.form')->set('storeId', $this->b->id)->set('values.employee_name', 'X')->call('save')->assertForbidden();
        $this->assertSame(0, HiringReport::count());

        Livewire::actingAs($creator)->test('hiring-report.form')->set('userId', (string) $foreigner->id)->call('save')->assertHasErrors('userId');
        $this->assertSame(0, HiringReport::count());
    }

    public function test_one_report_per_employee_and_store(): void
    {
        $this->enable();
        $manager = $this->managerOf($this->a);
        $ana = $this->employeeOf($this->a);
        $this->report($this->a, ['user_id' => $ana->id]);

        Livewire::actingAs($manager)->test('hiring-report.form')->set('userId', (string) $ana->id)->call('save')->assertHasErrors('userId');
        $this->assertSame(1, HiringReport::count());

        // Sans employé lié, plusieurs rapports sont possibles.
        Livewire::actingAs($manager)->test('hiring-report.form')->set('values.employee_name', 'Walk-in')->call('save')->assertHasNoErrors();
        $this->assertSame(2, HiringReport::count());
    }

    public function test_values_are_validated(): void
    {
        $this->enable();
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('hiring-report.form')
            ->set('values.birth_date', 'not-a-date')->set('values.email', 'nope')->set('values.gender', 'robot')
            ->call('save')->assertHasErrors(['values.birth_date', 'values.email', 'values.gender']);
        $this->assertSame(0, HiringReport::count());
    }

    public function test_updating_needs_update_and_never_moves_the_report_to_another_store(): void
    {
        $this->enable();
        $report = $this->report($this->a, ['employee_name' => 'Before']);
        $updater = $this->holder(['hiring_reports.view', 'hiring_reports.update'], $this->a);

        Livewire::actingAs($updater)->test('hiring-report.form', ['report' => $report->id])
            ->assertSet('values.employee_name', 'Before')
            ->set('storeId', $this->b->id)->set('values.employee_name', 'After')->call('save');

        $fresh = $report->fresh();
        $this->assertSame('After', $fresh?->employee_name);
        $this->assertSame($this->a->id, $fresh->store_id);

        $this->actingAs($this->holder(['hiring_reports.view'], $this->a))->get('/hiring-report/'.$report->id.'/edit')->assertForbidden();
    }

    public function test_deleting_needs_its_own_permission(): void
    {
        $this->enable();
        $report = $this->report($this->a);

        $viewer = $this->holder(['hiring_reports.view', 'hiring_reports.update'], $this->a);
        Livewire::actingAs($viewer)->test('hiring-report.show', ['report' => $report->id])->assertDontSee('data-test="delete-report-button"', false)->call('delete')->assertForbidden();
        $this->assertNotNull($report->fresh());

        $deleter = $this->holder(['hiring_reports.view', 'hiring_reports.delete'], $this->a);
        Livewire::actingAs($deleter)->test('hiring-report.show', ['report' => $report->id])->assertSee('data-test="delete-report-button"', false)->call('delete');
        $this->assertNull($report->fresh());
    }

    // -- PDF ----------------------------------------------------------------------------------------------------------------

    public function test_the_pdf_is_private_and_generated_on_the_fly(): void
    {
        $this->enable();
        $report = $this->report($this->a, ['employee_name' => 'Ana Member', 'address' => "1 Main St\nTokyo"]);
        $viewer = $this->holder(['hiring_reports.view'], $this->a);

        $preview = $this->actingAs($viewer)->get('/hiring-report/'.$report->id.'/pdf')->assertOk()->assertSee('Alpha')->assertSee('Ana Member');
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('onclick', (string) $preview->getContent());

        $download = $this->actingAs($viewer)->get('/hiring-report/'.$report->id.'/pdf/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $download->getContent());
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
    }

    // -- Journal ----------------------------------------------------------------------------------------------------------

    public function test_the_activity_log_records_actions_without_any_personal_data(): void
    {
        $this->enable();
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('hiring-report.form')
            ->set('values.employee_name', 'Secret Name')->set('values.phone', '090-7777-1234')->set('values.guarantor_name', 'Secret Guarantor')
            ->set('values.notes', 'Secret note')->call('save');
        $report = HiringReport::firstOrFail();
        Livewire::actingAs($manager)->test('hiring-report.form', ['report' => $report->id])->set('values.address', 'Secret Street 9')->set('values.email', 'secret@example.test')->call('save');
        $this->actingAs($manager)->get('/hiring-report/'.$report->id)->assertOk();
        Livewire::actingAs($manager)->test('hiring-report.show', ['report' => $report->id])->call('delete');

        foreach (['created', 'updated', 'viewed', 'deleted'] as $action) {
            $this->assertNotNull(ActivityEntry::where('action', 'hiring_report.'.$action)->first(), $action);
        }

        $log = ActivityEntry::query()->get()->map(fn (ActivityEntry $entry): string => json_encode($entry->properties))->implode('|');
        foreach (['Secret Name', '090-7777-1234', 'Secret Guarantor', 'Secret note', 'Secret Street 9', 'secret@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertStringContainsString('address', $log);
    }

    // -- Création automatique ---------------------------------------------------------------------------------------

    public function test_creating_an_employee_creates_the_report_from_the_record(): void
    {
        $this->enable();
        $owner = $this->owner();
        $owner->forceFill(['name' => 'Boss Owner'])->save();

        $this->actingAs($owner);
        $user = app(CreateEmployee::class)->handle($owner, [
            'last_name' => 'Martin', 'first_name' => 'Alice', 'furigana_last_name' => 'マルタン', 'furigana_first_name' => 'アリス',
            'employee_code' => 'E-42', 'email' => 'alice@example.test', 'store_id' => $this->a->id,
        ]);

        $report = HiringReport::firstOrFail();
        $this->assertSame($this->a->id, $report->store_id);
        $this->assertSame($user->id, $report->user_id);
        $this->assertSame('E-42', $report->employee_number);
        $this->assertSame($user->name, $report->employee_name);
        $this->assertSame('マルタン', $report->furigana_last_name);
        $this->assertSame('アリス', $report->furigana_first_name);
        $this->assertSame('alice@example.test', $report->email);
        $this->assertSame(now((string) config('kintai.timezone', 'UTC'))->toDateString(), $report->hire_date?->format('Y-m-d'));
        $this->assertSame('Boss Owner', $report->hired_by);
        $this->assertSame($owner->id, $report->created_by);
    }

    public function test_the_report_copies_the_employee_profile(): void
    {
        $this->enable();
        $user = $this->employeeOf($this->a, ['name' => 'Ana Member']);
        EmployeeProfile::factory()->create([
            'user_id' => $user->id, 'gender' => 'female', 'birth_date' => '1990-05-17', 'address' => 'Shibuya 1', 'postal_code' => '150-0001',
            'phone' => '03-1234', 'mobile_phone' => '090-1', 'tax_classification' => 'kou', 'education' => 'Univ', 'guarantor_name' => 'Parent', 'guarantor_phone' => '03-9',
        ]);

        EmployeeCreated::dispatch($user->id, 'form');

        $report = HiringReport::firstOrFail();
        $this->assertSame('female', $report->gender);
        $this->assertSame('1990-05-17', $report->birth_date?->format('Y-m-d'));
        $this->assertSame('Shibuya 1', $report->address);
        $this->assertSame('150-0001', $report->postal_code);
        $this->assertSame('03-1234', $report->phone);
        $this->assertSame('090-1', $report->mobile_phone);
        $this->assertSame('kou', $report->tax_classification);
        $this->assertSame('Univ', $report->education);
        $this->assertSame('Parent', $report->guarantor_name);
        $this->assertSame('03-9', $report->guarantor_phone);
        // Sans utilisateur connecté (import en console) : ni auteur ni « embauché par ».
        $this->assertNull($report->created_by);
        $this->assertNull($report->hired_by);
    }

    public function test_an_employee_without_store_gets_no_report_and_one_report_is_made_per_store(): void
    {
        $this->enable();
        $lonely = User::factory()->create();
        EmployeeCreated::dispatch($lonely->id, 'api');
        $this->assertSame(0, HiringReport::count());

        $both = $this->employeeOf($this->a);
        $this->b->members()->attach($both);
        EmployeeCreated::dispatch($both->id, 'form');

        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], HiringReport::where('user_id', $both->id)->pluck('store_id')->all());

        EmployeeCreated::dispatch(999999, 'form');
        $this->assertSame(2, HiringReport::count());
    }

    public function test_the_automatic_creation_never_duplicates_a_report(): void
    {
        $this->enable();
        $user = $this->employeeOf($this->a);
        $existing = $this->report($this->a, ['user_id' => $user->id, 'employee_name' => 'Kept']);

        EmployeeCreated::dispatch($user->id, 'form');
        app(HiringReportOnEmployeeCreated::class)->handle(new EmployeeCreated($user->id, 'form'));

        $this->assertSame(1, HiringReport::count());
        $this->assertSame('Kept', $existing->fresh()?->employee_name);
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

        $this->assertSame(0, HiringReport::where('user_id', $ana->id)->count());
        $this->assertEqualsCanonicalizing([$kept->id, $free->id], HiringReport::pluck('id')->all());
    }

    // -- Menu ------------------------------------------------------------------------------------------------------------

    public function test_the_menu_entry_follows_the_permission(): void
    {
        $this->enable();
        $keys = fn (User $user): array => array_map(fn ($item) => $item->key, app(NavigationRegistry::class)->visibleTo($user));

        $this->assertContains('hiring-report', $keys($this->managerOf($this->a)));
        $this->assertNotContains('hiring-report', $keys($this->employeeOf($this->a)));
        $this->assertNotContains('hiring-report', $keys($this->holder(['hiring_reports.create'], $this->a)));
    }
}
