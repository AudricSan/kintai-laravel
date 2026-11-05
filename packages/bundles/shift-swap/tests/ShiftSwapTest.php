<?php

namespace Kintai\Bundles\ShiftSwap\Tests;

use App\Actions\AnonymizeUser;
use App\Actions\Bundles\EnableBundle;
use App\Bundles\BundleManager;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Bundles\ShiftSwap\ShiftSwapServiceProvider;
use Kintai\Bundles\ShiftSwap\SwapActions;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Planning\TimeOffChecker;
use Kintai\Contracts\Requests\PendingRequestRegistry;
use Kintai\Contracts\Shifts\ShiftExchanger;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Échanges de shifts : demande, réponse du collègue, annulation, approbation (le Core applique l'échange), garde-fous du
 * planning, cas concurrents (shift modifié, supprimé, réaffecté, décision rejouée), échange appliqué par un gérant,
 * suppression avec rétablissement, portée magasin, notifications, widgets, bundle désactivé.
 */
class ShiftSwapTest extends TestCase
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
     * Gérant (rôle sur mesure) membre des magasins donnés, avec les permissions données.
     *
     * @param  list<string>  $permissions
     * @param  list<Store>  $stores
     */
    private function managerWith(array $permissions, array $stores, string $name = 'Manager Person'): User
    {
        $role = $this->customRole($permissions);
        $user = User::factory()->create(['name' => $name]);

        foreach ($stores as $store) {
            $store->members()->attach($user);
            $user->assignRole($role, $store);
        }

        return $user;
    }

    /** @return list<string> */
    private function managerRights(): array
    {
        return ['swaps.view', 'swaps.create', 'swaps.update', 'swaps.approve', 'swaps.delete', 'shifts.view', 'shifts.create', 'shifts.update'];
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    private function shift(User $user, Store $store, int $offset, string $start = '09:00', string $end = '17:00'): Shift
    {
        $date = $this->day($offset);

        return Shift::factory()->create([
            'store_id' => $store->id, 'user_id' => $user->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ]);
    }

    /**
     * @return array{User, User, Shift, Shift, ShiftSwapRequest} Alice, Bob, leurs shifts et une demande acceptée par Bob.
     */
    private function acceptedSwap(): array
    {
        [$alice, $bob, $a, $b, $swap] = $this->pendingSwap();
        app(SwapActions::class)->answer($bob, $swap, true);

        return [$alice, $bob, $a, $b, $swap->fresh()];
    }

    /**
     * @return array{User, User, Shift, Shift, ShiftSwapRequest}
     */
    private function pendingSwap(): array
    {
        $alice = $this->employeeOf($this->a, ['name' => 'Alice Requester']);
        $bob = $this->employeeOf($this->a, ['name' => 'Bob Colleague']);
        $a = $this->shift($alice, $this->a, 10);
        $b = $this->shift($bob, $this->a, 12, '13:00', '21:00');
        $swap = app(SwapActions::class)->request($alice, $a->id, $b->id, 'Dentist');

        return [$alice, $bob, $a, $b, $swap];
    }

    private function notified(User $user, string $type): int
    {
        return $user->notifications()->where('type', $type)->count();
    }

    private function holder(Shift $shift): ?int
    {
        return Shift::withTrashed()->find($shift->id)->user_id;
    }

    private function activeShiftOf(User $user): ?Shift
    {
        return Shift::query()->where('user_id', $user->id)->orderBy('starts_at')->first();
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_when_enabled(): void
    {
        $this->enableBundle('shift-swap');

        foreach (['view', 'create', 'update', 'approve', 'delete'] as $action) {
            $this->assertTrue(app(PermissionRegistry::class)->has('swaps.'.$action));
        }
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('swaps'));
        $this->assertTrue(app(NavigationRegistry::class)->has('shift-swap'));
        $this->assertTrue(app(NavigationRegistry::class)->has('shift-swap-manage'));
        $this->assertFalse(app(PendingRequestRegistry::class)->isEmpty());
        foreach (['swap_requested', 'swap_peer_accepted', 'swap_peer_refused', 'swap_to_approve', 'swap_accepted', 'swap_refused', 'swap_cancelled', 'swap_applied', 'swap_cancelled_restored'] as $type) {
            $this->assertTrue(app(NotificationTypeRegistry::class)->has($type), $type);
        }
        $this->assertInstanceOf(ShiftExchanger::class, app(ShiftExchanger::class));
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('swaps.view'));
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('swaps'));
        $this->assertFalse(app(NavigationRegistry::class)->has('shift-swap'));
        $this->assertTrue(app(PendingRequestRegistry::class)->isEmpty());

        $this->actingAs($this->employeeOf($this->a))->get('/shift-swap')->assertNotFound();
        $this->actingAs($this->owner())->get('/shift-swap/manage')->assertNotFound();
        $this->assertTrue(Schema::hasTable('shift_swap_requests'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enableBundle('shift-swap');

        $this->get('/shift-swap')->assertRedirect(route('login'));
    }

    public function test_the_first_activation_gives_the_manager_role_every_right_and_employees_none(): void
    {
        app(EnableBundle::class)->handle($this->owner(), 'shift-swap');
        $this->app->register(ShiftSwapServiceProvider::class, true);

        $manager = $this->managerOf($this->a);
        $employee = $this->employeeOf($this->a);

        foreach (['view', 'create', 'update', 'approve', 'delete'] as $action) {
            $this->assertTrue(Gate::forUser($manager)->allows('swaps.'.$action, $this->a), $action);
            $this->assertFalse(Gate::forUser($employee)->allows('swaps.'.$action, $this->a), $action);
        }
    }

    // -- Demander ------------------------------------------------------------------------------------------------

    public function test_an_employee_requests_a_swap_without_any_permission(): void
    {
        $this->enableBundle('shift-swap');
        $alice = $this->employeeOf($this->a);
        $bob = $this->employeeOf($this->a, ['name' => 'Bob Colleague']);
        $mine = $this->shift($alice, $this->a, 10);
        $theirs = $this->shift($bob, $this->a, 12);

        Livewire::actingAs($alice)->test('shift-swap.create')
            ->set('colleagueId', (string) $bob->id)->set('myShiftId', (string) $mine->id)->set('theirShiftId', (string) $theirs->id)->set('reason', '  Dentist  ')
            ->assertSee('Bob Colleague')
            ->call('submit')->assertHasNoErrors()->assertRedirect();

        $swap = ShiftSwapRequest::firstOrFail();
        $this->assertSame([$alice->id, $bob->id, $mine->id, $theirs->id], [$swap->requester_id, $swap->target_user_id, $swap->requester_shift_id, $swap->target_shift_id]);
        $this->assertSame('pending', $swap->status);
        $this->assertNull($swap->accepted_at);
        $this->assertSame('Dentist', $swap->reason);
        $this->assertSame($mine->ical_sequence, $swap->requester_shift_sequence);
        $this->assertNotNull(ActivityEntry::where('action', 'swap.created')->where('actor_id', $alice->id)->first());

        $this->assertSame(1, $this->notified($bob, 'swap_requested'));
        $this->assertSame(0, $alice->notifications()->count());
        $notification = $bob->notifications()->where('type', 'swap_requested')->firstOrFail();
        $this->assertSame($swap->id, $notification->data['reference_id']);
        $this->assertSame('swap', $notification->data['reference_type']);
    }

    public function test_the_create_page_lists_the_colleagues_and_upcoming_shifts_of_the_stores_of_the_employee(): void
    {
        $this->enableBundle('shift-swap');
        $alice = $this->employeeOf($this->a);
        $bob = $this->employeeOf($this->a, ['name' => 'Bob Colleague']);
        $elsewhere = $this->employeeOf($this->b, ['name' => 'Eve Elsewhere']);
        $this->shift($alice, $this->a, 10);
        $this->shift($alice, $this->a, -3);
        $this->shift($bob, $this->a, 12, '13:00', '21:00');

        Livewire::actingAs($alice)->test('shift-swap.create')
            ->assertSee('Bob Colleague')->assertDontSee('Eve Elsewhere')
            ->assertSee($this->day(10).' 09:00-17:00')->assertDontSee($this->day(-3))
            ->set('colleagueId', (string) $bob->id)->assertSee($this->day(12).' 13:00-21:00');
        $this->assertNotNull($elsewhere);
    }

    public function test_an_employee_cannot_swap_the_shift_of_somebody_else(): void
    {
        $this->enableBundle('shift-swap');
        $alice = $this->employeeOf($this->a);
        $bob = $this->employeeOf($this->a);
        $carol = $this->employeeOf($this->a);
        $notMine = $this->shift($bob, $this->a, 10);
        $theirs = $this->shift($carol, $this->a, 12);

        try {
            app(SwapActions::class)->request($alice, $notMine->id, $theirs->id, null);
            $this->fail('Swapping the shift of somebody else must be refused.');
        } catch (AuthorizationException) {
            $this->assertSame(0, ShiftSwapRequest::count());
        }

        Livewire::actingAs($alice)->test('shift-swap.create')
            ->set('colleagueId', (string) $carol->id)->set('myShiftId', (string) $notMine->id)->set('theirShiftId', (string) $theirs->id)
            ->call('submit')->assertForbidden();
    }

    public function test_a_swap_needs_active_membership_the_feature_and_valid_shifts(): void
    {
        $this->enableBundle('shift-swap');
        $alice = $this->employeeOf($this->a);
        $bob = $this->employeeOf($this->a);
        $mine = $this->shift($alice, $this->a, 10);
        $theirs = $this->shift($bob, $this->a, 12);
        $actions = app(SwapActions::class);

        $refused = function (callable $call): void {
            try {
                $call();
                $this->fail('The request should be refused.');
            } catch (ValidationException) {
                $this->assertSame(0, ShiftSwapRequest::count());
            }
        };

        // Autre magasin pour le collègue.
        $stranger = $this->employeeOf($this->b);
        $refused(fn () => $actions->request($alice, $mine->id, $this->shift($stranger, $this->b, 12)->id, null));
        // Shift passé, shift ouvert, son propre shift, shift supprimé.
        $refused(fn () => $actions->request($alice, $this->shift($alice, $this->a, -2)->id, $theirs->id, null));
        $refused(fn () => $actions->request($alice, $mine->id, Shift::factory()->open()->create(['store_id' => $this->a->id, 'shift_date' => $this->day(11), 'starts_at' => Shift::localTime($this->day(11), '09:00'), 'ends_at' => Shift::localTime($this->day(11), '17:00')])->id, null));
        $refused(fn () => $actions->request($alice, $mine->id, $this->shift($alice, $this->a, 15)->id, null));
        $deleted = $this->shift($bob, $this->a, 13);
        $deleted->delete();
        $refused(fn () => $actions->request($alice, $mine->id, $deleted->id, null));
        // Collègue qui a quitté le magasin.
        $left = $this->employeeOf($this->a);
        $leftShift = $this->shift($left, $this->a, 14);
        $this->a->members()->updateExistingPivot($left->id, ['is_active' => false]);
        $refused(fn () => $actions->request($alice, $mine->id, $leftShift->id, null));
        // Fonctionnalité désactivée sur le magasin.
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'swaps', 'enabled' => false]);
        $refused(fn () => $actions->request($alice, $mine->id, $theirs->id, null));
        $this->actingAs($alice)->get('/shift-swap/create')->assertNotFound();
    }

    public function test_one_shift_cannot_be_part_of_two_pending_requests(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, , $a, $b] = $this->pendingSwap();
        $carol = $this->employeeOf($this->a);
        $c = $this->shift($carol, $this->a, 14);

        $this->expectException(ValidationException::class);

        try {
            app(SwapActions::class)->request($carol, $c->id, $a->id, null);
        } finally {
            $this->assertSame(1, ShiftSwapRequest::count());
        }
        $this->assertNotNull($alice);
        $this->assertNotNull($b);
    }

    // -- Répondre, annuler ---------------------------------------------------------------------------------------

    public function test_the_colleague_accepts_and_the_requester_and_the_approvers_are_told(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, $bob, , , $swap] = $this->pendingSwap();
        $approver = $this->managerWith(['swaps.approve'], [$this->a]);
        $viewer = $this->managerWith(['swaps.view'], [$this->a]);
        $otherStore = $this->managerWith(['swaps.approve'], [$this->b]);

        Livewire::actingAs($bob)->test('shift-swap.index')->assertSee('Alice Requester')->call('accept', $swap->id)->assertHasNoErrors();

        $swap->refresh();
        $this->assertNotNull($swap->accepted_at);
        $this->assertSame('pending', $swap->status);
        $this->assertSame('waiting_manager', $swap->stage());
        $this->assertSame(1, $this->notified($alice, 'swap_peer_accepted'));
        $this->assertSame(1, $this->notified($approver, 'swap_to_approve'));
        $this->assertSame(0, $this->notified($viewer, 'swap_to_approve'));
        $this->assertSame(0, $this->notified($otherStore, 'swap_to_approve'));
        $this->assertSame(0, $this->notified($bob, 'swap_peer_accepted'));
        $this->assertNotNull(ActivityEntry::where('action', 'swap.peer_accepted')->first());

        // Une réponse ne se rejoue pas.
        Livewire::actingAs($bob)->test('shift-swap.index')->call('accept', $swap->id);
        $this->assertSame(1, $this->notified($alice, 'swap_peer_accepted'));
    }

    public function test_the_colleague_declines_and_the_request_is_closed(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, $bob, , , $swap] = $this->pendingSwap();

        Livewire::actingAs($bob)->test('shift-swap.index')->call('confirmRefusal', $swap->id)->call('refuse')->assertHasNoErrors();

        $this->assertSame('refused', $swap->fresh()->status);
        $this->assertSame(1, $this->notified($alice, 'swap_peer_refused'));
        $this->assertNotNull(ActivityEntry::where('action', 'swap.peer_refused')->first());

        Livewire::actingAs($bob)->test('shift-swap.index')->call('accept', $swap->id);
        $this->assertNull($swap->fresh()->accepted_at);
    }

    public function test_only_the_colleague_can_answer_and_only_the_requester_can_cancel(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, $bob, , , $swap] = $this->pendingSwap();
        $carol = $this->employeeOf($this->a);

        Livewire::actingAs($carol)->test('shift-swap.index')->call('accept', $swap->id)->assertNotFound();
        Livewire::actingAs($carol)->test('shift-swap.index')->call('confirmCancellation', $swap->id)->assertNotFound();
        Livewire::actingAs($alice)->test('shift-swap.index')->call('accept', $swap->id)->assertNotFound();
        Livewire::actingAs($bob)->test('shift-swap.index')->call('confirmCancellation', $swap->id)->assertNotFound();

        try {
            app(SwapActions::class)->answer($carol, $swap, true);
            $this->fail('A third party cannot answer.');
        } catch (AuthorizationException) {
            $this->assertNull($swap->fresh()->accepted_at);
        }
    }

    public function test_the_requester_cancels_while_the_request_is_pending(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, $bob, , , $swap] = $this->pendingSwap();

        Livewire::actingAs($alice)->test('shift-swap.index')->call('confirmCancellation', $swap->id)->call('cancel')->assertHasNoErrors();

        $this->assertSame('cancelled', $swap->fresh()->status);
        $this->assertSame(1, $this->notified($bob, 'swap_cancelled'));
        $this->assertNotNull(ActivityEntry::where('action', 'swap.cancelled')->first());

        // Plus rien à annuler ni à accepter.
        Livewire::actingAs($alice)->test('shift-swap.index')->call('confirmCancellation', $swap->id)->call('cancel');
        Livewire::actingAs($bob)->test('shift-swap.index')->call('accept', $swap->id);
        $this->assertSame('cancelled', $swap->fresh()->status);
        $this->assertSame(1, $this->notified($bob, 'swap_cancelled'));
    }

    // -- Approuver -----------------------------------------------------------------------------------------------

    public function test_a_manager_approves_and_the_core_swaps_the_shifts(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, $bob, $a, $b, $swap] = $this->acceptedSwap();
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        Livewire::actingAs($manager)->test('shift-swap.manage')->assertSee('Alice Requester')->call('approve', $swap->id)->assertHasNoErrors();

        $swap->refresh();
        $this->assertSame('accepted', $swap->status);
        $this->assertSame($manager->id, $swap->approved_by_id);
        $this->assertNotNull($swap->approved_at);

        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
        $newA = Shift::findOrFail($swap->applied_requester_shift_id);
        $newB = Shift::findOrFail($swap->applied_target_shift_id);
        $this->assertSame($bob->id, $newA->user_id);
        $this->assertSame($this->day(10), $newA->shift_date->toDateString());
        $this->assertSame($alice->id, $newB->user_id);
        $this->assertSame('13:00', $newB->localStartsAt->format('H:i'));
        $this->assertSame($newA->id, Shift::withTrashed()->find($a->id)->replaced_by_id);

        $this->assertSame(1, $this->notified($alice, 'swap_accepted'));
        $this->assertSame(1, $this->notified($bob, 'swap_accepted'));
        $this->assertSame(0, $this->notified($manager, 'swap_accepted'));
        $this->assertNotNull(ActivityEntry::where('action', 'swap.approved')->where('actor_id', $manager->id)->first());
    }

    public function test_a_swap_waiting_for_the_colleague_cannot_be_approved(): void
    {
        $this->enableBundle('shift-swap');
        [, , $a, $b, $swap] = $this->pendingSwap();
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        Livewire::actingAs($manager)->test('shift-swap.manage')->assertDontSee('data-test="approve-swap-button"', false)->call('approve', $swap->id);

        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertNotSame($a->id, null);
        $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
        $this->assertNull(Shift::withTrashed()->find($b->id)->deleted_at);
    }

    public function test_approval_needs_the_right_in_the_store_of_the_request(): void
    {
        $this->enableBundle('shift-swap');
        [, , $a, , $swap] = $this->acceptedSwap();
        $viewer = $this->managerWith(['swaps.view', 'shifts.update', 'shifts.create'], [$this->a]);
        $elsewhere = $this->managerWith($this->managerRights(), [$this->b]);
        $noShiftRights = $this->managerWith(['swaps.view', 'swaps.approve'], [$this->a]);

        Livewire::actingAs($viewer)->test('shift-swap.manage')->call('approve', $swap->id)->assertForbidden();
        Livewire::actingAs($elsewhere)->test('shift-swap.manage')->call('approve', $swap->id)->assertNotFound();
        Livewire::actingAs($noShiftRights)->test('shift-swap.manage')->call('approve', $swap->id)->assertForbidden();
        Livewire::actingAs($this->employeeOf($this->a))->test('shift-swap.manage')->assertForbidden();

        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
    }

    public function test_planning_warnings_ask_for_a_confirmation_and_nothing_is_written_before(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, , $a, $b, $swap] = $this->acceptedSwap();
        // Alice a déjà un shift qui chevauche celui de Bob qu'elle recevrait.
        $this->shift($alice, $this->a, 12, '14:00', '16:00');
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        $component = Livewire::actingAs($manager)->test('shift-swap.manage')
            ->call('approve', $swap->id)
            ->assertSet('pending', 'approve');
        $this->assertCount(1, $component->get('warnings'));
        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
        $this->assertNull(Shift::withTrashed()->find($b->id)->deleted_at);

        $component->call('confirmWarnings')->assertHasNoErrors()->assertSet('pending', '');

        $this->assertSame('accepted', $swap->fresh()->status);
        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
    }

    public function test_an_approved_time_off_blocks_the_approval_even_when_confirmed(): void
    {
        $this->enableBundle('shift-swap');
        [, $bob, $a, , $swap] = $this->acceptedSwap();
        $blocked = $this->day(10);
        $this->app->bind(TimeOffChecker::class, fn () => new class($bob->id, $blocked) implements TimeOffChecker
        {
            public function __construct(private readonly int $userId, private readonly string $date) {}

            public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool
            {
                return $userId === $this->userId && $date->toDateString() === $this->date;
            }
        });
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        $component = Livewire::actingAs($manager)->test('shift-swap.manage')->call('approve', $swap->id);
        $this->assertSame([], $component->get('warnings'));
        $component->assertSet('pending', '');

        try {
            app(SwapActions::class)->approve($manager, $swap, true);
            $this->fail('A time off blocks the swap.');
        } catch (ValidationException) {
            $this->assertSame('pending', $swap->fresh()->status);
            $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
        }
    }

    public function test_a_shift_modified_deleted_or_reassigned_since_the_request_is_refused_cleanly(): void
    {
        $this->enableBundle('shift-swap');
        $manager = $this->managerWith($this->managerRights(), [$this->a]);
        $cases = [
            'modified' => fn (Shift $a, Shift $b) => $a->update(['notes' => 'Changed']),
            'deleted' => fn (Shift $a, Shift $b) => $b->delete(),
            'reassigned' => fn (Shift $a, Shift $b) => $a->update(['user_id' => $this->employeeOf($this->a)->id]),
        ];

        foreach ($cases as $label => $change) {
            ShiftSwapRequest::query()->delete();
            Shift::withTrashed()->forceDelete();
            [, , $a, $b, $swap] = $this->acceptedSwap();
            $change($a, $b);
            $before = Shift::withTrashed()->count();

            try {
                app(SwapActions::class)->approve($manager, $swap, true);
                $this->fail("A {$label} shift must refuse the approval.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('request', $e->errors(), $label);
            }

            $this->assertSame('pending', $swap->fresh()->status, $label);
            $this->assertSame($before, Shift::withTrashed()->count(), $label);
        }
    }

    public function test_a_shift_changed_after_the_request_cannot_be_accepted_either(): void
    {
        $this->enableBundle('shift-swap');
        [, $bob, $a, , $swap] = $this->pendingSwap();
        $a->update(['starts_at' => $a->starts_at->copy()->addHour()]);

        Livewire::actingAs($bob)->test('shift-swap.index')->call('accept', $swap->id);

        $this->assertNull($swap->fresh()->accepted_at);
    }

    public function test_a_decision_cannot_be_replayed(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, $bob, , , $swap] = $this->acceptedSwap();
        $manager = $this->managerWith($this->managerRights(), [$this->a]);
        $second = $this->managerWith($this->managerRights(), [$this->a]);

        app(SwapActions::class)->approve($manager, $swap, false);
        $applied = Shift::count();

        // Une seconde approbation (autre gérant, onglet obsolète) ne ré-échange rien.
        try {
            app(SwapActions::class)->approve($second, $swap, true);
            $this->fail('A swap is approved only once.');
        } catch (ValidationException) {
            $this->assertSame($applied, Shift::count());
        }
        // Ni un refus, ni une réponse, ni une annulation après coup.
        try {
            app(SwapActions::class)->refuse($second, $swap);
            $this->fail('An applied swap cannot be refused.');
        } catch (ValidationException) {
            $this->assertSame('accepted', $swap->fresh()->status);
        }
        $this->assertSame(1, $this->notified($alice, 'swap_accepted'));
        $this->assertSame(1, $this->notified($bob, 'swap_accepted'));
    }

    public function test_a_manager_refuses_a_pending_request(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, , $a, , $swap] = $this->acceptedSwap();
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        Livewire::actingAs($manager)->test('shift-swap.manage')->call('confirmRefusal', $swap->id)->call('refuse')->assertHasNoErrors();

        $this->assertSame('refused', $swap->fresh()->status);
        $this->assertSame(1, $this->notified($alice, 'swap_refused'));
        $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
        $this->assertNotNull(ActivityEntry::where('action', 'swap.refused')->first());
    }

    // -- Échange appliqué d'emblée -------------------------------------------------------------------------------

    public function test_a_manager_applies_a_swap_right_away(): void
    {
        $this->enableBundle('shift-swap');
        $alice = $this->employeeOf($this->a);
        $bob = $this->employeeOf($this->a);
        $a = $this->shift($alice, $this->a, 10);
        $b = $this->shift($bob, $this->a, 12);
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        Livewire::actingAs($manager)->test('shift-swap.manage')
            ->set('directStore', (string) $this->a->id)->set('firstUser', (string) $alice->id)->set('secondUser', (string) $bob->id)
            ->assertSee($this->day(10).' 09:00-17:00')
            ->set('firstShift', (string) $a->id)->set('secondShift', (string) $b->id)->set('directReason', 'Training')
            ->call('createDirect')->assertHasNoErrors();

        $swap = ShiftSwapRequest::firstOrFail();
        $this->assertSame('accepted', $swap->status);
        $this->assertSame($manager->id, $swap->approved_by_id);
        $this->assertSame('Training', $swap->reason);
        $this->assertSame($bob->id, Shift::findOrFail($swap->applied_requester_shift_id)->user_id);
        $this->assertSoftDeleted($a);
        $this->assertSame(1, $this->notified($alice, 'swap_applied'));
        $this->assertSame(1, $this->notified($bob, 'swap_applied'));
        $this->assertNotNull(ActivityEntry::where('action', 'swap.created_by_manager')->first());
    }

    public function test_the_direct_swap_needs_the_create_right_and_matching_shifts(): void
    {
        $this->enableBundle('shift-swap');
        $alice = $this->employeeOf($this->a);
        $bob = $this->employeeOf($this->a);
        $carol = $this->employeeOf($this->b);
        $a = $this->shift($alice, $this->a, 10);
        $b = $this->shift($bob, $this->a, 12);
        $c = $this->shift($carol, $this->b, 12);
        $manager = $this->managerWith($this->managerRights(), [$this->a]);
        $noCreate = $this->managerWith(['swaps.view', 'swaps.approve', 'shifts.create', 'shifts.update'], [$this->a]);
        $actions = app(SwapActions::class);

        foreach ([
            fn () => $actions->createApplied($noCreate, $a->id, $b->id, null),
            fn () => $actions->createApplied($manager, $a->id, $c->id, null),
            fn () => $actions->createApplied($this->employeeOf($this->a), $a->id, $b->id, null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The direct swap should be refused.');
            } catch (AuthorizationException|ValidationException) {
                $this->assertSame(0, ShiftSwapRequest::count());
                $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
            }
        }

        try {
            $actions->createApplied($manager, $a->id, $this->shift($alice, $this->a, 15)->id, null);
            $this->fail('Two shifts of the same employee cannot be swapped.');
        } catch (ValidationException) {
            $this->assertSame(0, ShiftSwapRequest::count());
        }

        Livewire::actingAs($manager)->test('shift-swap.manage')
            ->set('directStore', (string) $this->a->id)->set('firstUser', (string) $alice->id)->set('secondUser', (string) $bob->id)
            ->set('firstShift', (string) $b->id)->set('secondShift', (string) $a->id)
            ->call('createDirect')->assertHasErrors('secondShift');
        $this->assertSame(0, ShiftSwapRequest::count());
    }

    public function test_the_direct_swap_confirms_planning_warnings(): void
    {
        $this->enableBundle('shift-swap');
        $alice = $this->employeeOf($this->a);
        $bob = $this->employeeOf($this->a);
        $a = $this->shift($alice, $this->a, 10);
        $b = $this->shift($bob, $this->a, 12);
        $this->shift($bob, $this->a, 10, '10:00', '12:00');
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        $component = Livewire::actingAs($manager)->test('shift-swap.manage')
            ->set('directStore', (string) $this->a->id)->set('firstUser', (string) $alice->id)->set('secondUser', (string) $bob->id)
            ->set('firstShift', (string) $a->id)->set('secondShift', (string) $b->id)
            ->call('createDirect')->assertSet('pending', 'direct');
        $this->assertCount(1, $component->get('warnings'));
        $this->assertSame(0, ShiftSwapRequest::count());

        $component->call('confirmWarnings');

        $this->assertSame(1, ShiftSwapRequest::count());
        $this->assertSoftDeleted($a);
    }

    // -- Supprimer -----------------------------------------------------------------------------------------------

    public function test_deleting_an_applied_swap_puts_the_shifts_back(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, $bob, , , $swap] = $this->acceptedSwap();
        $manager = $this->managerWith($this->managerRights(), [$this->a]);
        app(SwapActions::class)->approve($manager, $swap, false);
        $newA = $swap->fresh()->applied_requester_shift_id;

        Livewire::actingAs($manager)->test('shift-swap.manage')->call('confirmDeletion', $swap->id)->call('delete')->assertHasNoErrors();

        $this->assertDatabaseMissing('shift_swap_requests', ['id' => $swap->id]);
        $this->assertSoftDeleted(Shift::withTrashed()->find($newA));
        $this->assertSame($alice->id, Shift::query()->where('shift_date', $this->day(10))->value('user_id'));
        $this->assertSame($bob->id, Shift::query()->where('shift_date', $this->day(12))->value('user_id'));
        $this->assertSame(1, $this->notified($alice, 'swap_cancelled_restored'));
        $this->assertSame(1, $this->notified($bob, 'swap_cancelled_restored'));
        $this->assertNotNull(ActivityEntry::where('action', 'swap.deleted')->first());
    }

    public function test_deleting_does_not_touch_shifts_that_moved_since_the_swap(): void
    {
        $this->enableBundle('shift-swap');
        [, $bob, , , $swap] = $this->acceptedSwap();
        $manager = $this->managerWith($this->managerRights(), [$this->a]);
        app(SwapActions::class)->approve($manager, $swap, false);
        $swap->refresh();
        $reassigned = $this->employeeOf($this->a);
        Shift::findOrFail($swap->applied_requester_shift_id)->update(['user_id' => $reassigned->id]);
        $count = Shift::count();

        app(SwapActions::class)->delete($manager, $swap);

        $this->assertSame($count, Shift::count());
        $this->assertSame($reassigned->id, Shift::findOrFail($swap->applied_requester_shift_id)->user_id);
        $this->assertSame(0, $this->notified($bob, 'swap_cancelled_restored'));
        $this->assertDatabaseMissing('shift_swap_requests', ['id' => $swap->id]);
    }

    public function test_deleting_a_pending_swap_touches_no_shift_and_needs_the_delete_right(): void
    {
        $this->enableBundle('shift-swap');
        [, , $a, , $swap] = $this->pendingSwap();
        $noDelete = $this->managerWith(['swaps.view', 'swaps.approve'], [$this->a]);

        Livewire::actingAs($noDelete)->test('shift-swap.manage')->call('confirmDeletion', $swap->id)->assertForbidden();
        $this->assertDatabaseHas('shift_swap_requests', ['id' => $swap->id]);

        app(SwapActions::class)->delete($this->managerWith($this->managerRights(), [$this->a]), $swap);

        $this->assertDatabaseMissing('shift_swap_requests', ['id' => $swap->id]);
        $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
    }

    // -- Portée magasin ------------------------------------------------------------------------------------------

    public function test_the_manager_list_is_limited_to_the_stores_of_the_manager(): void
    {
        $this->enableBundle('shift-swap');
        $this->pendingSwap();
        $carol = $this->employeeOf($this->b, ['name' => 'Carol Beta']);
        $dan = $this->employeeOf($this->b, ['name' => 'Dan Beta']);
        app(SwapActions::class)->request($carol, $this->shift($carol, $this->b, 10)->id, $this->shift($dan, $this->b, 11)->id, null);
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        Livewire::actingAs($manager)->test('shift-swap.manage')->assertSee('Alice Requester')->assertDontSee('Carol Beta');
        Livewire::actingAs($this->owner())->test('shift-swap.manage')->assertSee('Alice Requester')->assertSee('Carol Beta');
        Livewire::actingAs($manager)->test('shift-swap.manage')->set('status', 'refused')->assertDontSee('Alice Requester');
    }

    // -- Tableau de bord, demandes en attente ---------------------------------------------------------------------

    public function test_pending_requests_count_what_each_person_has_to_do_or_follow(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, $bob, , , $swap] = $this->pendingSwap();
        $registry = app(PendingRequestRegistry::class);
        $count = fn (User $user): int => $registry->counts($user)[0]['count'];
        $manager = $this->managerWith(['swaps.approve'], [$this->a]);

        $this->assertSame(1, $count($alice), 'The requester follows the request.');
        $this->assertSame(1, $count($bob), 'The colleague has to answer.');
        $this->assertSame(0, $count($manager), 'A manager decides only what the colleague accepted.');

        app(SwapActions::class)->answer($bob, $swap, true);

        $this->assertSame(1, $count($alice));
        $this->assertSame(0, $count($bob));
        $this->assertSame(1, $count($manager));
        $this->assertSame(0, $count($this->managerWith(['swaps.approve'], [$this->b])));
        $this->assertSame(0, $count($this->employeeOf($this->a)));
    }

    public function test_the_widgets_show_what_to_answer_or_to_approve(): void
    {
        $this->enableBundle('shift-swap');
        [, $bob, , , $swap] = $this->pendingSwap();
        $manager = $this->managerWith($this->managerRights(), [$this->a]);

        Livewire::actingAs($bob)->test('shift-swap.employee-widget')->assertSee('Swaps awaiting my answer')->assertSee('Alice Requester');
        Livewire::actingAs($manager)->test('shift-swap.manager-widget')->assertSee('Swaps to approve')->assertSee('No pending requests.')->assertDontSee('Alice Requester');

        app(SwapActions::class)->answer($bob, $swap, true);

        Livewire::actingAs($bob)->test('shift-swap.employee-widget')->assertSee('No pending requests.');
        Livewire::actingAs($manager)->test('shift-swap.manager-widget')->assertSee('Alice Requester')->assertSee('Bob Colleague');
        Livewire::actingAs($this->employeeOf($this->b))->test('shift-swap.employee-widget')->assertSee('No pending requests.');
    }

    public function test_notifications_are_marked_read_when_the_employee_opens_the_page(): void
    {
        $this->enableBundle('shift-swap');
        [, $bob] = $this->pendingSwap();
        $this->assertSame(1, $bob->unreadNotifications()->where('type', 'swap_requested')->count());

        Livewire::actingAs($bob)->test('shift-swap.index');

        $this->assertSame(0, $bob->unreadNotifications()->where('type', 'swap_requested')->count());
    }

    // -- Données -------------------------------------------------------------------------------------------------

    public function test_disabling_the_bundle_keeps_the_data(): void
    {
        $this->enableBundle('shift-swap');
        [, , , , $swap] = $this->pendingSwap();

        app(BundleManager::class)->disable('shift-swap');

        $this->assertDatabaseHas('shift_swap_requests', ['id' => $swap->id]);
    }

    public function test_anonymising_an_account_erases_the_reasons_but_keeps_the_swap(): void
    {
        $this->enableBundle('shift-swap');
        [$alice, , , , $swap] = $this->pendingSwap();
        $this->assertSame('Dentist', $swap->reason);

        app(AnonymizeUser::class)->handle($alice);

        $this->assertNull($swap->fresh()->reason);
        $this->assertDatabaseHas('shift_swap_requests', ['id' => $swap->id]);
    }
}
