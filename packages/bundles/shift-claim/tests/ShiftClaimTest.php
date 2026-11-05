<?php

namespace Kintai\Bundles\ShiftClaim\Tests;

use App\Actions\AnonymizeUser;
use App\Actions\Bundles\EnableBundle;
use App\Actions\Shifts\CreateShift;
use App\Actions\Shifts\DeleteShift;
use App\Actions\Shifts\UpdateShift;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftClaim\ClaimActions;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;
use Kintai\Bundles\ShiftClaim\Models\ShiftOffer;
use Kintai\Bundles\ShiftClaim\ShiftClaimServiceProvider;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Planning\TimeOffChecker;
use Kintai\Contracts\Requests\PendingRequestRegistry;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Reprise de shifts : bourse (ouverts et proposés), candidature, retrait, approbation (le Core attribue le shift),
 * garde-fous, cas concurrents, droits et portée magasin, notifications, suivi du planning du Core, bundle désactivé.
 */
class ShiftClaimTest extends TestCase
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

    /**
     * @param  list<string>  $permissions
     * @param  list<Store>  $stores
     */
    private function managerWith(array $permissions, array $stores): User
    {
        $role = $this->customRole($permissions);
        $user = User::factory()->create(['name' => 'Manager Person']);

        foreach ($stores as $store) {
            $store->members()->attach($user);
            $user->assignRole($role, $store);
        }

        return $user;
    }

    private function manager(): User
    {
        return $this->managerWith(['open_shifts.view', 'open_shifts.publish', 'open_shifts.approve', 'shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete', 'payroll.generate'], [$this->a]);
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    private function shift(?User $user, Store $store, int $offset = 10, string $start = '09:00', string $end = '17:00'): Shift
    {
        $date = $this->day($offset);

        return Shift::factory()->create([
            'store_id' => $store->id, 'user_id' => $user?->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ]);
    }

    private function notified(User $user, string $type): int
    {
        return $user->notifications()->where('type', $type)->count();
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_when_enabled(): void
    {
        $this->enableBundle('shift-claim');

        foreach (['view', 'publish', 'approve'] as $action) {
            $this->assertTrue(app(PermissionRegistry::class)->has('open_shifts.'.$action));
        }
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('open_shifts'));
        $this->assertTrue(app(NavigationRegistry::class)->has('shift-claim'));
        $this->assertTrue(app(NavigationRegistry::class)->has('shift-claim-manage'));
        $this->assertFalse(app(PendingRequestRegistry::class)->isEmpty());
        foreach (['open_shift_published', 'shift_claim_submitted', 'shift_claim_approved', 'shift_claim_rejected', 'shift_claim_withdrawn'] as $type) {
            $this->assertTrue(app(NotificationTypeRegistry::class)->has($type), $type);
        }
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('open_shifts.view'));
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('open_shifts'));
        $this->assertTrue(app(PendingRequestRegistry::class)->isEmpty());

        $this->actingAs($this->employeeOf($this->a))->get('/shift-claim')->assertNotFound();
        $this->actingAs($this->owner())->get('/shift-claim/manage')->assertNotFound();
        $this->assertTrue(Schema::hasTable('shift_claims'));

        // Désactivé, le bundle ne réagit pas au planning du Core.
        $employee = $this->employeeOf($this->a);
        $this->shift(null, $this->a);
        $this->assertSame(0, $employee->notifications()->count());
    }

    public function test_the_first_activation_gives_the_manager_role_every_right_and_employees_none(): void
    {
        app(EnableBundle::class)->handle($this->owner(), 'shift-claim');
        $this->app->register(ShiftClaimServiceProvider::class, true);

        $manager = $this->managerOf($this->a);
        $employee = $this->employeeOf($this->a);

        foreach (['view', 'publish', 'approve'] as $action) {
            $this->assertTrue(Gate::forUser($manager)->allows('open_shifts.'.$action, $this->a), $action);
            $this->assertFalse(Gate::forUser($employee)->allows('open_shifts.'.$action, $this->a), $action);
        }
    }

    // -- La bourse -----------------------------------------------------------------------------------------------

    public function test_the_pool_lists_open_and_offered_upcoming_shifts_of_the_stores_of_the_employee(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $holder = $this->employeeOf($this->a, ['name' => 'Holder Person']);
        $this->shift(null, $this->a, 10);
        $offered = $this->shift($holder, $this->a, 11);
        ShiftOffer::create(['shift_id' => $offered->id, 'note' => 'Please take it']);
        $this->shift($holder, $this->a, 12, '08:00', '12:00');
        $this->shift(null, $this->a, -2);
        $this->shift(null, $this->b, 13, '10:00', '11:00');

        Livewire::actingAs($eve)->test('shift-claim.index')
            ->assertSee($this->day(10))->assertSee($this->day(11))->assertSee('Please take it')
            ->assertDontSee($this->day(12))->assertDontSee($this->day(-2))->assertDontSee($this->day(13));
        $this->actingAs($this->owner())->get('/shift-claim')->assertNotFound();
    }

    public function test_an_employee_applies_without_any_permission_and_the_approvers_are_told(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a, ['name' => 'Eve Employee']);
        $open = $this->shift(null, $this->a);
        $approver = $this->managerWith(['open_shifts.approve'], [$this->a]);
        $viewer = $this->managerWith(['open_shifts.view'], [$this->a]);
        $elsewhere = $this->managerWith(['open_shifts.approve'], [$this->b]);

        Livewire::actingAs($eve)->test('shift-claim.index')
            ->call('confirmClaim', $open->id)->set('note', '  Happy to  ')->call('claim')->assertHasNoErrors();

        $claim = ShiftClaim::firstOrFail();
        $this->assertSame([$open->id, $eve->id, $this->a->id, 'pending', 'Happy to'], [$claim->shift_id, $claim->user_id, $claim->store_id, $claim->status, $claim->note]);
        $this->assertSame(1, $this->notified($approver, 'shift_claim_submitted'));
        $this->assertSame(0, $this->notified($viewer, 'shift_claim_submitted'));
        $this->assertSame(0, $this->notified($elsewhere, 'shift_claim_submitted'));
        $this->assertSame(0, $this->notified($eve, 'shift_claim_submitted'));
        $this->assertNotNull(ActivityEntry::where('action', 'shift_claim.created')->where('actor_id', $eve->id)->first());

        // Pas deux fois.
        Livewire::actingAs($eve)->test('shift-claim.index')->call('confirmClaim', $open->id)->call('claim');
        $this->assertSame(1, ShiftClaim::count());
        $this->assertSame(1, $this->notified($approver, 'shift_claim_submitted'));
    }

    public function test_claim_rules_membership_ownership_time_off_feature_and_availability(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $actions = app(ClaimActions::class);

        // Magasin dont l'employé n'est pas membre : le shift n'existe pas pour lui.
        $foreign = $this->shift(null, $this->b);
        try {
            $actions->claim($eve, $foreign->id, null);
            $this->fail('A non-member cannot apply.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, ShiftClaim::count());
        }
        Livewire::actingAs($eve)->test('shift-claim.index')->call('confirmClaim', $foreign->id)->assertNotFound();

        // Membre devenu inactif.
        $left = $this->employeeOf($this->b);
        $this->b->members()->updateExistingPivot($left->id, ['is_active' => false]);
        $this->expectRefusals($actions, $left, $foreign, ModelNotFoundException::class);

        // Shift assigné non proposé, passé, supprimé, son propre shift proposé.
        $assigned = $this->shift($this->employeeOf($this->a), $this->a, 10);
        $this->expectRefusals($actions, $eve, $assigned, ValidationException::class);
        $this->expectRefusals($actions, $eve, $this->shift(null, $this->a, -1), ValidationException::class);
        $deleted = $this->shift(null, $this->a, 11);
        $deleted->delete();
        $this->expectRefusals($actions, $eve, $deleted, ModelNotFoundException::class);
        $mine = $this->shift($eve, $this->a, 12);
        ShiftOffer::create(['shift_id' => $mine->id]);
        $this->expectRefusals($actions, $eve, $mine, ValidationException::class);

        // Congé approuvé.
        $open = $this->shift(null, $this->a, 13);
        $date = $this->day(13);
        $this->app->bind(TimeOffChecker::class, fn () => new class($eve->id, $date) implements TimeOffChecker
        {
            public function __construct(private readonly int $userId, private readonly string $date) {}

            public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool
            {
                return $userId === $this->userId && $date->toDateString() === $this->date;
            }
        });
        $this->expectRefusals($actions, $eve, $open, ValidationException::class);

        // Fonctionnalité désactivée.
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'open_shifts', 'enabled' => false]);
        $this->expectRefusals($actions, $eve, $this->shift(null, $this->a, 14), ModelNotFoundException::class);
        $this->actingAs($eve)->get('/shift-claim')->assertNotFound();
    }

    /**
     * @param  class-string<\Throwable>  $exception
     */
    private function expectRefusals(ClaimActions $actions, User $user, Shift $shift, string $exception): void
    {
        $before = ShiftClaim::count();

        try {
            $actions->claim($user, $shift->id, null);
            $this->fail('The application should be refused.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
            $this->assertSame($before, ShiftClaim::count());
        }
    }

    public function test_an_employee_withdraws_and_can_apply_again(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a);
        $actions = app(ClaimActions::class);
        $claim = $actions->claim($eve, $open->id, 'First');

        Livewire::actingAs($this->employeeOf($this->a))->test('shift-claim.index')->call('confirmWithdrawal', $claim->id)->assertNotFound();
        Livewire::actingAs($eve)->test('shift-claim.index')->call('confirmWithdrawal', $claim->id)->call('withdraw')->assertHasNoErrors();
        $this->assertSame('withdrawn', $claim->fresh()->status);

        $again = $actions->claim($eve, $open->id, 'Second');
        $this->assertSame($claim->id, $again->id);
        $this->assertSame('pending', $again->status);
        $this->assertSame('Second', $again->note);
        $this->assertSame(1, ShiftClaim::count());
    }

    // -- Approuver -----------------------------------------------------------------------------------------------

    public function test_a_manager_approves_one_application_the_core_assigns_the_shift_and_the_others_are_rejected(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $fay = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a, 10, '09:00', '17:00');
        $actions = app(ClaimActions::class);
        $winner = $actions->claim($eve, $open->id, null);
        $loser = $actions->claim($fay, $open->id, null);
        $manager = $this->manager();

        Livewire::actingAs($manager)->test('shift-claim.manage')->assertSee($eve->name)->call('approve', $winner->id)->assertHasNoErrors();

        $this->assertSame('approved', $winner->fresh()->status);
        $this->assertSame($manager->id, $winner->fresh()->resolved_by);
        $this->assertSame('rejected', $loser->fresh()->status);
        $this->assertSoftDeleted($open);
        $new = Shift::findOrFail(Shift::withTrashed()->find($open->id)->replaced_by_id);
        $this->assertSame($eve->id, $new->user_id);
        $this->assertSame($this->day(10), $new->shift_date->toDateString());
        $this->assertSame('09:00', $new->localStartsAt->format('H:i'));

        $this->assertSame(1, $this->notified($eve, 'shift_claim_approved'));
        $this->assertSame(1, $this->notified($fay, 'shift_claim_rejected'));
        $this->assertSame(0, $this->notified($manager, 'shift_claim_approved'));
        $this->assertNotNull(ActivityEntry::where('action', 'shift_claim.approved')->first());
        $this->assertSame(0, ShiftClaim::where('status', 'pending')->count());
    }

    public function test_an_offered_shift_goes_to_the_applicant_and_its_holder_loses_it(): void
    {
        $this->enableBundle('shift-claim');
        $holder = $this->employeeOf($this->a);
        $eve = $this->employeeOf($this->a);
        $shift = $this->shift($holder, $this->a);
        $manager = $this->manager();
        app(ClaimActions::class)->offer($manager, $shift->id, 'Anyone?');
        $claim = app(ClaimActions::class)->claim($eve, $shift->id, null);

        app(ClaimActions::class)->approve($manager, $claim);

        $this->assertSame($eve->id, Shift::findOrFail(Shift::withTrashed()->find($shift->id)->replaced_by_id)->user_id);
        $this->assertSame(0, ShiftOffer::count());
        $this->assertSame(1, $holder->notifications()->where('type', 'shift_deleted')->count(), 'The Core tells the former holder.');
    }

    public function test_a_decision_cannot_be_replayed_and_a_second_manager_is_refused_cleanly(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $fay = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a);
        $actions = app(ClaimActions::class);
        $first = $actions->claim($eve, $open->id, null);
        $second = $actions->claim($fay, $open->id, null);
        $one = $this->manager();
        $two = $this->manager();

        $actions->approve($one, $first);
        $count = Shift::withTrashed()->count();

        foreach ([[$two, $first], [$two, $second]] as [$actor, $claim]) {
            try {
                $actions->approve($actor, $claim, true);
                $this->fail('Only one application wins.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('request', $e->errors());
            }
        }
        try {
            $actions->reject($two, $first);
            $this->fail('An approved application cannot be rejected.');
        } catch (ValidationException) {
        }

        $this->assertSame($count, Shift::withTrashed()->count());
        $this->assertSame(1, $this->notified($eve, 'shift_claim_approved'));
        $this->assertSame(1, $this->notified($fay, 'shift_claim_rejected'));
    }

    public function test_a_shift_deleted_or_assigned_meanwhile_cannot_be_granted(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $manager = $this->manager();
        $actions = app(ClaimActions::class);

        $gone = $this->shift(null, $this->a, 10);
        $claimGone = $actions->claim($eve, $gone->id, null);
        $gone->delete();

        $taken = $this->shift(null, $this->a, 11);
        $claimTaken = $actions->claim($eve, $taken->id, null);
        $taken->update(['user_id' => $this->employeeOf($this->a)->id]);

        foreach ([$claimGone, $claimTaken] as $claim) {
            try {
                $actions->approve($manager, $claim->fresh(), true);
                $this->fail('The shift is no longer available.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, Shift::onlyTrashed()->whereNotNull('replaced_by_id')->count());
    }

    public function test_planning_warnings_ask_for_a_confirmation_and_nothing_is_written_before(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a, 10, '09:00', '17:00');
        $this->shift($eve, $this->a, 10, '12:00', '14:00');
        $other = $this->employeeOf($this->a);
        $claim = app(ClaimActions::class)->claim($eve, $open->id, null);
        $loser = app(ClaimActions::class)->claim($other, $open->id, null);

        $component = Livewire::actingAs($this->manager())->test('shift-claim.manage')->call('approve', $claim->id);
        $this->assertCount(1, $component->get('warnings'));
        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertSame('pending', $loser->fresh()->status, 'Nothing is written before the confirmation.');
        $this->assertNull(Shift::withTrashed()->find($open->id)->deleted_at);

        $component->call('confirmWarnings')->assertHasNoErrors();

        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertSoftDeleted($open);
    }

    public function test_an_approved_time_off_blocks_the_approval(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a, 10);
        $claim = app(ClaimActions::class)->claim($eve, $open->id, null);
        $this->app->bind(TimeOffChecker::class, fn () => new class implements TimeOffChecker
        {
            public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool
            {
                return true;
            }
        });

        try {
            app(ClaimActions::class)->approve($this->manager(), $claim, true);
            $this->fail('A time off blocks the assignment.');
        } catch (ValidationException) {
            $this->assertSame('pending', $claim->fresh()->status);
            $this->assertNull(Shift::withTrashed()->find($open->id)->deleted_at);
        }
    }

    public function test_deciding_needs_the_right_in_the_store_and_the_shift_rights(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a);
        $claim = app(ClaimActions::class)->claim($eve, $open->id, null);
        $viewer = $this->managerWith(['open_shifts.view', 'shifts.create', 'shifts.update'], [$this->a]);
        $noShiftRights = $this->managerWith(['open_shifts.view', 'open_shifts.approve'], [$this->a]);
        $elsewhere = $this->managerWith(['open_shifts.view', 'open_shifts.approve', 'shifts.create', 'shifts.update'], [$this->b]);

        Livewire::actingAs($viewer)->test('shift-claim.manage')->call('approve', $claim->id)->assertForbidden();
        Livewire::actingAs($noShiftRights)->test('shift-claim.manage')->call('approve', $claim->id)->assertForbidden();
        Livewire::actingAs($elsewhere)->test('shift-claim.manage')->call('approve', $claim->id)->assertNotFound();
        Livewire::actingAs($viewer)->test('shift-claim.manage')->call('confirmRejection', $claim->id)->assertForbidden();
        Livewire::actingAs($eve)->test('shift-claim.manage')->assertForbidden();
        $this->actingAs($eve)->get('/shift-claim/manage')->assertForbidden();

        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertNull(Shift::withTrashed()->find($open->id)->deleted_at);
    }

    public function test_a_manager_rejects_an_application(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a);
        $claim = app(ClaimActions::class)->claim($eve, $open->id, null);

        Livewire::actingAs($this->manager())->test('shift-claim.manage')->call('confirmRejection', $claim->id)->call('reject')->assertHasNoErrors();

        $this->assertSame('rejected', $claim->fresh()->status);
        $this->assertSame(1, $this->notified($eve, 'shift_claim_rejected'));
        $this->assertNull(Shift::withTrashed()->find($open->id)->deleted_at);
        $this->assertNotNull(ActivityEntry::where('action', 'shift_claim.rejected')->first());
    }

    // -- Proposer, retirer ---------------------------------------------------------------------------------------

    public function test_a_manager_offers_a_shift_then_withdraws_it(): void
    {
        $this->enableBundle('shift-claim');
        $holder = $this->employeeOf($this->a);
        $eve = $this->employeeOf($this->a);
        $shift = $this->shift($holder, $this->a);
        $manager = $this->manager();

        Livewire::actingAs($manager)->test('shift-claim.manage')->set('offerShift', (string) $shift->id)->set('offerNote', 'Cover me')->call('offer')->assertHasNoErrors();

        $this->assertSame('Cover me', ShiftOffer::firstOrFail()->note);
        $this->assertSame(1, $this->notified($eve, 'open_shift_published'));
        $this->assertSame(0, $this->notified($holder, 'open_shift_published'), 'The holder is not told.');
        $this->assertSame(0, $this->notified($manager, 'open_shift_published'));
        $claim = app(ClaimActions::class)->claim($eve, $shift->id, null);

        Livewire::actingAs($manager)->test('shift-claim.manage')->call('confirmUnoffer', $shift->id)->call('unoffer')->assertHasNoErrors();

        $this->assertSame(0, ShiftOffer::count());
        $this->assertSame('withdrawn', $claim->fresh()->status);
        $this->assertSame(1, $this->notified($eve, 'shift_claim_withdrawn'));
        $this->assertSame($holder->id, $shift->fresh()->user_id);
    }

    public function test_offering_needs_the_publish_right_an_assigned_upcoming_shift_and_is_not_repeated(): void
    {
        $this->enableBundle('shift-claim');
        $holder = $this->employeeOf($this->a);
        $shift = $this->shift($holder, $this->a);
        $actions = app(ClaimActions::class);

        foreach ([$this->managerWith(['open_shifts.view', 'open_shifts.approve'], [$this->a]), $this->managerWith(['open_shifts.publish'], [$this->b]), $holder] as $actor) {
            try {
                $actions->offer($actor, $shift->id, null);
                $this->fail('Offering needs the publish right.');
            } catch (AuthorizationException) {
            }
        }

        $publisher = $this->managerWith(['open_shifts.publish'], [$this->a]);
        foreach ([$this->shift(null, $this->a), $this->shift($holder, $this->a, -2)] as $invalid) {
            try {
                $actions->offer($publisher, $invalid->id, null);
                $this->fail('This shift cannot be offered.');
            } catch (ValidationException) {
            }
        }

        $actions->offer($publisher, $shift->id, null);
        try {
            $actions->offer($publisher, $shift->id, null);
            $this->fail('Already on offer.');
        } catch (ValidationException) {
        }
        $this->assertSame(1, ShiftOffer::count());
    }

    // -- Suivi du planning du Core -------------------------------------------------------------------------------

    public function test_a_new_open_shift_is_announced_to_the_members_of_the_store(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $elsewhere = $this->employeeOf($this->b);
        $manager = $this->manager();
        $date = $this->day(10);

        app(CreateShift::class)->handle($manager, [
            'store_id' => $this->a->id, 'user_id' => null, 'shift_date' => $date, 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 0,
        ]);

        $this->assertSame(1, $this->notified($eve, 'open_shift_published'));
        $this->assertSame(0, $this->notified($elsewhere, 'open_shift_published'));
        $this->assertSame(0, $this->notified($manager, 'open_shift_published'));
    }

    public function test_applications_are_withdrawn_when_the_core_assigns_or_deletes_the_shift(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $fay = $this->employeeOf($this->a);
        $manager = $this->manager();
        $actions = app(ClaimActions::class);

        $assigned = $this->shift(null, $this->a, 10);
        $claimA = $actions->claim($eve, $assigned->id, null);
        app(UpdateShift::class)->handle($manager, $assigned, ['user_id' => $fay->id]);

        $deleted = $this->shift(null, $this->a, 11);
        $claimD = $actions->claim($eve, $deleted->id, null);
        app(DeleteShift::class)->handle($manager, $deleted);

        $this->assertSame('withdrawn', $claimA->fresh()->status);
        $this->assertSame('withdrawn', $claimD->fresh()->status);
        $this->assertSame(2, $this->notified($eve, 'shift_claim_withdrawn'));
    }

    // -- Tableau de bord, données --------------------------------------------------------------------------------

    public function test_pending_requests_count_what_each_person_follows_or_decides(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $actions = app(ClaimActions::class);
        $actions->claim($eve, $this->shift(null, $this->a, 10)->id, null);
        $actions->claim($this->employeeOf($this->a), $this->shift(null, $this->a, 11)->id, null);
        $registry = app(PendingRequestRegistry::class);
        $count = fn (User $user): int => $registry->counts($user)[0]['count'];

        $this->assertSame(1, $count($eve));
        $this->assertSame(2, $count($this->manager()));
        $this->assertSame(0, $count($this->managerWith(['open_shifts.approve'], [$this->b])));
    }

    public function test_notifications_are_marked_read_when_the_pool_is_opened(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $shift = $this->shift(null, $this->a);
        app(ClaimActions::class)->announce($shift, null);
        $this->assertSame(1, $eve->unreadNotifications()->where('type', 'open_shift_published')->count());

        Livewire::actingAs($eve)->test('shift-claim.index');

        $this->assertSame(0, $eve->unreadNotifications()->where('type', 'open_shift_published')->count());
    }

    public function test_anonymising_an_account_erases_the_notes_but_keeps_the_application(): void
    {
        $this->enableBundle('shift-claim');
        $eve = $this->employeeOf($this->a);
        $claim = app(ClaimActions::class)->claim($eve, $this->shift(null, $this->a)->id, 'Private');

        app(AnonymizeUser::class)->handle($eve);

        $this->assertNull($claim->fresh()->note);
        $this->assertDatabaseHas('shift_claims', ['id' => $claim->id]);
    }
}
