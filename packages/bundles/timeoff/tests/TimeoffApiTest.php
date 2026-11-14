<?php

namespace Kintai\Bundles\Timeoff\Tests;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API des congés : `/api/v1/timeoff/requests`, mêmes droits que les écrans, mêmes erreurs que l'API du Core.
 */
class TimeoffApiTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;
    use UsesApi;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
        $this->enableBundle('timeoff');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function managerWith(array $permissions, Store $store): User
    {
        $user = User::factory()->create();
        $store->members()->attach($user);
        $user->assignRole($this->customRole($permissions), $store);

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

    public function test_the_api_needs_a_token(): void
    {
        $this->getJson('/api/v1/timeoff/requests')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->assertSame(url('/api/v1/timeoff/requests'), route('api.v1.timeoff.requests.index'));

    }

    public function test_an_employee_lists_only_their_own_requests(): void
    {
        $employee = $this->employeeOf($this->a);
        $this->leave($employee, $this->a, 'pending', ['start_date' => '2026-12-11', 'end_date' => '2026-12-11']);
        $this->leave($this->employeeOf($this->a), $this->a);

        $response = $this->actingWithToken($employee)->getJson('/api/v1/timeoff/requests')->assertOk();

        $this->assertSame(['2026-12-11'], array_column($response->json('data'), 'start_date'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_a_manager_lists_the_requests_of_their_stores_with_filters(): void
    {
        $employee = $this->employeeOf($this->a);
        $this->leave($employee, $this->a, 'approved', ['type' => 'sick']);
        $this->leave($employee, $this->a, 'pending');
        $this->leave($this->employeeOf($this->b), $this->b);
        $manager = $this->managerWith(['timeoff.view'], $this->a);

        $this->actingWithToken($manager)->getJson('/api/v1/timeoff/requests')->assertJsonCount(2, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/timeoff/requests?status=approved')->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'sick');
        $this->actingWithToken($manager)->getJson('/api/v1/timeoff/requests?type=sick')->assertJsonCount(1, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/timeoff/requests?user_id='.$employee->id)->assertJsonCount(2, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/timeoff/requests?store_id='.$this->b->id)->assertJsonCount(0, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/timeoff/requests?from=2027-01-01')->assertJsonCount(0, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/timeoff/requests?status=bogus')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
    }

    public function test_show_hides_requests_out_of_scope(): void
    {
        $employee = $this->employeeOf($this->a);
        $own = $this->leave($employee, $this->a);
        $foreign = $this->leave($this->employeeOf($this->a), $this->a);

        $this->actingWithToken($employee)->getJson('/api/v1/timeoff/requests/'.$own->id)
            ->assertOk()->assertJsonPath('data.id', $own->id)->assertJsonPath('data.can_cancel', true)->assertJsonPath('data.can_decide', false);
        $this->actingWithToken($employee)->getJson('/api/v1/timeoff/requests/'.$foreign->id)->assertNotFound()->assertJson(['code' => 'not_found']);
        $this->actingWithToken($employee)->getJson('/api/v1/timeoff/requests/999')->assertNotFound();
    }

    public function test_an_employee_submits_a_pending_request_for_themselves_and_the_status_cannot_be_forced(): void
    {
        $employee = $this->employeeOf($this->a);
        $approver = $this->managerWith(['timeoff.approve'], $this->a);

        $this->actingWithToken($employee)->postJson('/api/v1/timeoff/requests', [
            'store_id' => $this->a->id, 'type' => 'sick', 'start_date' => '2026-12-01', 'reason' => 'Flu',
            'status' => 'approved', 'processed_by' => $employee->id, 'id' => 999,
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.end_date', '2026-12-01')->assertJsonPath('data.user.id', $employee->id);

        $request = TimeoffRequest::firstOrFail();
        $this->assertNotSame(999, $request->id);
        $this->assertNull($request->processed_by);
        $this->assertSame(1, $approver->notifications()->count());
    }

    public function test_submitting_validates_and_refuses_a_foreign_store(): void
    {
        $employee = $this->employeeOf($this->a);

        $this->actingWithToken($employee)->postJson('/api/v1/timeoff/requests', ['store_id' => $this->a->id, 'type' => 'x', 'start_date' => 'soon'])
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors(['type', 'start_date']);
        $this->actingWithToken($employee)->postJson('/api/v1/timeoff/requests', ['store_id' => $this->a->id, 'type' => 'sick', 'start_date' => '2026-12-05', 'end_date' => '2026-12-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->actingWithToken($employee)->postJson('/api/v1/timeoff/requests', ['store_id' => $this->b->id, 'type' => 'sick', 'start_date' => '2026-12-05'])
            ->assertStatus(422)->assertJsonValidationErrors(['store_id']);
        $this->assertSame(0, TimeoffRequest::count());
    }

    public function test_creating_for_someone_else_needs_the_create_permission_and_is_approved_at_once(): void
    {
        $employee = $this->employeeOf($this->a);
        $payload = ['store_id' => $this->a->id, 'user_id' => $employee->id, 'type' => 'personal', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02'];

        $this->actingWithToken($this->employeeOf($this->a))->postJson('/api/v1/timeoff/requests', $payload)->assertForbidden();
        $this->actingWithToken($this->managerWith(['timeoff.view'], $this->a))->postJson('/api/v1/timeoff/requests', $payload)->assertForbidden();
        $this->assertSame(0, TimeoffRequest::count());

        $manager = $this->managerWith(['timeoff.create'], $this->a);
        $this->actingWithToken($manager)->postJson('/api/v1/timeoff/requests', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.processed_by', $manager->id);
        $this->assertSame(1, $employee->notifications()->count());

        $this->actingWithToken($manager)->postJson('/api/v1/timeoff/requests', ['user_id' => 999999] + $payload)->assertNotFound();
    }

    public function test_the_decision_endpoints_follow_the_rules_of_the_screens(): void
    {
        $employee = $this->employeeOf($this->a);
        $approver = $this->managerWith(['timeoff.view', 'timeoff.approve'], $this->a);
        $viewer = $this->managerWith(['timeoff.view'], $this->a);
        $first = $this->leave($employee, $this->a);
        $second = $this->leave($employee, $this->a);

        $this->actingWithToken($viewer)->postJson('/api/v1/timeoff/requests/'.$first->id.'/approve')->assertForbidden();
        $this->actingWithToken($employee)->postJson('/api/v1/timeoff/requests/'.$first->id.'/approve')->assertForbidden();

        $this->actingWithToken($approver)->postJson('/api/v1/timeoff/requests/'.$first->id.'/approve', ['admin_note' => 'Enjoy'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.admin_note', 'Enjoy');
        $this->actingWithToken($approver)->postJson('/api/v1/timeoff/requests/'.$second->id.'/refuse', ['admin_note' => 'No'])
            ->assertOk()->assertJsonPath('data.status', 'refused');
        $this->assertSame(2, $employee->notifications()->count());

        $this->actingWithToken($approver)->postJson('/api/v1/timeoff/requests/'.$first->id.'/refuse')
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors(['request']);
        $this->assertSame('approved', $first->fresh()->status);
    }

    public function test_an_employee_cancels_their_own_pending_request(): void
    {
        $employee = $this->employeeOf($this->a);
        $colleague = $this->employeeOf($this->a);
        $own = $this->leave($employee, $this->a);
        $approved = $this->leave($employee, $this->a, 'approved');
        $foreign = $this->leave($colleague, $this->a);

        $this->actingWithToken($employee)->postJson('/api/v1/timeoff/requests/'.$own->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingWithToken($employee)->postJson('/api/v1/timeoff/requests/'.$approved->id.'/cancel')->assertStatus(422);
        $this->actingWithToken($employee)->postJson('/api/v1/timeoff/requests/'.$foreign->id.'/cancel')->assertNotFound();
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_update_changes_the_content_but_never_the_owner_the_store_or_the_status(): void
    {
        $employee = $this->employeeOf($this->a);
        $request = $this->leave($employee, $this->a);
        $editor = $this->managerWith(['timeoff.view', 'timeoff.update'], $this->a);

        $this->actingWithToken($employee)->putJson('/api/v1/timeoff/requests/'.$request->id, ['reason' => 'x'])->assertForbidden();

        $this->actingWithToken($editor)->putJson('/api/v1/timeoff/requests/'.$request->id, [
            'type' => 'sick', 'end_date' => '2026-12-05', 'admin_note' => 'Checked', 'status' => 'approved', 'user_id' => 1, 'store_id' => $this->b->id,
        ])->assertOk()->assertJsonPath('data.type', 'sick')->assertJsonPath('data.end_date', '2026-12-05');

        $fresh = $request->fresh();
        $this->assertSame([$employee->id, $this->a->id, 'pending', 'Checked'], [$fresh->user_id, $fresh->store_id, $fresh->status, $fresh->admin_note]);

        $this->actingWithToken($editor)->putJson('/api/v1/timeoff/requests/'.$request->id, ['end_date' => '2026-11-01'])
            ->assertStatus(422)->assertJson(['code' => 'validation_failed']);
    }

    public function test_delete_needs_the_delete_permission(): void
    {
        $request = $this->leave($this->employeeOf($this->a), $this->a, 'approved');

        $this->actingWithToken($this->managerWith(['timeoff.view', 'timeoff.update'], $this->a))->deleteJson('/api/v1/timeoff/requests/'.$request->id)->assertForbidden();
        $this->actingWithToken($this->managerWith(['timeoff.view', 'timeoff.delete'], $this->b))->deleteJson('/api/v1/timeoff/requests/'.$request->id)->assertNotFound();
        $this->actingWithToken($this->managerWith(['timeoff.view', 'timeoff.delete'], $this->a))->deleteJson('/api/v1/timeoff/requests/'.$request->id)->assertNoContent();
        $this->assertDatabaseMissing('timeoff_requests', ['id' => $request->id]);
    }

    public function test_an_invalid_update_of_a_request_out_of_scope_is_still_not_found(): void
    {
        $employee = $this->employeeOf($this->a);
        $foreign = $this->leave($this->employeeOf($this->a), $this->a);

        $this->actingWithToken($employee)->putJson('/api/v1/timeoff/requests/'.$foreign->id, ['type' => 'bogus', 'start_date' => 'nope'])->assertNotFound();
        $this->actingWithToken($employee)->putJson('/api/v1/timeoff/requests/999', ['type' => 'bogus'])->assertNotFound();
    }
}
