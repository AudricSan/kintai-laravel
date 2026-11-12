<?php

namespace Kintai\Bundles\DailyReport\Tests;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\DailyReport\DailyReportAccess;
use Kintai\Bundles\DailyReport\DailyReportMail;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Requests\PendingRequestRegistry;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Rapports journaliers : rédaction, workflow brouillon / soumis / validé, portée magasin, notifications, envoi par e-mail,
 * validation automatique, PDF, réglages, anonymisation, bundle désactivé.
 */
class DailyReportTest extends TestCase
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

    /**
     * Active le bundle comme l'Owner : rejoue le démarrage du provider puis accorde les droits par défaut des rôles.
     */
    private function enable(): void
    {
        $this->enableBundle('daily-report');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('daily-report') ?? throw new LogicException);
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
    private function report(Store $store, ?User $author = null, string $status = 'draft', array $attributes = []): DailyReport
    {
        return DailyReport::create([
            'store_id' => $store->id, 'author_id' => $author?->id, 'report_date' => Carbon::now(config('kintai.timezone'))->toDateString(),
            'sales_total' => 120000, 'customer_count' => 80, 'labor_cost' => 30000, 'waste_total' => 1500, 'status' => $status, ...$attributes,
        ]);
    }

    private function today(): string
    {
        return Carbon::now((string) config('kintai.timezone'))->toDateString();
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_and_grants_the_manager_defaults(): void
    {
        $this->enable();

        foreach (['view', 'create', 'update', 'submit', 'approve', 'delete'] as $action) {
            $this->assertTrue(app(PermissionRegistry::class)->has('daily_reports.'.$action));
        }
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('daily_reports'));
        $this->assertTrue(app(NavigationRegistry::class)->has('daily-report'));
        $this->assertFalse(app(PendingRequestRegistry::class)->isEmpty());
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('daily_report_submitted'));
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('daily_report_validated'));

        $manager = $this->managerOf($this->a);
        $this->assertTrue(app(Authorizer::class)->allows($manager, 'daily_reports.approve', $this->a));
        $this->assertFalse(app(Authorizer::class)->allows($manager, 'daily_reports.delete', $this->a));
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('daily_reports.view'));
        $this->assertFalse(app(NavigationRegistry::class)->has('daily-report'));

        $this->actingAs($this->owner())->get('/daily-report')->assertNotFound();
        $this->assertTrue(Schema::hasTable('daily_reports'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enable();

        $this->get('/daily-report')->assertRedirect(route('login'));
    }

    // -- Rédiger -------------------------------------------------------------------------------------------------

    public function test_a_holder_of_create_writes_the_report_of_the_day_as_a_draft(): void
    {
        $this->enable();
        $author = $this->holder(['daily_reports.create'], $this->a);

        Livewire::actingAs($author)->test('daily-report.form')
            ->set('salesTotal', '125000.50')->set('customerCount', '90')->set('laborCost', '31000')->set('wasteTotal', '0')->set('notes', "  Busy day\nNew menu  ")
            ->call('save')->assertHasNoErrors();

        $report = DailyReport::firstOrFail();
        $this->assertSame([$this->a->id, $author->id, $this->today(), 'draft', 90, "Busy day\nNew menu"], [$report->store_id, $report->author_id, $report->report_date->toDateString(), $report->status, $report->customer_count, $report->notes]);
        $this->assertSame('125000.50', $report->sales_total);

        $entry = ActivityEntry::where('action', 'daily_report.created')->firstOrFail();
        $this->assertStringNotContainsString('Busy day', (string) json_encode($entry->properties));
    }

    public function test_one_report_per_store_and_day_and_values_are_validated(): void
    {
        $this->enable();
        $author = $this->holder(['daily_reports.create'], $this->a);
        $this->report($this->a, $author);

        Livewire::actingAs($author)->test('daily-report.form')->call('save')->assertHasErrors(['reportDate']);

        Livewire::actingAs($author)->test('daily-report.form')
            ->set('reportDate', Carbon::parse($this->today())->addDay()->toDateString())
            ->set('salesTotal', 'abc')->call('save')->assertHasErrors(['salesTotal'])
            ->set('salesTotal', '10')->set('customerCount', '-3')->call('save')->assertHasErrors(['customerCount'])
            ->set('customerCount', '3')->set('laborCost', '-1')->call('save')->assertHasErrors(['laborCost']);

        $this->assertSame(1, DailyReport::count());
    }

    public function test_only_the_owner_can_write_a_report_for_a_past_date(): void
    {
        $this->enable();
        $past = Carbon::parse($this->today())->subDays(3)->toDateString();

        Livewire::actingAs($this->managerOf($this->a))->test('daily-report.form')->set('reportDate', $past)->call('save')->assertHasErrors(['reportDate']);
        $this->assertSame(0, DailyReport::count());

        Livewire::actingAs($this->owner())->test('daily-report.form')->set('storeId', $this->a->id)->set('reportDate', $past)->call('save')->assertHasNoErrors();
        $this->assertSame($past, DailyReport::firstOrFail()->report_date->toDateString());
    }

    public function test_an_employee_without_the_permission_cannot_write_and_a_disabled_store_refuses_everyone(): void
    {
        $this->enable();

        $this->actingAs($this->employeeOf($this->a))->get('/daily-report/create')->assertForbidden();

        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'daily_reports', 'enabled' => false]);
        $manager = $this->managerOf($this->a);
        $this->actingAs($manager)->get('/daily-report/create')->assertForbidden();
        $this->actingAs($manager)->get('/daily-report')->assertNotFound();
    }

    public function test_the_author_edits_their_own_draft_but_not_a_submitted_report_without_update(): void
    {
        $this->enable();
        $author = $this->holder(['daily_reports.create', 'daily_reports.submit'], $this->a);
        $report = $this->report($this->a, $author);

        Livewire::actingAs($author)->test('daily-report.form', ['report' => $report->id])
            ->assertSet('salesTotal', '120000')->set('salesTotal', '99000')->call('save')->assertHasNoErrors();
        $this->assertSame('99000.00', $report->fresh()->sales_total);
        $this->assertNotNull(ActivityEntry::where('action', 'daily_report.updated')->first());

        $report->update(['status' => 'submitted']);
        $this->actingAs($author)->get(route('daily-report.edit', $report->id))->assertForbidden();

        $editor = $this->holder(['daily_reports.update', 'daily_reports.view'], $this->a);
        $this->actingAs($editor)->get(route('daily-report.edit', $report->id))->assertOk();

        $report->update(['status' => 'validated']);
        $this->actingAs($editor)->get(route('daily-report.edit', $report->id))->assertForbidden();
    }

    // -- Portée --------------------------------------------------------------------------------------------------

    public function test_the_list_shows_every_report_of_the_stores_with_view_and_only_the_own_ones_elsewhere(): void
    {
        $this->enable();
        $viewer = $this->holder(['daily_reports.view'], $this->a);
        $colleague = $this->employeeOf($this->a);
        $mine = $this->employeeOf($this->a);
        $this->report($this->a, $colleague);
        $this->report($this->b, $this->employeeOf($this->b), 'draft', ['report_date' => '2020-01-02']);

        Livewire::actingAs($viewer)->test('daily-report.index')->set('from', '2000-01-01')->assertViewHas('reports', fn ($reports): bool => $reports->count() === 1 && $reports->first()->author_id === $colleague->id);

        Livewire::actingAs($mine)->test('daily-report.index')->set('from', '2000-01-01')->assertViewHas('reports', fn ($reports): bool => $reports->isEmpty());

        $own = $this->report($this->a, $mine, 'draft', ['report_date' => '2020-02-02']);
        Livewire::actingAs($mine)->test('daily-report.index')->set('from', '2000-01-01')->assertViewHas('reports', fn ($reports): bool => $reports->modelKeys() === [$own->id]);

        $both = $this->holder(['daily_reports.view'], $this->b);
        $this->a->members()->attach($both);
        Livewire::actingAs($both)->test('daily-report.index')->set('from', '2000-01-01')->assertSee('Alpha')->assertSee('Zulu')
            ->assertViewHas('reports', fn ($reports): bool => $reports->count() === 1);
    }

    public function test_a_report_out_of_scope_is_not_found(): void
    {
        $this->enable();
        $author = $this->employeeOf($this->b);
        $report = $this->report($this->b, $author);

        $this->actingAs($this->holder(['daily_reports.view'], $this->a))->get(route('daily-report.show', $report->id))->assertNotFound();
        $this->actingAs($this->employeeOf($this->a))->get(route('daily-report.show', $report->id))->assertNotFound();
        $this->actingAs($this->employeeOf($this->a))->get(route('daily-report.pdf', $report->id))->assertNotFound();
        $this->actingAs($this->employeeOf($this->b))->get(route('daily-report.show', $report->id))->assertNotFound();
        $this->actingAs($author)->get(route('daily-report.show', $report->id))->assertOk();
    }

    public function test_the_list_shows_days_without_report_after_the_configured_time(): void
    {
        $this->enable();
        $manager = $this->managerOf($this->a);
        Carbon::setTestNow(Carbon::parse('2026-11-10 20:00', config('kintai.timezone')));

        Livewire::actingAs($manager)->test('daily-report.index')->set('store', (string) $this->a->id)->set('from', '2026-11-08')
            ->assertSeeHtml('data-test="report-gap-row"')->assertSee('2026-11-10');

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', config('kintai.timezone')));
        Livewire::actingAs($manager)->test('daily-report.index')->set('store', (string) $this->a->id)->set('from', '2026-11-10')
            ->assertDontSeeHtml('data-test="report-gap-row"');
        Carbon::setTestNow();
    }

    public function test_the_reminder_shows_between_the_reminder_and_the_no_report_times(): void
    {
        $this->enable();
        $manager = $this->managerOf($this->a);

        Carbon::setTestNow(Carbon::parse('2026-11-10 16:00', config('kintai.timezone')));
        Livewire::actingAs($manager)->test('daily-report.index')->set('store', (string) $this->a->id)->assertSeeHtml('data-test="reminder-alert"');

        $this->report($this->a, $manager, 'draft', ['report_date' => '2026-11-10']);
        Livewire::actingAs($manager)->test('daily-report.index')->set('store', (string) $this->a->id)->assertDontSeeHtml('data-test="reminder-alert"');
        Carbon::setTestNow();
    }

    public function test_the_running_total_is_computed_for_cumulative_system_stores(): void
    {
        $this->enable();
        DailyReportSetting::forStore($this->a->id)->fill(['cumulative_mode' => 'cumulative_system'])->save();
        $this->report($this->a, null, 'draft', ['report_date' => '2026-11-01', 'sales_total' => 1000]);
        $this->report($this->a, null, 'draft', ['report_date' => '2026-11-02', 'sales_total' => 500]);

        Livewire::actingAs($this->managerOf($this->a))->test('daily-report.index')->set('store', (string) $this->a->id)->set('from', '2026-11-01')->set('status', 'draft')
            ->assertSee('Running total')->assertSee('1.500,00');
    }

    // -- Workflow ------------------------------------------------------------------------------------------------

    public function test_submitting_notifies_the_validators_of_the_store_except_the_author(): void
    {
        $this->enable();
        $author = $this->holder(['daily_reports.create', 'daily_reports.submit', 'daily_reports.approve'], $this->a);
        $validator = $this->holder(['daily_reports.approve'], $this->a);
        $viewer = $this->holder(['daily_reports.view'], $this->a);
        $other = $this->holder(['daily_reports.approve'], $this->b);
        $report = $this->report($this->a, $author);

        Livewire::actingAs($author)->test('daily-report.show', ['report' => $report->id])->call('submit')->assertHasNoErrors();

        $this->assertSame('submitted', $report->fresh()->status);
        $this->assertNotNull($report->fresh()->submitted_at);
        $this->assertSame(1, $validator->notifications()->count());
        $this->assertSame('daily_report_submitted', $validator->notifications()->first()->type);
        $this->assertSame(0, $author->notifications()->count());
        $this->assertSame(0, $viewer->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $this->assertNotNull(ActivityEntry::where('action', 'daily_report.submitted')->first());
    }

    public function test_submitting_needs_the_submit_permission_and_a_draft(): void
    {
        $this->enable();
        $author = $this->holder(['daily_reports.create'], $this->a);
        $report = $this->report($this->a, $author);

        Livewire::actingAs($author)->test('daily-report.show', ['report' => $report->id])->call('submit')->assertForbidden();
        $this->assertSame('draft', $report->fresh()->status);

        $submitter = $this->holder(['daily_reports.view', 'daily_reports.submit'], $this->a);
        $report->update(['status' => 'submitted']);
        Livewire::actingAs($submitter)->test('daily-report.show', ['report' => $report->id])->call('submit')->assertHasErrors(['report']);
    }

    public function test_validating_notifies_the_author_and_locks_the_report(): void
    {
        $this->enable();
        Mail::fake();
        $author = $this->holder(['daily_reports.create'], $this->a);
        $validator = $this->holder(['daily_reports.approve', 'daily_reports.view'], $this->a);
        $report = $this->report($this->a, $author, 'submitted');

        Livewire::actingAs($validator)->test('daily-report.show', ['report' => $report->id])->call('validateReport')->assertHasNoErrors();

        $fresh = $report->fresh();
        $this->assertSame(['validated', $validator->id], [$fresh->status, $fresh->validated_by]);
        $this->assertNotNull($fresh->validated_at);
        $this->assertSame('daily_report_validated', $author->notifications()->first()->type);
        $this->assertSame(0, $validator->notifications()->count());
        Mail::assertNothingSent();

        Livewire::actingAs($validator)->test('daily-report.show', ['report' => $report->id])->call('validateReport')->assertHasErrors(['report']);
    }

    public function test_validating_needs_approve_and_a_submitted_report(): void
    {
        $this->enable();
        $viewer = $this->holder(['daily_reports.view', 'daily_reports.submit'], $this->a);
        $report = $this->report($this->a, null, 'submitted');

        Livewire::actingAs($viewer)->test('daily-report.show', ['report' => $report->id])->call('validateReport')->assertForbidden();
        $this->assertSame('submitted', $report->fresh()->status);

        $validator = $this->holder(['daily_reports.approve', 'daily_reports.view'], $this->a);
        $draft = $this->report($this->a, null, 'draft', ['report_date' => '2020-05-05']);
        Livewire::actingAs($validator)->test('daily-report.show', ['report' => $draft->id])->call('validateReport')->assertHasErrors(['report']);
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_opening_a_report_marks_its_notification_as_read(): void
    {
        $this->enable();
        $author = $this->holder(['daily_reports.create', 'daily_reports.submit'], $this->a);
        $validator = $this->holder(['daily_reports.approve', 'daily_reports.view'], $this->a);
        $report = $this->report($this->a, $author);
        Livewire::actingAs($author)->test('daily-report.show', ['report' => $report->id])->call('submit');
        $this->assertSame(1, $validator->unreadNotifications()->count());

        $this->actingAs($validator)->get(route('daily-report.show', $report->id))->assertOk();

        $this->assertSame(0, $validator->unreadNotifications()->count());
    }

    public function test_the_pending_count_is_the_submitted_reports_the_user_can_validate(): void
    {
        $this->enable();
        $validator = $this->holder(['daily_reports.approve'], $this->a);
        $this->report($this->a, null, 'submitted');
        $this->report($this->a, null, 'draft', ['report_date' => '2020-01-01']);
        $this->report($this->b, null, 'submitted');

        $access = app(DailyReportAccess::class);

        $this->assertSame(1, $access->toValidate($validator)->count());
        $this->assertSame(0, $access->toValidate($this->employeeOf($this->a))->count());
    }

    public function test_deleting_needs_the_delete_permission_and_is_final(): void
    {
        $this->enable();
        $report = $this->report($this->a, null, 'validated');

        Livewire::actingAs($this->managerOf($this->a))->test('daily-report.show', ['report' => $report->id])->assertDontSeeHtml('data-test="delete-report-button"')->call('delete')->assertForbidden();
        $this->assertSame(1, DailyReport::count());

        Livewire::actingAs($this->owner())->test('daily-report.show', ['report' => $report->id])->assertSeeHtml('data-test="delete-report-button"')->call('delete')->assertRedirect();
        $this->assertSame(0, DailyReport::count());
        $this->assertNotNull(ActivityEntry::where('action', 'daily_report.deleted')->first());
    }

    // -- Envoi par e-mail ----------------------------------------------------------------------------------------

    public function test_the_validated_report_is_mailed_to_each_recipient_in_their_language_with_the_pdf(): void
    {
        $this->enable();
        Mail::fake();
        $ja = User::factory()->create(['locale' => 'ja', 'email' => 'ja@example.test']);
        $fr = User::factory()->create(['locale' => 'fr', 'email' => 'fr@example.test']);
        $inactive = User::factory()->create(['email' => 'off@example.test', 'is_active' => false]);
        $outsider = User::factory()->create(['email' => 'out@example.test']);
        foreach ([$ja, $fr, $inactive] as $user) {
            $this->a->members()->attach($user);
        }
        DailyReportSetting::forStore($this->a->id)->fill(['mail_recipient_ids' => [$ja->id, $fr->id, $inactive->id, $outsider->id]])->save();
        $report = $this->report($this->a, null, 'validated');

        Livewire::actingAs($this->managerOf($this->a))->test('daily-report.show', ['report' => $report->id])->call('sendMail')->assertHasNoErrors();

        Mail::assertSent(DailyReportMail::class, 2);
        Mail::assertSent(DailyReportMail::class, fn (DailyReportMail $mail): bool => $mail->hasTo('ja@example.test') && $mail->locale === 'ja' && str_starts_with((string) $mail->pdf, '%PDF'));
        Mail::assertSent(DailyReportMail::class, fn (DailyReportMail $mail): bool => $mail->hasTo('fr@example.test') && $mail->locale === 'fr');
        Mail::assertNotSent(DailyReportMail::class, fn (DailyReportMail $mail): bool => $mail->hasTo('off@example.test') || $mail->hasTo('out@example.test'));
        $this->assertNotNull($report->fresh()->mail_sent_at);
        $this->assertNotNull(ActivityEntry::where('action', 'daily_report.mail_sent')->first());
    }

    public function test_sending_without_recipient_or_before_validation_is_refused(): void
    {
        $this->enable();
        Mail::fake();
        $manager = $this->managerOf($this->a);
        $report = $this->report($this->a, null, 'validated');

        Livewire::actingAs($manager)->test('daily-report.show', ['report' => $report->id])->assertSeeHtml('data-test="no-recipients-alert"')->call('sendMail')->assertHasErrors(['report']);

        $submitted = $this->report($this->a, null, 'submitted', ['report_date' => '2020-03-03']);
        Livewire::actingAs($manager)->test('daily-report.show', ['report' => $submitted->id])->assertDontSeeHtml('data-test="send-mail-button"')->call('sendMail')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_an_employee_without_approve_cannot_send_the_mail(): void
    {
        $this->enable();
        Mail::fake();
        $report = $this->report($this->a, null, 'validated');
        $author = $this->holder(['daily_reports.view'], $this->a);

        Livewire::actingAs($author)->test('daily-report.show', ['report' => $report->id])->call('sendMail')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_the_report_is_mailed_automatically_on_validation_when_the_store_asks_for_it(): void
    {
        $this->enable();
        Mail::fake();
        $recipient = $this->employeeOf($this->a, ['email' => 'boss@example.test']);
        DailyReportSetting::forStore($this->a->id)->fill(['auto_send_on_validate' => true, 'mail_recipient_ids' => [$recipient->id]])->save();
        $report = $this->report($this->a, null, 'submitted');

        Livewire::actingAs($this->managerOf($this->a))->test('daily-report.show', ['report' => $report->id])->call('validateReport');

        Mail::assertSent(DailyReportMail::class, 1);
        $this->assertNotNull($report->fresh()->mail_sent_at);
    }

    // -- Validation automatique ----------------------------------------------------------------------------------

    public function test_the_command_validates_the_report_of_yesterday_in_the_stores_whose_hour_it_is(): void
    {
        $this->enable();
        Mail::fake();
        $yesterday = Carbon::now((string) config('kintai.timezone'))->subDay()->toDateString();
        $hour = Carbon::now((string) config('kintai.timezone'))->format('H');
        $otherHour = $hour === '03' ? '04' : '03';
        DailyReportSetting::forStore($this->a->id)->fill(['auto_validate_time' => $hour.':00'])->save();
        DailyReportSetting::forStore($this->b->id)->fill(['auto_validate_time' => $otherHour.':00'])->save();
        $due = $this->report($this->a, null, 'draft', ['report_date' => $yesterday]);
        $later = $this->report($this->b, null, 'submitted', ['report_date' => $yesterday]);

        $this->artisan('kintai:auto-validate')->assertSuccessful();

        $fresh = $due->fresh();
        $this->assertSame(['validated', null], [$fresh->status, $fresh->validated_by]);
        $this->assertNotNull($fresh->validated_at);
        $this->assertSame('submitted', $later->fresh()->status);
        $this->assertNotNull(ActivityEntry::where('action', 'daily_report.auto_validated')->whereNull('actor_id')->first());

        $this->artisan('kintai:auto-validate --all')->assertSuccessful();
        $this->assertSame('validated', $later->fresh()->status);
    }

    public function test_the_command_skips_stores_without_the_setting_validated_reports_and_a_disabled_bundle(): void
    {
        $this->enable();
        $date = '2026-11-01';
        $unset = $this->report($this->a, null, 'draft', ['report_date' => $date]);
        DailyReportSetting::forStore($this->b->id)->fill(['auto_validate_time' => '03:00'])->save();
        $validated = $this->report($this->b, null, 'validated', ['report_date' => $date, 'validated_at' => '2026-11-02 08:00:00']);

        $this->artisan("kintai:auto-validate --date={$date} --all")->assertSuccessful();

        $this->assertSame('draft', $unset->fresh()->status);
        $this->assertSame('2026-11-02 08:00:00', $validated->fresh()->validated_at?->format('Y-m-d H:i:s'));
    }

    // -- PDF -----------------------------------------------------------------------------------------------------

    public function test_the_pdf_preview_and_download_are_reserved_to_who_can_open_the_report(): void
    {
        $this->enable();
        $author = $this->employeeOf($this->a, ['locale' => 'fr']);
        $report = $this->report($this->a, $author, 'validated', ['notes' => 'Calm evening']);

        $this->actingAs($author)->get(route('daily-report.pdf', $report->id))->assertOk()->assertSee('Calm evening')->assertSee('Alpha')->assertSee(route('daily-report.pdf.download', $report->id), false);

        $download = $this->actingAs($author)->get(route('daily-report.pdf.download', $report->id))->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $download->getContent());
        $this->assertStringContainsString('daily-report-', (string) $download->headers->get('Content-Disposition'));
        $this->assertNotNull(ActivityEntry::where('action', 'daily_report.pdf_exported')->first());

        $this->actingAs($this->employeeOf($this->a))->get(route('daily-report.pdf.download', $report->id))->assertNotFound();
    }

    public function test_the_next_day_schedule_is_shown_with_the_report_and_only_members_count(): void
    {
        $this->enable();
        $type = ShiftType::factory()->create(['name' => 'Morning']);
        $tomorrow = Carbon::parse($this->today())->addDay()->toDateString();
        $member = $this->employeeOf($this->a, ['name' => 'Ana Member']);
        $stranger = User::factory()->create(['name' => 'Zed Stranger']);
        foreach ([$member, $stranger] as $user) {
            Shift::fromLocalTimes($tomorrow, '09:00', '17:00', ['store_id' => $this->a->id, 'user_id' => $user->id, 'shift_type_id' => $type->id, 'pause_minutes' => 60])->save();
        }
        $report = $this->report($this->a, null, 'draft');

        Livewire::actingAs($this->managerOf($this->a))->test('daily-report.show', ['report' => $report->id])
            ->assertSee('Ana Member')->assertSee('Morning')->assertSee('09:00-17:00')->assertSee('8h00')->assertSee('7h00')->assertDontSee('Zed Stranger');
    }

    // -- Réglages ------------------------------------------------------------------------------------------------

    public function test_a_holder_of_update_saves_the_settings_of_the_store(): void
    {
        $this->enable();
        $manager = $this->holder(['daily_reports.update'], $this->a);
        $recipient = $this->employeeOf($this->a, ['email' => 'r@example.test']);

        Livewire::actingAs($manager)->test('daily-report.settings')
            ->assertSet('store', (string) $this->a->id)
            ->set('recipients', [$recipient->id])->set('autoSend', true)->set('autoValidateTime', '02:30')->set('reminderTime', '14:00')->set('noReportTime', '19:00')
            ->set('columns', ['employee', 'schedule'])->set('mode', 'cumulative_input')
            ->call('save')->assertHasNoErrors();

        $setting = DailyReportSetting::forStore($this->a->id);
        $this->assertSame([[$recipient->id], true, '02:30', '14:00', '19:00', ['employee', 'schedule'], 'cumulative_input'], [
            $setting->recipientIds(), $setting->auto_send_on_validate, $setting->auto_validate_time, $setting->reminder_time, $setting->no_report_time, $setting->columns(), $setting->cumulative_mode,
        ]);
        $this->assertNotNull(ActivityEntry::where('action', 'daily_report.settings_updated')->first());
    }

    public function test_settings_are_validated_and_recipients_must_belong_to_the_store(): void
    {
        $this->enable();
        $manager = $this->holder(['daily_reports.update'], $this->a);
        $outsider = $this->employeeOf($this->b, ['email' => 'o@example.test']);

        Livewire::actingAs($manager)->test('daily-report.settings')
            ->set('recipients', [$outsider->id])->call('save')->assertHasErrors(['recipients'])
            ->set('recipients', [])->set('reminderTime', '20:00')->set('noReportTime', '18:00')->call('save')->assertHasErrors(['noReportTime'])
            ->set('noReportTime', '21:00')->set('mode', 'bogus')->call('save')->assertHasErrors(['mode'])
            ->set('mode', 'per_day')->set('autoValidateTime', '25:99')->call('save')->assertHasErrors(['autoValidateTime']);

        $this->assertFalse(DailyReportSetting::query()->exists());
    }

    public function test_settings_are_reserved_to_the_stores_where_the_user_holds_update(): void
    {
        $this->enable();

        $this->actingAs($this->employeeOf($this->a))->get('/daily-report/settings')->assertForbidden();
        $this->actingAs($this->holder(['daily_reports.view'], $this->a))->get('/daily-report/settings')->assertForbidden();

        $manager = $this->holder(['daily_reports.update'], $this->a);
        Livewire::actingAs($manager)->test('daily-report.settings')->set('store', (string) $this->b->id)->assertForbidden();
    }

    // -- Données personnelles ------------------------------------------------------------------------------------

    public function test_anonymizing_an_author_erases_the_notes_and_keeps_the_figures(): void
    {
        $this->enable();
        $author = $this->employeeOf($this->a);
        $report = $this->report($this->a, $author, 'validated', ['notes' => 'Call Mr Tanaka']);
        $other = $this->report($this->a, $this->employeeOf($this->a), 'draft', ['report_date' => '2020-04-04', 'notes' => 'Keep me']);

        app(AnonymizeUser::class)->handle($author);

        $this->assertNull($report->fresh()->notes);
        $this->assertSame('120000.00', $report->fresh()->sales_total);
        $this->assertSame('Keep me', $other->fresh()->notes);
    }

    public function test_deleting_the_author_keeps_the_report(): void
    {
        $this->enable();
        $author = $this->employeeOf($this->a);
        $report = $this->report($this->a, $author);

        $author->forceDelete();

        $this->assertNull($report->fresh()->author_id);
    }

    public function test_the_widget_lists_the_reports_to_validate(): void
    {
        $this->enable();
        $this->report($this->a, null, 'submitted');

        Livewire::actingAs($this->managerOf($this->a))->test('daily-report.manager-widget')->assertSeeHtml('data-test="widget-report-row"')->assertSee('Alpha');
        Livewire::actingAs($this->managerOf($this->b))->test('daily-report.manager-widget')->assertSeeHtml('data-test="widget-no-reports"');
    }
}
