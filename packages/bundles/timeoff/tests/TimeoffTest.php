<?php

namespace Kintai\Bundles\Timeoff\Tests;

use App\Actions\AnonymizeUser;
use App\Actions\Bundles\EnableBundle;
use App\Bundles\BundleManager;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use App\Planning\ShiftGuard;
use App\Planning\ShiftIssueKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\Timeoff\ApprovedTimeOff;
use Kintai\Bundles\Timeoff\LeaveStatistics;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Kintai\Bundles\Timeoff\TimeoffServiceProvider;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Planning\TimeOffChecker;
use Kintai\Contracts\Planning\TimeOffProvider;
use Kintai\Contracts\Planning\TimeOffRequestSummary;
use Kintai\Contracts\Planning\TimeOffStatistics;
use Kintai\Contracts\Requests\PendingRequestRegistry;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Congés : demande, saisie par un gérant, décision, annulation, suppression, portée magasin, intégration au Core
 * (garde-fou du planning, flux iCal, demandes en attente), notifications, widgets, bundle désactivé.
 */
class TimeoffTest extends TestCase
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
     * Gérant (rôle sur mesure) membre des magasins donnés, avec les permissions de congé données.
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function leave(User $user, Store $store, string $status = 'pending', array $attributes = []): TimeoffRequest
    {
        return TimeoffRequest::create([
            'user_id' => $user->id, 'store_id' => $store->id, 'type' => 'vacation', 'status' => $status,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-03', ...$attributes,
        ]);
    }

    private function allRights(): array
    {
        return ['timeoff.view', 'timeoff.create', 'timeoff.update', 'timeoff.approve', 'timeoff.delete'];
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_when_enabled(): void
    {
        $this->enableBundle('timeoff');

        foreach ($this->allRights() as $permission) {
            $this->assertTrue(app(PermissionRegistry::class)->has($permission));
        }
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('timeoff'));
        $this->assertTrue(app(NavigationRegistry::class)->has('timeoff'));
        $this->assertTrue(app(NavigationRegistry::class)->has('timeoff-manage'));
        $this->assertFalse(app(PendingRequestRegistry::class)->isEmpty());
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('timeoff_approved'));
        $this->assertInstanceOf(ApprovedTimeOff::class, app(TimeOffChecker::class));
        $this->assertInstanceOf(ApprovedTimeOff::class, app(TimeOffProvider::class));
        $this->assertInstanceOf(LeaveStatistics::class, app(TimeOffStatistics::class));
    }

    public function test_a_disabled_bundle_is_absent_everywhere_and_the_core_knows_no_leave(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('timeoff.view'));
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('timeoff'));
        $this->assertFalse(app(NavigationRegistry::class)->has('timeoff'));
        $this->assertTrue(app(PendingRequestRegistry::class)->isEmpty());
        $this->assertNotInstanceOf(ApprovedTimeOff::class, app(TimeOffChecker::class));
        $this->assertNotInstanceOf(ApprovedTimeOff::class, app(TimeOffProvider::class));
        $this->assertNotInstanceOf(LeaveStatistics::class, app(TimeOffStatistics::class));

        $this->actingAs($this->employeeOf($this->a))->get('/timeoff')->assertNotFound();
        $this->actingAs($this->owner())->get('/timeoff/manage')->assertNotFound();
        $this->assertTrue(Schema::hasTable('timeoff_requests'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enableBundle('timeoff');

        $this->get('/timeoff')->assertRedirect(route('login'));
    }

    // -- Demander ------------------------------------------------------------------------------------------------

    public function test_an_employee_asks_for_a_leave_without_any_permission(): void
    {
        $this->enableBundle('timeoff');
        $employee = User::factory()->create();
        $this->a->members()->attach($employee);

        Livewire::actingAs($employee)->test('timeoff.my-requests')
            ->set('type', 'sick')->set('startDate', '2026-12-01')->set('endDate', '2026-12-03')->set('reason', '  Flu  ')
            ->call('submit')->assertHasNoErrors()
            ->assertSee('Alpha')->assertSee('2026-12-03');

        $request = TimeoffRequest::firstOrFail();
        $this->assertSame([$employee->id, $this->a->id, 'sick', 'pending', 'Flu'], [$request->user_id, $request->store_id, $request->type, $request->status, $request->reason]);
        $this->assertNotNull(ActivityEntry::where('action', 'timeoff.created')->where('actor_id', $employee->id)->first());
    }

    public function test_the_end_date_defaults_to_the_start_date_and_dates_are_validated(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);

        Livewire::actingAs($employee)->test('timeoff.my-requests')
            ->set('startDate', '2026-12-05')->call('submit')->assertHasNoErrors();
        $this->assertSame('2026-12-05', TimeoffRequest::firstOrFail()->end_date->toDateString());

        Livewire::actingAs($employee)->test('timeoff.my-requests')
            ->set('startDate', 'tomorrow')->call('submit')->assertHasErrors(['startDate'])
            ->set('startDate', '2026-12-05')->set('endDate', '2026-12-01')->call('submit')->assertHasErrors(['endDate'])
            ->set('endDate', '')->set('type', 'bogus')->call('submit')->assertHasErrors(['type']);
        $this->assertSame(1, TimeoffRequest::count());
    }

    public function test_an_employee_cannot_ask_in_a_store_they_do_not_belong_to(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);

        Livewire::actingAs($employee)->test('timeoff.my-requests')
            ->set('storeId', (string) $this->b->id)->set('startDate', '2026-12-01')->call('submit')->assertHasErrors(['store_id']);

        $this->assertSame(0, TimeoffRequest::count());
    }

    public function test_a_store_without_the_feature_or_no_store_means_no_page(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'timeoff', 'enabled' => false]);

        $this->actingAs($employee)->get('/timeoff')->assertNotFound();
        $this->actingAs($employee)->get(route('dashboard'))->assertDontSee(route('timeoff.index'), false);
        $this->actingAs(User::factory()->create())->get('/timeoff')->assertNotFound();
    }

    public function test_submitting_notifies_the_approvers_of_the_store_only(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $approver = $this->managerWith(['timeoff.approve'], [$this->a]);
        $viewer = $this->managerWith(['timeoff.view'], [$this->a]);
        $otherStore = $this->managerWith(['timeoff.approve'], [$this->b]);

        Livewire::actingAs($employee)->test('timeoff.my-requests')->set('startDate', '2026-12-01')->call('submit');

        $this->assertSame(1, $approver->notifications()->count());
        $this->assertSame('timeoff_submitted', $approver->notifications()->first()->type);
        $this->assertSame(0, $viewer->notifications()->count());
        $this->assertSame(0, $otherStore->notifications()->count());
        $this->assertSame(0, $employee->notifications()->count());
    }

    public function test_an_employee_cancels_only_their_own_pending_request(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $colleague = $this->employeeOf($this->a);
        $pending = $this->leave($employee, $this->a);
        $approved = $this->leave($employee, $this->a, 'approved');
        $foreign = $this->leave($colleague, $this->a);

        Livewire::actingAs($employee)->test('timeoff.my-requests')
            ->call('confirmCancellation', $pending->id)->assertSet('cancellingId', $pending->id)->call('cancel');
        $this->assertSame('cancelled', $pending->fresh()->status);

        Livewire::actingAs($employee)->test('timeoff.my-requests')->call('confirmCancellation', $foreign->id)->assertNotFound();
        $this->assertSame('pending', $foreign->fresh()->status);

        Livewire::actingAs($employee)->test('timeoff.my-requests')->call('confirmCancellation', $approved->id)->call('cancel')->assertHasErrors();
        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_the_page_lists_only_own_requests(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $colleague = $this->employeeOf($this->a);
        $this->leave($employee, $this->a, 'pending', ['start_date' => '2026-12-11', 'end_date' => '2026-12-11']);
        $this->leave($colleague, $this->a, 'pending', ['start_date' => '2027-01-21', 'end_date' => '2027-01-21']);

        $this->actingAs($employee)->get('/timeoff')->assertOk()->assertSee('2026-12-11')->assertDontSee('2027-01-21');
    }

    // -- Décider -------------------------------------------------------------------------------------------------

    public function test_a_manager_approves_and_the_employee_is_notified(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $manager = $this->managerWith(['timeoff.view', 'timeoff.approve'], [$this->a]);
        $request = $this->leave($employee, $this->a);

        Livewire::actingAs($manager)->test('timeoff.manage')->call('approve', $request->id)->assertHasNoErrors();

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($manager->id, $fresh->processed_by);
        $this->assertNotNull($fresh->processed_at);
        $notification = $employee->notifications()->firstOrFail();
        $this->assertSame('timeoff_approved', $notification->type);
        $this->assertSame($request->id, $notification->data['reference_id']);
        $this->assertNotNull(ActivityEntry::where('action', 'timeoff.approved')->first());
    }

    public function test_a_manager_refuses_with_a_note_and_the_employee_is_notified(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $manager = $this->managerWith(['timeoff.view', 'timeoff.approve'], [$this->a]);
        $request = $this->leave($employee, $this->a);

        Livewire::actingAs($manager)->test('timeoff.manage')
            ->call('confirmRefusal', $request->id)->assertSet('refusingId', $request->id)
            ->set('note', 'Too many people away')->call('refuse')->assertHasNoErrors();

        $this->assertSame(['refused', 'Too many people away'], [$request->fresh()->status, $request->fresh()->admin_note]);
        $this->assertSame('timeoff_refused', $employee->notifications()->firstOrFail()->type);
    }

    public function test_a_decision_is_final_and_only_a_pending_request_can_be_decided(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $manager = $this->managerWith(['timeoff.view', 'timeoff.approve'], [$this->a]);
        $cancelled = $this->leave($employee, $this->a, 'cancelled');
        $approved = $this->leave($employee, $this->a, 'approved');

        Livewire::actingAs($manager)->test('timeoff.manage')->call('approve', $cancelled->id)->assertHasErrors();
        Livewire::actingAs($manager)->test('timeoff.manage')->call('approve', $approved->id)->assertHasErrors();

        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame(0, $employee->notifications()->count(), 'No double notification.');
    }

    public function test_without_the_approve_permission_nothing_can_be_decided(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $viewer = $this->managerWith(['timeoff.view'], [$this->a]);
        $request = $this->leave($employee, $this->a);

        Livewire::actingAs($viewer)->test('timeoff.manage')->call('approve', $request->id)->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_manager_only_sees_and_decides_requests_of_their_stores(): void
    {
        $this->enableBundle('timeoff');
        $inA = $this->leave($this->employeeOf($this->a), $this->a, 'pending', ['start_date' => '2026-12-11', 'end_date' => '2026-12-11']);
        $inB = $this->leave($this->employeeOf($this->b), $this->b, 'pending', ['start_date' => '2027-01-21', 'end_date' => '2027-01-21']);
        $manager = $this->managerWith(['timeoff.view', 'timeoff.approve'], [$this->a]);

        Livewire::actingAs($manager)->test('timeoff.manage')->assertSee('2026-12-11')->assertDontSee('2027-01-21');
        Livewire::actingAs($manager)->test('timeoff.manage')->call('approve', $inB->id)->assertNotFound();
        $this->assertSame('pending', $inB->fresh()->status);
        $this->assertSame('pending', $inA->fresh()->status);
    }

    public function test_the_manage_page_needs_the_view_permission_and_filters_work(): void
    {
        $this->enableBundle('timeoff');
        $this->actingAs($this->employeeOf($this->a))->get('/timeoff/manage')->assertForbidden();

        $employee = $this->employeeOf($this->a);
        $this->leave($employee, $this->a, 'approved', ['type' => 'sick', 'start_date' => '2026-12-11', 'end_date' => '2026-12-11']);
        $this->leave($employee, $this->a, 'pending', ['type' => 'vacation', 'start_date' => '2027-01-21', 'end_date' => '2027-01-21']);
        $manager = $this->managerWith(['timeoff.view'], [$this->a]);

        Livewire::actingAs($manager)->test('timeoff.manage')
            ->assertSee('2026-12-11')->assertSee('2027-01-21')
            ->set('status', 'approved')->assertSee('2026-12-11')->assertDontSee('2027-01-21')
            ->set('status', '')->set('type', 'vacation')->assertSee('2027-01-21')->assertDontSee('2026-12-11')
            ->set('type', '')->set('sort', 'asc')->assertSeeInOrder(['2026-12-11', '2027-01-21']);
    }

    // -- Saisie par un gérant et suppression ---------------------------------------------------------------------

    public function test_a_manager_creates_an_approved_leave_for_an_employee(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $manager = $this->managerWith(['timeoff.view', 'timeoff.create'], [$this->a]);

        Livewire::actingAs($manager)->test('timeoff.manage')
            ->set('target', $employee->id.':'.$this->a->id)->set('newType', 'personal')->set('newStart', '2026-12-10')->set('newReason', 'Moving')
            ->call('create')->assertHasNoErrors();

        $request = TimeoffRequest::firstOrFail();
        $this->assertSame([$employee->id, 'approved', $manager->id, 'personal', '2026-12-10'], [$request->user_id, $request->status, $request->processed_by, $request->type, $request->end_date->toDateString()]);
        $this->assertSame('timeoff_approved', $employee->notifications()->firstOrFail()->type);
        $this->assertNotNull(ActivityEntry::where('action', 'timeoff.created_by_manager')->first());
    }

    public function test_a_manager_cannot_create_a_leave_outside_their_scope(): void
    {
        $this->enableBundle('timeoff');
        $elsewhere = $this->employeeOf($this->b);
        $manager = $this->managerWith(['timeoff.view', 'timeoff.create'], [$this->a]);

        Livewire::actingAs($manager)->test('timeoff.manage')
            ->set('target', $elsewhere->id.':'.$this->b->id)->set('newStart', '2026-12-10')->call('create')->assertForbidden();

        $this->assertSame(0, TimeoffRequest::count());

        $viewer = $this->managerWith(['timeoff.view'], [$this->a]);
        $colleague = $this->employeeOf($this->a);
        Livewire::actingAs($viewer)->test('timeoff.manage')
            ->set('target', $colleague->id.':'.$this->a->id)->set('newStart', '2026-12-10')->call('create')->assertForbidden();
        $this->assertSame(0, TimeoffRequest::count());
    }

    public function test_a_leave_can_only_be_created_for_a_member_of_the_store(): void
    {
        $this->enableBundle('timeoff');
        $elsewhere = $this->employeeOf($this->b);
        $manager = $this->managerWith(['timeoff.view', 'timeoff.create'], [$this->a]);

        Livewire::actingAs($manager)->test('timeoff.manage')
            ->set('target', $elsewhere->id.':'.$this->a->id)->set('newStart', '2026-12-10')->call('create')->assertHasErrors(['user_id']);
        $this->assertSame(0, TimeoffRequest::count());
    }

    public function test_deleting_needs_a_confirmation_and_the_delete_permission(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $approved = $this->leave($employee, $this->a, 'approved');
        $deleter = $this->managerWith(['timeoff.view', 'timeoff.delete'], [$this->a]);
        $viewer = $this->managerWith(['timeoff.view'], [$this->a]);

        Livewire::actingAs($viewer)->test('timeoff.manage')->call('confirmDeletion', $approved->id)->assertForbidden();
        $this->assertDatabaseHas('timeoff_requests', ['id' => $approved->id]);

        Livewire::actingAs($deleter)->test('timeoff.manage')
            ->call('confirmDeletion', $approved->id)->assertSet('deletingId', $approved->id)->call('delete');
        $this->assertDatabaseMissing('timeoff_requests', ['id' => $approved->id]);
        $this->assertNotNull(ActivityEntry::where('action', 'timeoff.deleted')->first());
    }

    public function test_the_owner_manages_every_store(): void
    {
        $this->enableBundle('timeoff');
        $inB = $this->leave($this->employeeOf($this->b), $this->b);

        Livewire::actingAs($this->owner())->test('timeoff.manage')->call('approve', $inB->id)->assertHasNoErrors();

        $this->assertSame('approved', $inB->fresh()->status);
    }

    // -- Intégration au Core -------------------------------------------------------------------------------------

    public function test_an_approved_leave_blocks_a_shift_but_a_pending_or_refused_one_does_not(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $this->leave($employee, $this->a, 'approved', ['start_date' => '2026-12-01', 'end_date' => '2026-12-03']);
        $this->leave($employee, $this->a, 'pending', ['start_date' => '2026-12-10', 'end_date' => '2026-12-10']);
        $this->leave($employee, $this->a, 'refused', ['start_date' => '2026-12-11', 'end_date' => '2026-12-11']);
        $this->leave($employee, $this->a, 'cancelled', ['start_date' => '2026-12-12', 'end_date' => '2026-12-12']);

        $kinds = fn (string $day): array => array_map(
            fn ($issue) => $issue->kind,
            app(ShiftGuard::class)->check(Shift::factory()->for($this->a)->make(['user_id' => $employee->id, 'shift_date' => $day])),
        );

        foreach (['2026-12-01', '2026-12-02', '2026-12-03'] as $day) {
            $this->assertContains(ShiftIssueKind::TimeOff, $kinds($day), $day);
        }
        foreach (['2026-11-30', '2026-12-04', '2026-12-10', '2026-12-11', '2026-12-12'] as $day) {
            $this->assertNotContains(ShiftIssueKind::TimeOff, $kinds($day), $day);
        }
    }

    public function test_the_checker_is_per_employee(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $other = $this->employeeOf($this->a);
        $this->leave($employee, $this->a, 'approved');

        $this->assertTrue(app(TimeOffChecker::class)->hasApprovedTimeOff($employee->id, Carbon::parse('2026-12-02')));
        $this->assertFalse(app(TimeOffChecker::class)->hasApprovedTimeOff($other->id, Carbon::parse('2026-12-02')));
    }

    public function test_the_statistics_publish_every_status_of_the_store_for_the_users_and_the_period(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $other = $this->employeeOf($this->a);
        foreach (['pending', 'approved', 'refused', 'cancelled'] as $i => $status) {
            $this->leave($employee, $this->a, $status, ['type' => ['vacation', 'sick', 'personal', 'unpaid'][$i], 'start_date' => '2026-12-0'.($i + 1), 'end_date' => '2026-12-0'.($i + 1)]);
        }
        $this->leave($employee, $this->b, 'approved');
        $this->leave($other, $this->a, 'pending', ['type' => 'other']);
        $this->leave($employee, $this->a, 'approved', ['start_date' => '2027-03-01', 'end_date' => '2027-03-02']);
        $statistics = app(TimeOffStatistics::class);

        $requests = $statistics->requestsFor($this->a->id, [$employee->id], Carbon::parse('2026-11-25'), Carbon::parse('2026-12-31'));

        $this->assertContainsOnlyInstancesOf(TimeOffRequestSummary::class, $requests);
        $this->assertSame(
            [['pending', 'vacation', '2026-12-01'], ['approved', 'sick', '2026-12-02'], ['refused', 'personal', '2026-12-03'], ['cancelled', 'unpaid', '2026-12-04']],
            array_map(fn (TimeOffRequestSummary $r): array => [$r->status, $r->type, $r->startDate], $requests),
        );
        $this->assertSame([$employee->id], array_values(array_unique(array_map(fn (TimeOffRequestSummary $r): int => $r->userId, $requests))));
        $this->assertSame('2026-12-01', $requests[0]->endDate);
        $this->assertCount(5, $statistics->requestsFor($this->a->id, [$employee->id, $other->id], Carbon::parse('2026-11-25'), Carbon::parse('2026-12-31')));
        $this->assertCount(1, $statistics->requestsFor($this->a->id, [$employee->id], Carbon::parse('2027-03-02'), Carbon::parse('2027-04-01')));
        $this->assertSame([], $statistics->requestsFor($this->a->id, [$employee->id], Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31')));
        $this->assertSame([], $statistics->requestsFor($this->a->id, [], Carbon::parse('2026-11-25'), Carbon::parse('2026-12-31')));
    }

    public function test_the_provider_publishes_approved_leaves_of_the_store_for_the_period(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $approved = $this->leave($employee, $this->a, 'approved');
        $this->leave($employee, $this->a, 'pending', ['start_date' => '2026-12-02']);
        $this->leave($employee, $this->b, 'approved', ['start_date' => '2026-12-02']);
        $this->leave($employee, $this->a, 'approved', ['start_date' => '2027-03-01', 'end_date' => '2027-03-02']);

        $periods = app(TimeOffProvider::class)->approvedFor($employee->id, $this->a->id, Carbon::parse('2026-11-25'), Carbon::parse('2026-12-02'));

        $this->assertCount(1, $periods);
        $this->assertSame(['timeoff-'.$approved->id, '2026-12-01', '2026-12-03'], [$periods[0]->uid, $periods[0]->startDate, $periods[0]->endDate]);
        $this->assertSame([], app(TimeOffProvider::class)->approvedFor($employee->id, $this->a->id, Carbon::parse('2026-12-04'), Carbon::parse('2026-12-20')));
    }

    public function test_pending_requests_count_what_the_user_has_to_decide_or_follow(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $this->leave($employee, $this->a);
        $this->leave($this->employeeOf($this->a), $this->a);
        $this->leave($this->employeeOf($this->b), $this->b);
        $this->leave($employee, $this->a, 'approved');
        $registry = app(PendingRequestRegistry::class);

        $count = fn (User $user): int => $registry->counts($user)[0]['count'];

        $this->assertSame(1, $count($employee), 'An employee follows their own pending requests.');
        $this->assertSame(2, $count($this->managerWith(['timeoff.approve'], [$this->a])), 'A manager decides those of their stores.');
        $this->assertSame(3, $count($this->owner()));
    }

    public function test_disabling_the_bundle_keeps_the_data_and_removes_the_blocking(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $request = $this->leave($employee, $this->a, 'approved');

        app(BundleManager::class)->disable('timeoff');

        $this->assertDatabaseHas('timeoff_requests', ['id' => $request->id]);
    }

    // -- Tableau de bord -----------------------------------------------------------------------------------------

    public function test_the_widgets_show_pending_requests_to_decide_or_follow(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a, ['name' => 'Eve Employee']);
        $this->leave($employee, $this->a, 'pending', ['start_date' => '2026-12-11', 'end_date' => '2026-12-12']);
        $this->leave($this->employeeOf($this->b, ['name' => 'Bob Elsewhere']), $this->b);
        $this->leave($employee, $this->a, 'approved', ['start_date' => '2027-02-02', 'end_date' => '2027-02-02']);
        $manager = $this->managerWith(['timeoff.view', 'timeoff.approve'], [$this->a]);

        Livewire::actingAs($manager)->test('timeoff.manager-widget')
            ->assertSee('Pending time off')->assertSee('Eve Employee')->assertSee('2026-12-11')
            ->assertDontSee('Bob Elsewhere')->assertDontSee('2027-02-02');

        Livewire::actingAs($employee)->test('timeoff.employee-widget')
            ->assertSee('My pending time off')->assertSee('2026-12-11')->assertDontSee('2027-02-02');

        Livewire::actingAs($this->employeeOf($this->a))->test('timeoff.employee-widget')->assertSee('No pending requests.');
    }

    public function test_the_first_activation_gives_the_manager_role_every_right_and_employees_none(): void
    {
        app(EnableBundle::class)->handle($this->owner(), 'timeoff');
        $this->app->register(TimeoffServiceProvider::class, true);

        $manager = $this->managerOf($this->a);
        $employee = $this->employeeOf($this->a);

        foreach ($this->allRights() as $permission) {
            $this->assertTrue(Gate::forUser($manager)->allows($permission, $this->a), $permission);
            $this->assertFalse(Gate::forUser($employee)->allows($permission, $this->a), $permission);
        }
    }

    // -- Données -------------------------------------------------------------------------------------------------

    public function test_anonymising_an_account_erases_the_reasons_but_keeps_the_dates(): void
    {
        $this->enableBundle('timeoff');
        $employee = $this->employeeOf($this->a);
        $request = $this->leave($employee, $this->a, 'refused', ['reason' => 'Private matter', 'admin_note' => 'Because']);

        app(AnonymizeUser::class)->handle($employee);

        $fresh = $request->fresh();
        $this->assertNull($fresh->reason);
        $this->assertNull($fresh->admin_note);
        $this->assertSame('2026-12-01', $fresh->start_date->toDateString());
    }
}
