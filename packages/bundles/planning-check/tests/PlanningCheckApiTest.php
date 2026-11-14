<?php

namespace Kintai\Bundles\PlanningCheck\Tests;

use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheck;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Kintai\Bundles\PlanningCheck\PlanningMail;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API de la validation du planning : `/api/v1/planning-check`, mêmes droits que les écrans, mêmes erreurs que l'API du Core.
 * L'heure est figée au mardi 2026-11-10 09:00 : le jour visé est le mercredi 2026-11-11.
 */
class PlanningCheckApiTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;
    use UsesApi;

    private const TOMORROW = '2026-11-11';

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Zulu']);
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', (string) config('kintai.timezone')));

        $this->enableBundle('planning-check');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('planning-check') ?? throw new LogicException);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function holder(array $permissions, Store $store): User
    {
        $user = User::factory()->create();
        $store->members()->attach($user);
        $user->assignRole($this->customRole($permissions), $store);

        return $user;
    }

    private function configure(Store $store, string $email = 'boss@example.test'): void
    {
        $recipient = User::factory()->create(['email' => $email]);
        $store->members()->attach($recipient);
        PlanningCheckSetting::query()->firstOrNew(['store_id' => $store->id], PlanningCheckSetting::DEFAULTS)->fill(['enabled' => true, 'mail_recipient_ids' => [$recipient->id]])->save();
    }

    private function shift(Store $store): void
    {
        $type = ShiftType::query()->where('name', 'Morning')->first() ?? ShiftType::factory()->create(['name' => 'Morning']);
        Shift::fromLocalTimes(self::TOMORROW, '09:00', '17:00', ['store_id' => $store->id, 'user_id' => User::factory()->create(['name' => 'Ana Member'])->id, 'shift_type_id' => $type->id, 'pause_minutes' => 60])->save();
    }

    private function check(Store $store, string $status = 'pending', string $date = self::TOMORROW): PlanningCheck
    {
        return PlanningCheck::create(['store_id' => $store->id, 'target_date' => $date, 'status' => $status]);
    }

    public function test_the_api_needs_a_token(): void
    {
        $this->getJson('/api/v1/planning-check/tomorrow?store_id='.$this->a->id)->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->assertSame(url('/api/v1/planning-check/checks'), route('api.v1.planning-check.checks.index'));
    }

    public function test_tomorrow_gives_the_state_the_shifts_and_the_capabilities(): void
    {
        $this->configure($this->a);
        $this->shift($this->a);
        $manager = $this->managerOf($this->a);

        $this->actingWithToken($manager)->getJson('/api/v1/planning-check/tomorrow?store_id='.$this->a->id)->assertOk()
            ->assertJsonPath('data.date', self::TOMORROW)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.working_day', true)
            ->assertJsonPath('data.late', false)
            ->assertJsonPath('data.check', null)
            ->assertJsonPath('data.shifts.0.employee', 'Ana Member')
            ->assertJsonPath('data.total_minutes', 420)
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.can_approve', true);

        $this->actingWithToken($this->holder(['planning_check.view'], $this->a))->getJson('/api/v1/planning-check/tomorrow?store_id='.$this->a->id)->assertOk()
            ->assertJsonPath('data.can_submit', false)->assertJsonPath('data.can_approve', false);
    }

    public function test_tomorrow_is_scoped_and_validated(): void
    {
        $this->configure($this->a);
        $manager = $this->managerOf($this->a);

        $this->actingWithToken($manager)->getJson('/api/v1/planning-check/tomorrow')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->actingWithToken($manager)->getJson('/api/v1/planning-check/tomorrow?store_id='.$this->b->id)->assertNotFound()->assertJson(['code' => 'not_found']);
        $this->actingWithToken($this->employeeOf($this->a))->getJson('/api/v1/planning-check/tomorrow?store_id='.$this->a->id)->assertNotFound();
    }

    public function test_submit_creates_the_check_for_tomorrow_and_ignores_forbidden_fields(): void
    {
        $this->configure($this->a);
        $this->shift($this->a);
        $manager = $this->managerOf($this->a);

        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/submit', [
            'store_id' => $this->a->id, 'target_date' => '2030-01-01', 'status' => 'validated', 'submitted_by' => 999,
        ])->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.target_date', self::TOMORROW)->assertJsonPath('data.submitted_by.id', $manager->id);

        $this->assertSame(1, PlanningCheck::count());
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/submit', ['store_id' => $this->a->id])->assertStatus(422)->assertJson(['code' => 'validation_failed']);
    }

    public function test_submit_is_validated_and_scoped(): void
    {
        $this->configure($this->a);
        $this->configure($this->b, 'b@example.test');
        $manager = $this->managerOf($this->a);

        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/submit', [])->assertStatus(422);
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/submit', ['store_id' => 'abc'])->assertStatus(422);
        // Jour vide : confirmation explicite requise.
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/submit', ['store_id' => $this->a->id])->assertStatus(422);
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/submit', ['store_id' => $this->a->id, 'confirm_empty' => true])->assertCreated()->assertJsonPath('data.confirmed_empty', true);
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/submit', ['store_id' => $this->b->id, 'confirm_empty' => true])->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingWithToken($this->employeeOf($this->a))->postJson('/api/v1/planning-check/submit', ['store_id' => $this->a->id, 'confirm_empty' => true])->assertForbidden();
        $this->assertSame(0, PlanningCheck::where('store_id', $this->b->id)->count());
    }

    public function test_the_list_is_scoped_and_filtered(): void
    {
        $this->configure($this->a);
        $this->check($this->a, 'validated', '2026-11-09');
        $this->check($this->a, 'submitted', '2026-11-10');
        $this->check($this->b, 'submitted');
        $viewer = $this->holder(['planning_check.view'], $this->a);

        $this->actingWithToken($viewer)->getJson('/api/v1/planning-check/checks')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.target_date', '2026-11-10');
        $this->actingWithToken($viewer)->getJson('/api/v1/planning-check/checks?status=validated')->assertJsonCount(1, 'data');
        $this->actingWithToken($viewer)->getJson('/api/v1/planning-check/checks?from=2026-11-10&to=2026-11-30')->assertJsonCount(1, 'data');
        $this->actingWithToken($viewer)->getJson('/api/v1/planning-check/checks?store_id='.$this->b->id)->assertJsonCount(0, 'data');
        $this->actingWithToken($viewer)->getJson('/api/v1/planning-check/checks?status=bogus')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->actingWithToken($viewer)->getJson('/api/v1/planning-check/checks?from=yesterday')->assertStatus(422);
        $this->actingWithToken($this->employeeOf($this->a))->getJson('/api/v1/planning-check/checks')->assertJsonCount(0, 'data');
    }

    public function test_show_hides_the_checks_out_of_scope(): void
    {
        $own = $this->check($this->a, 'submitted');
        $foreign = $this->check($this->b, 'submitted');
        $manager = $this->managerOf($this->a);

        $this->actingWithToken($manager)->getJson('/api/v1/planning-check/checks/'.$own->id)->assertOk()
            ->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.can_decide', true)->assertJsonPath('data.can_submit', false);
        $this->actingWithToken($manager)->getJson('/api/v1/planning-check/checks/'.$foreign->id)->assertNotFound()->assertJson(['code' => 'not_found']);
        $this->actingWithToken($manager)->getJson('/api/v1/planning-check/checks/999999')->assertNotFound();
        $this->actingWithToken($this->employeeOf($this->a))->getJson('/api/v1/planning-check/checks/'.$own->id)->assertNotFound();
    }

    public function test_approve_validates_and_mails_the_pdf(): void
    {
        Mail::fake();
        $this->configure($this->a);
        $check = $this->check($this->a, 'submitted');

        $this->actingWithToken($this->holder(['planning_check.view', 'planning_check.submit'], $this->a))->postJson('/api/v1/planning-check/checks/'.$check->id.'/approve')->assertForbidden();
        $this->assertSame('submitted', $check->fresh()->status);

        $validator = $this->holder(['planning_check.approve'], $this->a);
        $this->actingWithToken($validator)->postJson('/api/v1/planning-check/checks/'.$check->id.'/approve')->assertOk()
            ->assertJsonPath('data.status', 'validated')->assertJsonPath('data.send_status', 'sent')->assertJsonPath('data.validated_by.id', $validator->id);
        Mail::assertSent(PlanningMail::class, 1);

        // Plus dans l'état attendu.
        $this->actingWithToken($validator)->postJson('/api/v1/planning-check/checks/'.$check->id.'/approve')->assertStatus(422);
    }

    public function test_approve_without_recipient_is_refused_and_a_foreign_check_is_not_found(): void
    {
        Mail::fake();
        PlanningCheckSetting::query()->firstOrNew(['store_id' => $this->a->id], PlanningCheckSetting::DEFAULTS)->fill(['enabled' => true])->save();
        $check = $this->check($this->a, 'submitted');
        $foreign = $this->check($this->b, 'submitted');
        $manager = $this->managerOf($this->a);

        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/checks/'.$check->id.'/approve')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/checks/'.$foreign->id.'/approve')->assertNotFound();
        $this->assertSame('submitted', $check->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_reject_needs_a_reason_and_the_approve_right(): void
    {
        $this->configure($this->a);
        $check = $this->check($this->a, 'submitted');
        $validator = $this->managerOf($this->a);

        $this->actingWithToken($validator)->postJson('/api/v1/planning-check/checks/'.$check->id.'/reject', [])->assertStatus(422);
        $this->actingWithToken($validator)->postJson('/api/v1/planning-check/checks/'.$check->id.'/reject', ['reason' => str_repeat('x', 501)])->assertStatus(422);
        $this->actingWithToken($this->holder(['planning_check.submit'], $this->a))->postJson('/api/v1/planning-check/checks/'.$check->id.'/reject', ['reason' => 'No'])->assertForbidden();

        $this->actingWithToken($validator)->postJson('/api/v1/planning-check/checks/'.$check->id.'/reject', ['reason' => 'Fix the evening'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', 'Fix the evening')->assertJsonPath('data.can_submit', true);
    }

    public function test_reopen_and_resend_follow_the_state_and_the_rights(): void
    {
        Mail::fake();
        $this->configure($this->a);
        $check = $this->check($this->a, 'validated');
        $manager = $this->managerOf($this->a);

        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/checks/'.$check->id.'/resend')->assertOk()->assertJsonPath('data.send_status', 'sent');
        Mail::assertSent(PlanningMail::class, 1);

        $this->actingWithToken($this->holder(['planning_check.approve'], $this->a))->postJson('/api/v1/planning-check/checks/'.$check->id.'/reopen')->assertForbidden();
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/checks/'.$check->id.'/reopen')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/checks/'.$check->id.'/reopen')->assertStatus(422);
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/checks/'.$check->id.'/resend')->assertStatus(422);
    }

    public function test_send_now_validates_and_sends_for_an_approver(): void
    {
        Mail::fake();
        $this->configure($this->a);
        $this->shift($this->a);
        $manager = $this->managerOf($this->a);

        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/send-now', ['store_id' => $this->a->id])->assertOk()
            ->assertJsonPath('data.status', 'validated')->assertJsonPath('data.send_status', 'sent');
        Mail::assertSent(PlanningMail::class, 1);

        $this->actingWithToken($this->holder(['planning_check.submit'], $this->a))->postJson('/api/v1/planning-check/send-now', ['store_id' => $this->a->id])->assertForbidden();
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/send-now', ['store_id' => $this->b->id])->assertForbidden();
        $this->actingWithToken($manager)->postJson('/api/v1/planning-check/send-now', [])->assertStatus(422);
    }

    public function test_a_disabled_store_refuses_the_actions(): void
    {
        PlanningCheckSetting::query()->firstOrNew(['store_id' => $this->a->id], PlanningCheckSetting::DEFAULTS)->fill(['enabled' => false])->save();

        $this->actingWithToken($this->managerOf($this->a))->postJson('/api/v1/planning-check/submit', ['store_id' => $this->a->id, 'confirm_empty' => true])
            ->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->assertSame(0, PlanningCheck::count());
    }
}
