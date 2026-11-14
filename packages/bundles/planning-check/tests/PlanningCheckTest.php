<?php

namespace Kintai\Bundles\PlanningCheck\Tests;

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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\PlanningCheck\DaySchedule;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheck;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSend;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Kintai\Bundles\PlanningCheck\PlanningCheckAccess;
use Kintai\Bundles\PlanningCheck\PlanningCheckActions;
use Kintai\Bundles\PlanningCheck\PlanningCheckPolicy;
use Kintai\Bundles\PlanningCheck\PlanningMail;
use Kintai\Bundles\PlanningCheck\PlanningMailer;
use Kintai\Bundles\PlanningCheck\PlanningPdf;
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
 * Validation du planning de demain : soumission, validation et envoi du PDF, refus, renvoi, « envoyer maintenant »,
 * réouverture, escalade planifiée, réglages, PDF, widget, portée magasin, bundle désactivé.
 *
 * L'heure est figée au mardi 2026-11-10 09:00 (fuseau de l'instance) : la fenêtre (06:00) est ouverte, l'heure limite
 * (12:00) pas atteinte, et le jour visé est le mercredi 2026-11-11.
 */
class PlanningCheckTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private const TOMORROW = '2026-11-11';

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Zulu']);
        $this->at('09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Fige l'heure locale du mardi 2026-11-10.
     */
    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 '.$time.':00', (string) config('kintai.timezone')));
    }

    /**
     * Active le bundle comme l'Owner : rejoue le démarrage du provider puis accorde les droits par défaut des rôles.
     */
    private function enable(): void
    {
        $this->enableBundle('planning-check');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('planning-check') ?? throw new LogicException);
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
     * Active la validation d'un magasin avec les destinataires donnés (membres du magasin, avec adresse).
     *
     * @param  list<User>  $recipients
     * @param  array<string, mixed>  $attributes
     */
    private function configure(Store $store, array $recipients = [], array $attributes = []): PlanningCheckSetting
    {
        foreach ($recipients as $recipient) {
            $store->members()->syncWithoutDetaching([$recipient->id]);
        }

        $setting = PlanningCheckSetting::query()->firstOrNew(['store_id' => $store->id], PlanningCheckSetting::DEFAULTS);
        $setting->fill(['enabled' => true, 'mail_recipient_ids' => array_map(fn (User $user): int => $user->id, $recipients), ...$attributes])->save();

        return $setting;
    }

    private function recipient(Store $store, string $email = 'boss@example.test', string $locale = 'fr'): User
    {
        $user = User::factory()->create(['email' => $email, 'locale' => $locale]);
        $store->members()->attach($user);

        return $user;
    }

    private function shift(Store $store, ?User $user, string $start = '09:00', string $end = '17:00', ?string $date = null): Shift
    {
        $type = ShiftType::query()->where('name', 'Morning')->first() ?? ShiftType::factory()->create(['name' => 'Morning']);
        $shift = Shift::fromLocalTimes($date ?? self::TOMORROW, $start, $end, ['store_id' => $store->id, 'user_id' => $user?->id, 'shift_type_id' => $type->id, 'pause_minutes' => 60]);
        $shift->save();

        return $shift;
    }

    private function check(Store $store, string $status = 'pending', array $attributes = []): PlanningCheck
    {
        return PlanningCheck::create(['store_id' => $store->id, 'target_date' => self::TOMORROW, 'status' => $status, ...$attributes]);
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_and_grants_the_manager_defaults(): void
    {
        $this->enable();

        foreach (['view', 'submit', 'approve', 'settings'] as $action) {
            $this->assertTrue(app(PermissionRegistry::class)->has('planning_check.'.$action));
        }
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('planning_check'));
        $this->assertTrue(app(NavigationRegistry::class)->has('planning-check'));
        $this->assertFalse(app(PendingRequestRegistry::class)->isEmpty());
        foreach (['submitted', 'validated', 'rejected', 'overdue', 'failed'] as $type) {
            $this->assertTrue(app(NotificationTypeRegistry::class)->has('planning_check_'.$type));
        }

        $manager = $this->managerOf($this->a);
        $this->assertTrue(app(Authorizer::class)->allows($manager, 'planning_check.approve', $this->a));
        $this->assertTrue(app(Authorizer::class)->allows($manager, 'planning_check.submit', $this->a));
        $this->assertFalse(app(Authorizer::class)->allows($manager, 'planning_check.settings', $this->a));
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('planning_check.view'));
        $this->assertFalse(app(NavigationRegistry::class)->has('planning-check'));

        $this->actingAs($this->owner())->get('/planning-check')->assertNotFound();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enable();

        $this->get('/planning-check')->assertRedirect(route('login'));
    }

    public function test_the_screen_is_reserved_to_the_stores_where_the_user_holds_a_right(): void
    {
        $this->enable();
        $this->configure($this->a);

        $this->actingAs($this->employeeOf($this->a))->get('/planning-check')->assertNotFound();

        $viewer = $this->holder(['planning_check.view'], $this->a);
        $this->actingAs($viewer)->get('/planning-check')->assertOk()->assertSee('Shifts of '.self::TOMORROW);
        // Un magasin dont la fonctionnalité est coupée disparaît.
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'planning_check', 'enabled' => false]);
        $this->actingAs($viewer)->get('/planning-check')->assertNotFound();
    }

    public function test_a_store_outside_the_scope_cannot_be_selected(): void
    {
        $this->enable();
        $this->configure($this->a);
        $this->configure($this->b);

        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('planning-check.index', ['store' => (string) $this->b->id])->assertSet('store', (string) $this->a->id);
        $this->assertSame(1, count(app(PlanningCheckAccess::class)->visibleStores($manager)));
    }

    // -- Soumettre -----------------------------------------------------------------------------------------------

    public function test_the_manager_submits_the_schedule_and_the_approvers_are_notified_except_the_submitter(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $employee = $this->employeeOf($this->a);
        $this->shift($this->a, $employee);
        $submitter = $this->holder(['planning_check.submit', 'planning_check.approve'], $this->a);
        $validator = $this->holder(['planning_check.approve'], $this->a);
        $viewer = $this->holder(['planning_check.view'], $this->a);
        $other = $this->holder(['planning_check.approve'], $this->b);
        $owner = $this->owner();

        Livewire::actingAs($submitter)->test('planning-check.index')->call('submit')->assertHasNoErrors();

        $check = PlanningCheck::firstOrFail();
        $this->assertSame([$this->a->id, self::TOMORROW, 'submitted', $submitter->id, false], [$check->store_id, $check->target_date->toDateString(), $check->status, $check->submitted_by, $check->confirmed_empty]);
        $this->assertNotSame('', $check->shifts_hash);
        $this->assertSame(1, $validator->notifications()->count());
        $this->assertSame('planning_check_submitted', $validator->notifications()->first()->type);
        $this->assertSame(1, $owner->notifications()->count());
        foreach ([$submitter, $viewer, $other] as $user) {
            $this->assertSame(0, $user->notifications()->count());
        }
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.submitted')->first());
    }

    public function test_an_empty_day_needs_an_explicit_confirmation(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('planning-check.index')->call('submit')->assertHasErrors(['check']);
        $this->assertNotSame('submitted', PlanningCheck::query()->value('status'));

        Livewire::actingAs($manager)->test('planning-check.index')->set('confirmEmpty', true)->call('submit')->assertHasNoErrors();
        $check = PlanningCheck::firstOrFail();
        $this->assertSame(['submitted', true], [$check->status, $check->confirmed_empty]);
    }

    public function test_submitting_twice_or_without_the_right_or_when_disabled_is_refused(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a));
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('planning-check.index')->call('submit')->assertHasNoErrors();
        Livewire::actingAs($manager)->test('planning-check.index')->call('submit')->assertHasErrors(['check']);

        $viewer = $this->holder(['planning_check.view'], $this->a);
        Livewire::actingAs($viewer)->test('planning-check.index')->call('submit')->assertForbidden();

        PlanningCheckSetting::query()->firstOrNew(['store_id' => $this->a->id], PlanningCheckSetting::DEFAULTS)->fill(['enabled' => false])->save();
        PlanningCheck::query()->delete();
        Livewire::actingAs($manager)->test('planning-check.index')->call('submit')->assertHasErrors(['check']);
        $this->assertSame(0, PlanningCheck::count());
    }

    public function test_the_target_day_is_always_tomorrow_whatever_the_caller_sends(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a), date: '2026-11-10');
        $this->shift($this->a, $this->employeeOf($this->a));

        app(PlanningCheckActions::class)->submit($this->managerOf($this->a), $this->a->id);

        $this->assertSame([self::TOMORROW], PlanningCheck::all()->map(fn (PlanningCheck $check): string => $check->target_date->toDateString())->all());
    }

    // -- Valider et envoyer ---------------------------------------------------------------------------------------

    public function test_approving_mails_the_pdf_to_each_valid_recipient_in_their_language(): void
    {
        $this->enable();
        Mail::fake();
        $fr = $this->recipient($this->a, 'fr@example.test', 'fr');
        $ja = $this->recipient($this->a, 'ja@example.test', 'ja');
        $inactive = $this->recipient($this->a, 'off@example.test');
        $inactive->forceFill(['is_active' => false])->save();
        $outsider = User::factory()->create(['email' => 'out@example.test']);
        $this->configure($this->a, [$fr, $ja, $inactive]);
        PlanningCheckSetting::query()->firstOrNew(['store_id' => $this->a->id], PlanningCheckSetting::DEFAULTS)->fill(['mail_recipient_ids' => [$fr->id, $ja->id, $inactive->id, $outsider->id]])->save();
        $this->shift($this->a, $this->employeeOf($this->a));

        $submitter = $this->managerOf($this->a);
        $validator = $this->holder(['planning_check.approve'], $this->a, 'Val Idator');
        app(PlanningCheckActions::class)->submit($submitter, $this->a->id);
        $check = PlanningCheck::firstOrFail();

        Livewire::actingAs($validator)->test('planning-check.index')->call('confirm', 'approve', $check->id)->call('approve')->assertHasNoErrors();

        Mail::assertSent(PlanningMail::class, 2);
        Mail::assertSent(PlanningMail::class, fn (PlanningMail $mail): bool => $mail->hasTo('fr@example.test') && $mail->locale === 'fr' && str_starts_with($mail->pdf, '%PDF'));
        Mail::assertSent(PlanningMail::class, fn (PlanningMail $mail): bool => $mail->hasTo('ja@example.test') && $mail->locale === 'ja');
        Mail::assertNotSent(PlanningMail::class, fn (PlanningMail $mail): bool => $mail->hasTo('off@example.test') || $mail->hasTo('out@example.test'));

        $check->refresh();
        $this->assertSame(['validated', $validator->id, 'sent', null], [$check->status, $check->validated_by, $check->send_status, $check->last_error]);
        $this->assertNotNull($check->sent_at);
        $send = PlanningCheckSend::firstOrFail();
        $this->assertSame([$check->id, 'sent', 2, $validator->id], [$send->planning_check_id, $send->status, $send->recipient_count, $send->sent_by]);
        $this->assertSame($check->shifts_hash, $send->shifts_hash);
        $this->assertSame('planning_check_validated', $submitter->notifications()->first()->type);
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.validated')->first());
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.sent')->first());
    }

    public function test_approving_without_a_valid_recipient_validates_nothing(): void
    {
        $this->enable();
        Mail::fake();
        $this->configure($this->a);
        $check = $this->check($this->a, 'submitted', ['submitted_by' => $this->managerOf($this->a)->id]);

        Livewire::actingAs($this->managerOf($this->a))->test('planning-check.index')->call('confirm', 'approve', $check->id)->call('approve')->assertHasErrors(['check']);

        $this->assertSame('submitted', $check->fresh()->status);
        Mail::assertNothingSent();
        $this->assertSame(0, PlanningCheckSend::count());
    }

    public function test_approving_needs_the_right_the_store_scope_and_a_submitted_check(): void
    {
        $this->enable();
        Mail::fake();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->configure($this->b, [$this->recipient($this->b, 'b@example.test')]);
        $actions = app(PlanningCheckActions::class);

        $submitted = $this->check($this->a, 'submitted');
        $this->expectsAuthorization(fn () => $actions->approve($this->holder(['planning_check.submit'], $this->a), $submitted));
        $this->expectsAuthorization(fn () => $actions->approve($this->managerOf($this->b), $submitted));
        $this->assertSame('submitted', $submitted->fresh()->status);

        $pending = PlanningCheck::create(['store_id' => $this->b->id, 'target_date' => self::TOMORROW, 'status' => 'pending']);
        try {
            $actions->approve($this->managerOf($this->b), $pending);
            $this->fail('A pending check cannot be approved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('check', $e->errors());
        }
        Mail::assertNothingSent();
    }

    private function expectsAuthorization(callable $call): void
    {
        try {
            $call();
            $this->fail('An AuthorizationException was expected.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_failed_send_keeps_the_validation_marks_the_send_failed_and_can_be_retried(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a));
        $submitter = $this->managerOf($this->a);
        $validator = $this->holder(['planning_check.approve'], $this->a);
        app(PlanningCheckActions::class)->submit($submitter, $this->a->id);
        $check = PlanningCheck::firstOrFail();

        $this->app->bind(PlanningMailer::class, fn () => new class extends PlanningMailer
        {
            public function send(Store $store, string $date, ?string $pdf, array $context = []): array
            {
                return ['ok' => false, 'error' => 'mail_failed', 'count' => 0];
            }
        });
        app(PlanningCheckActions::class)->approve($validator, $check);

        $check->refresh();
        $this->assertSame(['validated', 'failed', 'mail_failed', null], [$check->status, $check->send_status, $check->last_error, $check->sent_at]);
        $this->assertSame('failed', PlanningCheckSend::firstOrFail()->status);
        $this->assertTrue(app(PlanningCheckActions::class)->needsResend($check));
        $this->assertContains('planning_check_failed', $submitter->notifications()->pluck('type')->all());
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.send_failed')->first());

        $this->app->forgetInstance(PlanningMailer::class);
        $this->app->bind(PlanningMailer::class);
        Mail::fake();
        app(PlanningCheckActions::class)->resend($validator, $check->fresh());

        $check->refresh();
        $this->assertSame(['sent', null], [$check->send_status, $check->last_error]);
        $this->assertSame(2, PlanningCheckSend::count());
        $this->assertFalse(app(PlanningCheckActions::class)->needsResend($check));
        Mail::assertSent(PlanningMail::class, fn (PlanningMail $mail): bool => empty($mail->context['updated']));
    }

    public function test_a_planning_changed_after_the_send_offers_a_resend_marked_as_updated(): void
    {
        $this->enable();
        Mail::fake();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a));
        $validator = $this->managerOf($this->a);
        $actions = app(PlanningCheckActions::class);
        $actions->submit($validator, $this->a->id);
        $check = $actions->approve($validator, PlanningCheck::firstOrFail());

        $this->assertFalse($actions->needsResend($check->fresh()));

        // Un shift ajouté après l'envoi change l'empreinte : un renvoi est proposé.
        $this->shift($this->a, $this->employeeOf($this->a), '10:00', '18:00');
        $this->assertTrue($actions->needsResend($check->fresh()));

        $actions->resend($validator, $check->fresh());

        $this->assertSame(2, PlanningCheckSend::count());
        $this->assertFalse($actions->needsResend($check->fresh()));
        Mail::assertSent(PlanningMail::class, fn (PlanningMail $mail): bool => ! empty($mail->context['updated']));
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.resent')->first());
    }

    public function test_resending_needs_a_validated_check_and_the_approve_right(): void
    {
        $this->enable();
        Mail::fake();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $actions = app(PlanningCheckActions::class);
        $submitted = $this->check($this->a, 'submitted');

        try {
            $actions->resend($this->managerOf($this->a), $submitted);
            $this->fail('Only a validated check can be resent.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('check', $e->errors());
        }

        $submitted->forceFill(['status' => 'validated'])->save();
        $this->expectsAuthorization(fn () => $actions->resend($this->holder(['planning_check.view', 'planning_check.submit'], $this->a), $submitted));
        Mail::assertNothingSent();
    }

    // -- Refuser et rouvrir ---------------------------------------------------------------------------------------

    public function test_refusing_needs_a_reason_notifies_the_submitter_and_allows_a_new_submission(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a));
        $submitter = $this->holder(['planning_check.submit'], $this->a);
        $validator = $this->holder(['planning_check.approve'], $this->a);
        app(PlanningCheckActions::class)->submit($submitter, $this->a->id);
        $check = PlanningCheck::firstOrFail();

        $component = Livewire::actingAs($validator)->test('planning-check.index')->call('confirm', 'reject', $check->id);
        $component->set('reason', '   ')->call('reject')->assertHasErrors(['reason']);
        $component->set('reason', str_repeat('x', 501))->call('reject')->assertHasErrors(['reason']);
        $this->assertSame('submitted', $check->fresh()->status);

        $component->set('reason', 'Marie is on leave')->call('reject')->assertHasNoErrors();

        $check->refresh();
        $this->assertSame(['rejected', $validator->id, 'Marie is on leave'], [$check->status, $check->rejected_by, $check->reject_reason]);
        $this->assertSame('planning_check_rejected', $submitter->notifications()->first()->type);
        $entry = ActivityEntry::where('action', 'planning_check.rejected')->firstOrFail();
        $this->assertStringNotContainsString('Marie is on leave', (string) json_encode($entry->properties));

        Livewire::actingAs($submitter)->test('planning-check.index')->call('submit')->assertHasNoErrors();
        $check->refresh();
        $this->assertSame(['submitted', null, null], [$check->status, $check->rejected_by, $check->reject_reason]);
    }

    public function test_reopening_needs_the_submit_right_and_puts_the_check_back_to_pending(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $check = $this->check($this->a, 'submitted', ['submitted_by' => $this->managerOf($this->a)->id, 'submitted_at' => now()]);

        Livewire::actingAs($this->holder(['planning_check.approve'], $this->a))->test('planning-check.index')->call('confirm', 'reopen', $check->id)->call('reopen')->assertForbidden();
        $this->assertSame('submitted', $check->fresh()->status);

        Livewire::actingAs($this->managerOf($this->a))->test('planning-check.index')->call('confirm', 'reopen', $check->id)->call('reopen')->assertHasNoErrors();
        $check->refresh();
        $this->assertSame(['pending', null, null], [$check->status, $check->submitted_by, $check->submitted_at]);
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.reopened')->first());

        $this->expectException(ValidationException::class);
        app(PlanningCheckActions::class)->reopen($this->managerOf($this->a), $check);
    }

    public function test_a_check_of_another_store_cannot_be_targeted_from_the_screen(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->configure($this->b, [$this->recipient($this->b, 'b@example.test')]);
        Mail::fake();
        $foreign = $this->check($this->b, 'submitted');
        $manager = $this->managerOf($this->a);

        Livewire::actingAs($manager)->test('planning-check.index')->call('confirm', 'approve', $foreign->id)->call('approve')->assertNotFound();
        Livewire::actingAs($manager)->test('planning-check.index')->call('confirm', 'reject', $foreign->id)->set('reason', 'no')->call('reject')->assertNotFound();

        $this->assertSame('submitted', $foreign->fresh()->status);
        Mail::assertNothingSent();
    }

    // -- Envoyer maintenant ---------------------------------------------------------------------------------------

    public function test_send_now_submits_validates_and_sends_in_one_step(): void
    {
        $this->enable();
        Mail::fake();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a));
        $validator = $this->holder(['planning_check.approve'], $this->a);

        Livewire::actingAs($validator)->test('planning-check.index')->call('sendNow')->assertHasNoErrors();

        $check = PlanningCheck::firstOrFail();
        $this->assertSame(['validated', $validator->id, $validator->id, 'sent'], [$check->status, $check->submitted_by, $check->validated_by, $check->send_status]);
        Mail::assertSent(PlanningMail::class, 1);

        // Un planning déjà validé est régénéré et renvoyé comme version mise à jour.
        Livewire::actingAs($validator)->test('planning-check.index')->call('sendNow')->assertHasNoErrors();
        Mail::assertSent(PlanningMail::class, fn (PlanningMail $mail): bool => ! empty($mail->context['updated']));
        $this->assertSame(2, PlanningCheckSend::count());
    }

    public function test_send_now_is_reserved_to_approvers_and_needs_confirmation_on_an_empty_day(): void
    {
        $this->enable();
        Mail::fake();
        $this->configure($this->a, [$this->recipient($this->a)]);

        Livewire::actingAs($this->holder(['planning_check.submit'], $this->a))->test('planning-check.index')->call('sendNow')->assertForbidden();

        $validator = $this->holder(['planning_check.approve'], $this->a);
        Livewire::actingAs($validator)->test('planning-check.index')->call('sendNow')->assertHasErrors(['check']);
        Mail::assertNothingSent();

        Livewire::actingAs($validator)->test('planning-check.index')->set('confirmEmpty', true)->call('sendNow')->assertHasNoErrors();
        $this->assertSame(['validated', true], [PlanningCheck::firstOrFail()->status, PlanningCheck::firstOrFail()->confirmed_empty]);
        Mail::assertSent(PlanningMail::class, 1);
    }

    // -- Escalade ------------------------------------------------------------------------------------------------

    public function test_the_command_escalates_a_check_not_submitted_by_the_deadline_once_and_warns_the_approvers(): void
    {
        $this->enable();
        Mail::fake();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $validator = $this->holder(['planning_check.approve'], $this->a);
        $submitter = $this->holder(['planning_check.submit'], $this->a);
        $owner = $this->owner();

        $this->at('11:59');
        $this->artisan('kintai:planning-check-escalate')->assertSuccessful();
        $this->assertSame(0, PlanningCheck::where('status', 'escalated')->count());

        $this->at('12:00');
        $this->artisan('kintai:planning-check-escalate')->expectsOutput('{"ok":true,"escalated":1}')->assertSuccessful();

        $check = PlanningCheck::firstOrFail();
        $this->assertSame(['escalated', self::TOMORROW], [$check->status, $check->target_date->toDateString()]);
        $this->assertNotNull($check->escalated_at);
        foreach ([$validator, $owner] as $user) {
            $this->assertSame(['planning_check_overdue'], $user->notifications()->pluck('type')->all());
        }
        $this->assertSame(0, $submitter->notifications()->count());
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.escalated')->whereNull('actor_id')->first());
        Mail::assertNothingSent();

        // Idempotent : la validation n'est escaladée qu'une fois.
        $this->artisan('kintai:planning-check-escalate')->expectsOutput('{"ok":true,"escalated":0}')->assertSuccessful();
        $this->assertSame(1, $validator->notifications()->count());

        // Un escaladé reste soumettable.
        $this->shift($this->a, $this->employeeOf($this->a));
        app(PlanningCheckActions::class)->submit($submitter, $this->a->id);
        $this->assertSame('submitted', $check->fresh()->status);
    }

    public function test_the_command_skips_submitted_or_validated_checks_closed_days_disabled_stores_and_other_stores(): void
    {
        $this->enable();
        $this->at('13:00');
        $this->configure($this->a, [$this->recipient($this->a)]);
        $submitted = $this->check($this->a, 'submitted');
        $this->holder(['planning_check.approve'], $this->a);

        $this->artisan('kintai:planning-check-escalate')->assertSuccessful();
        $this->assertSame('submitted', $submitted->fresh()->status);

        // Mercredi (3) n'est pas un jour travaillé.
        $this->configure($this->b, [$this->recipient($this->b, 'b@example.test')], ['weekdays' => [1, 2, 4, 5]]);
        $store = Store::factory()->create(['name' => 'Disabled']);
        PlanningCheckSetting::query()->firstOrNew(['store_id' => $store->id], PlanningCheckSetting::DEFAULTS)->fill(['enabled' => false])->save();
        $offStore = Store::factory()->create(['name' => 'Feature off']);
        $this->configure($offStore);
        StoreFeature::create(['store_id' => $offStore->id, 'feature' => 'planning_check', 'enabled' => false]);

        $this->artisan('kintai:planning-check-escalate')->expectsOutput('{"ok":true,"escalated":0}')->assertSuccessful();
        $this->assertSame(1, PlanningCheck::count());
    }

    public function test_a_rejected_check_is_escalated_after_the_deadline(): void
    {
        $this->enable();
        $this->at('12:30');
        $this->configure($this->a, [$this->recipient($this->a)]);
        $rejected = $this->check($this->a, 'rejected', ['reject_reason' => 'Fix it']);

        $this->artisan('kintai:planning-check-escalate')->assertSuccessful();

        $this->assertSame('escalated', $rejected->fresh()->status);
    }

    // -- Règles de temps -----------------------------------------------------------------------------------------

    public function test_time_rules_use_the_instance_timezone(): void
    {
        $setting = new PlanningCheckSetting(['enabled' => true, 'deadline_time' => '12:00', 'window_start_time' => '06:00', 'weekdays' => [3]]);

        $this->at('05:59');
        $this->assertFalse(PlanningCheckPolicy::windowOpen($setting));
        $this->at('06:00');
        $this->assertTrue(PlanningCheckPolicy::windowOpen($setting));
        $this->assertFalse(PlanningCheckPolicy::isLate($setting));
        $this->at('12:00');
        $this->assertTrue(PlanningCheckPolicy::isLate($setting));
        $this->assertSame(self::TOMORROW, PlanningCheckPolicy::targetDate());
        $this->assertTrue(PlanningCheckPolicy::isWorkingDay($setting, self::TOMORROW));
        $this->assertFalse(PlanningCheckPolicy::isWorkingDay($setting, '2026-11-12'));
        $this->assertTrue(PlanningCheckPolicy::isDue($setting, self::TOMORROW));
        $this->assertFalse(PlanningCheckPolicy::isDue(new PlanningCheckSetting(['enabled' => false, 'weekdays' => [3], 'window_start_time' => '06:00']), self::TOMORROW));
    }

    // -- PDF -----------------------------------------------------------------------------------------------------

    public function test_the_pdf_is_reserved_to_who_can_open_the_screen_of_the_store(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a, ['name' => 'Ana Member']));
        $check = $this->check($this->a, 'validated');
        $viewer = $this->holder(['planning_check.view'], $this->a);

        $response = $this->actingAs($viewer)->get(route('planning-check.pdf', $check->id))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('planning-20261111-store'.$this->a->id.'.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->actingAs($this->employeeOf($this->a))->get(route('planning-check.pdf', $check->id))->assertNotFound();
        $this->actingAs($this->managerOf($this->b))->get(route('planning-check.pdf', $check->id))->assertNotFound();
        $this->actingAs($viewer)->get(route('planning-check.pdf', 99999))->assertNotFound();
    }

    public function test_the_pdf_html_lists_the_shifts_total_and_the_updated_mention(): void
    {
        $this->enable();
        $this->shift($this->a, $this->employeeOf($this->a, ['name' => 'Ana Member']));
        $this->shift($this->a, null, '12:00', '14:00');
        $schedule = app(DaySchedule::class);
        $pdf = app(PlanningPdf::class);

        $withoutOpen = $schedule->forDay($this->a->id, self::TOMORROW, false);
        $this->assertSame([1, 1, 420], [$withoutOpen['assigned'], $withoutOpen['open'], $withoutOpen['total_minutes']]);
        $html = $pdf->html($this->a, self::TOMORROW, $withoutOpen, ['validated_by' => 'Val Idator', 'updated' => true, 'generated_at' => '09:00']);
        $this->assertStringContainsString('Ana Member', $html);
        $this->assertStringContainsString('7h00', $html);
        $this->assertStringContainsString('Val Idator', $html);
        $this->assertStringNotContainsString('Open shift', $html);

        $withOpen = $schedule->forDay($this->a->id, self::TOMORROW, true);
        $this->assertSame(480, $withOpen['total_minutes']);
        $this->assertStringContainsString('Open shift', $pdf->html($this->a, self::TOMORROW, $withOpen));
        $this->assertStringStartsWith('%PDF', (string) $pdf->bytes($this->a, self::TOMORROW, $withOpen));
    }

    public function test_a_deleted_shift_and_the_shifts_of_other_stores_are_not_in_the_day(): void
    {
        $this->enable();
        $this->shift($this->a, $this->employeeOf($this->a, ['name' => 'Ana Member']));
        $this->shift($this->b, $this->employeeOf($this->b, ['name' => 'Zed Other']));
        $deleted = $this->shift($this->a, $this->employeeOf($this->a, ['name' => 'Gone Person']));
        $deleted->delete();

        $names = array_column(app(DaySchedule::class)->forDay($this->a->id, self::TOMORROW, false)['rows'], 'employee');

        $this->assertSame(['Ana Member'], $names);
    }

    // -- Réglages ------------------------------------------------------------------------------------------------

    public function test_a_holder_of_settings_saves_the_settings_of_the_store(): void
    {
        $this->enable();
        $holder = $this->holder(['planning_check.settings'], $this->a);
        $recipient = $this->recipient($this->a);

        Livewire::actingAs($holder)->test('planning-check.settings')
            ->assertSet('store', (string) $this->a->id)
            ->set('enabled', true)->set('windowStart', '05:30')->set('deadline', '11:15')->set('weekdays', [1, 2, 3])->set('recipients', [$recipient->id])->set('includeOpen', true)
            ->call('save')->assertHasNoErrors();

        $setting = PlanningCheckSetting::query()->firstOrNew(['store_id' => $this->a->id], PlanningCheckSetting::DEFAULTS);
        $this->assertSame([true, '05:30', '11:15', [1, 2, 3], [$recipient->id], true], [$setting->enabled, $setting->window_start_time, $setting->deadline_time, $setting->days(), $setting->recipientIds(), $setting->include_open_shifts]);
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.settings_updated')->first());
    }

    public function test_settings_are_validated_and_recipients_must_belong_to_the_store(): void
    {
        $this->enable();
        $holder = $this->holder(['planning_check.settings'], $this->a);
        $outsider = $this->recipient($this->b, 'o@example.test');
        $member = $this->recipient($this->a);

        Livewire::actingAs($holder)->test('planning-check.settings')
            ->set('recipients', [$outsider->id])->call('save')->assertHasErrors(['recipients'])
            ->set('recipients', [$member->id])->set('windowStart', '12:00')->set('deadline', '12:00')->call('save')->assertHasErrors(['windowStart'])
            ->set('windowStart', '06:00')->set('deadline', '25:99')->call('save')->assertHasErrors(['deadline'])
            ->set('deadline', '12:00')->set('weekdays', [])->call('save')->assertHasErrors(['weekdays'])
            ->set('weekdays', [9])->call('save')->assertHasErrors(['weekdays.0'])
            ->set('weekdays', [1])->set('enabled', true)->set('recipients', [])->call('save')->assertHasErrors(['recipients'])
            ->set('enabled', false)->set('recipients', array_fill(0, 11, $member->id))->call('save');

        $this->assertFalse(PlanningCheckSetting::query()->exists());
    }

    public function test_settings_are_reserved_to_the_stores_where_the_user_holds_the_right(): void
    {
        $this->enable();

        $this->actingAs($this->managerOf($this->a))->get('/planning-check/settings')->assertForbidden();
        $this->actingAs($this->holder(['planning_check.settings'], $this->a))->get('/planning-check/settings')->assertOk();

        $holder = $this->holder(['planning_check.settings'], $this->a);
        Livewire::actingAs($holder)->test('planning-check.settings', ['store' => (string) $this->b->id])->assertSet('store', (string) $this->a->id);
        Livewire::actingAs($holder)->test('planning-check.settings')->set('store', (string) $this->b->id)->assertForbidden();

        $this->expectsAuthorization(fn () => app(PlanningCheckActions::class)->saveSettings($holder, $this->b->id, [
            'enabled' => false, 'deadline_time' => '12:00', 'window_start_time' => '06:00', 'weekdays' => [1], 'mail_recipient_ids' => [], 'include_open_shifts' => false,
        ]));
        $this->assertFalse(PlanningCheckSetting::query()->exists());
    }

    public function test_the_test_pdf_goes_only_to_the_requester(): void
    {
        $this->enable();
        Mail::fake();
        $holder = $this->holder(['planning_check.settings'], $this->a);
        $holder->forceFill(['email' => 'me@example.test'])->save();
        $this->configure($this->a, [$this->recipient($this->a)]);

        Livewire::actingAs($holder)->test('planning-check.settings')->call('sendTest')->assertHasNoErrors();

        Mail::assertSent(PlanningMail::class, 1);
        Mail::assertSent(PlanningMail::class, fn (PlanningMail $mail): bool => $mail->hasTo('me@example.test') && ! $mail->hasTo('boss@example.test'));
        $this->assertNotNull(ActivityEntry::where('action', 'planning_check.test_sent')->first());

        Livewire::actingAs($this->managerOf($this->a))->test('planning-check.settings')->assertForbidden();
        $this->expectsAuthorization(fn () => app(PlanningCheckActions::class)->sendTest($this->managerOf($this->a), $this->a->id));
    }

    // -- Pastille, demandes et widget -----------------------------------------------------------------------------

    public function test_the_attention_count_is_the_checks_to_validate_plus_the_schedules_to_submit(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->configure($this->b, [$this->recipient($this->b, 'b@example.test')]);
        $access = app(PlanningCheckAccess::class);
        $manager = $this->managerOf($this->a);

        $this->assertSame(1, $access->attention($manager));

        $this->check($this->a, 'submitted');
        $this->assertSame(1, $access->attention($manager));

        $this->at('05:00');
        $this->assertSame(1, $access->attention($manager));

        $this->assertSame(0, $access->attention($this->employeeOf($this->a)));
        $this->assertSame(0, $access->attention($this->holder(['planning_check.view'], $this->a)));
    }

    public function test_the_widget_lists_the_stores_with_the_validation_on(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->at('13:00');

        Livewire::actingAs($this->managerOf($this->a))->test('planning-check.manager-widget')->assertSeeHtml('data-test="widget-check-row"')->assertSee('Alpha');
        Livewire::actingAs($this->managerOf($this->b))->test('planning-check.manager-widget')->assertSeeHtml('data-test="widget-no-checks"');
        Livewire::actingAs($this->owner())->test('planning-check.manager-widget')->assertSee('Alpha');
    }

    public function test_the_screen_shows_the_tomorrow_panel_history_and_the_actions_matching_the_rights(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a, ['name' => 'Ana Member']));
        $this->check($this->a, 'submitted');

        Livewire::actingAs($this->managerOf($this->a))->test('planning-check.index')
            ->assertSee('Ana Member')->assertSeeHtml('data-test="approve-check-button"')->assertSeeHtml('data-test="send-now-button"')->assertSeeHtml('data-test="reopen-check-button"');
        Livewire::actingAs($this->holder(['planning_check.view'], $this->a))->test('planning-check.index')
            ->assertSee('Ana Member')->assertDontSeeHtml('data-test="approve-check-button"')->assertDontSeeHtml('data-test="send-now-button"')->assertDontSeeHtml('data-test="submit-check-button"');
    }

    public function test_the_disabled_store_shows_a_hint_instead_of_the_panel(): void
    {
        $this->enable();

        Livewire::actingAs($this->managerOf($this->a))->test('planning-check.index')->assertSeeHtml('data-test="disabled-hint"')->assertDontSeeHtml('data-test="tomorrow-panel"');
    }

    public function test_the_visit_of_the_screen_marks_the_notifications_of_the_checks_as_read(): void
    {
        $this->enable();
        $this->configure($this->a, [$this->recipient($this->a)]);
        $this->shift($this->a, $this->employeeOf($this->a));
        $validator = $this->holder(['planning_check.approve'], $this->a);
        app(PlanningCheckActions::class)->submit($this->managerOf($this->a), $this->a->id);
        $this->assertSame(1, $validator->unreadNotifications()->count());

        Livewire::actingAs($validator)->test('planning-check.index');

        $this->assertSame(0, $validator->fresh()->unreadNotifications()->count());
    }

    public function test_deleting_a_submitter_keeps_the_history(): void
    {
        $this->enable();
        $submitter = $this->managerOf($this->a);
        $check = $this->check($this->a, 'validated', ['submitted_by' => $submitter->id, 'validated_by' => $submitter->id]);

        $submitter->forceDelete();

        $check->refresh();
        $this->assertSame(['validated', null, null], [$check->status, $check->submitted_by, $check->validated_by]);
    }
}
