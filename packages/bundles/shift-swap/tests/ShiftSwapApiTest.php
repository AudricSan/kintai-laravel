<?php

namespace Kintai\Bundles\ShiftSwap\Tests;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Bundles\ShiftSwap\SwapActions;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API des échanges : `/api/v1/shift-swap/requests`, mêmes droits et mêmes règles que les écrans, mêmes erreurs que l'API
 * du Core (dont `409 shift_warnings` quand le planning demande une confirmation).
 */
class ShiftSwapApiTest extends TestCase
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
        $this->enableBundle('shift-swap');
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

    private function manager(Store $store): User
    {
        return $this->managerWith(['swaps.view', 'swaps.create', 'swaps.update', 'swaps.approve', 'swaps.delete', 'shifts.view', 'shifts.create', 'shifts.update'], $store);
    }

    private function shift(User $user, Store $store, int $offset, string $start = '09:00', string $end = '17:00'): Shift
    {
        $date = now()->addDays($offset)->toDateString();

        return Shift::factory()->create([
            'store_id' => $store->id, 'user_id' => $user->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ]);
    }

    /**
     * @return array{User, User, Shift, Shift}
     */
    private function pair(): array
    {
        $alice = $this->employeeOf($this->a, ['name' => 'Alice']);
        $bob = $this->employeeOf($this->a, ['name' => 'Bob']);

        return [$alice, $bob, $this->shift($alice, $this->a, 10), $this->shift($bob, $this->a, 12)];
    }

    public function test_the_api_needs_a_token_and_the_routes_are_named(): void
    {
        $this->getJson('/api/v1/shift-swap/requests')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->assertSame(url('/api/v1/shift-swap/requests'), route('api.v1.shift-swap.requests.index'));
    }

    public function test_the_full_flow_request_accept_approve(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $manager = $this->manager($this->a);

        $id = $this->actingWithToken($alice)->postJson('/api/v1/shift-swap/requests', [
            'requester_shift_id' => $a->id, 'target_shift_id' => $b->id, 'reason' => 'Dentist',
            'status' => 'accepted', 'requester_id' => $bob->id, 'id' => 999,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.stage', 'waiting_colleague')
            ->assertJsonPath('data.requester.id', $alice->id)->assertJsonPath('data.target.id', $bob->id)
            ->assertJsonPath('data.requester_shift.start', '09:00')->assertJsonPath('data.can_cancel', true)->assertJsonPath('data.can_answer', false)
            ->json('data.id');
        $this->assertNotSame(999, $id);

        $this->actingWithToken($bob)->getJson('/api/v1/shift-swap/requests/'.$id)->assertOk()->assertJsonPath('data.can_answer', true);
        $this->actingWithToken($manager)->postJson("/api/v1/shift-swap/requests/{$id}/approve")->assertStatus(422)->assertJsonValidationErrors(['request']);

        $this->actingWithToken($bob)->postJson("/api/v1/shift-swap/requests/{$id}/accept")->assertOk()->assertJsonPath('data.stage', 'waiting_manager');
        $this->actingWithToken($bob)->postJson("/api/v1/shift-swap/requests/{$id}/accept")->assertStatus(422)->assertJson(['code' => 'validation_failed']);

        $this->actingWithToken($alice)->postJson("/api/v1/shift-swap/requests/{$id}/approve")->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingWithToken($manager)->postJson("/api/v1/shift-swap/requests/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.approved_by', $manager->id);

        $this->assertSoftDeleted($a);
        $this->assertSame($bob->id, Shift::query()->where('shift_date', $a->shift_date->toDateString())->value('user_id'));
    }

    public function test_the_list_is_scoped_and_filterable(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $swap = app(SwapActions::class)->request($alice, $a->id, $b->id, null);
        $carol = $this->employeeOf($this->b);
        $dan = $this->employeeOf($this->b);
        app(SwapActions::class)->request($carol, $this->shift($carol, $this->b, 10)->id, $this->shift($dan, $this->b, 11)->id, null);
        $manager = $this->manager($this->a);

        $this->actingWithToken($alice)->getJson('/api/v1/shift-swap/requests')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $swap->id);
        $this->actingWithToken($bob)->getJson('/api/v1/shift-swap/requests')->assertJsonCount(1, 'data');
        $this->actingWithToken($this->employeeOf($this->a))->getJson('/api/v1/shift-swap/requests')->assertJsonCount(0, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/shift-swap/requests')->assertJsonCount(1, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/shift-swap/requests?stage=waiting_manager')->assertJsonCount(0, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/shift-swap/requests?stage=waiting_colleague')->assertJsonCount(1, 'data');
        $this->actingWithToken($this->owner())->getJson('/api/v1/shift-swap/requests')->assertJsonCount(2, 'data');
        $this->actingWithToken($this->owner())->getJson('/api/v1/shift-swap/requests?store_id='.$this->b->id)->assertJsonCount(1, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/shift-swap/requests?status=bogus')->assertStatus(422)->assertJson(['code' => 'validation_failed']);

        $this->actingWithToken($this->employeeOf($this->a))->getJson('/api/v1/shift-swap/requests/'.$swap->id)->assertNotFound()->assertJson(['code' => 'not_found']);
        $this->actingWithToken($this->manager($this->b))->getJson('/api/v1/shift-swap/requests/'.$swap->id)->assertNotFound();
        $this->actingWithToken($alice)->getJson('/api/v1/shift-swap/requests/999')->assertNotFound();
    }

    public function test_requesting_refuses_the_shift_of_somebody_else_and_invalid_input(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $carol = $this->employeeOf($this->a);
        $c = $this->shift($carol, $this->a, 14);

        $this->actingWithToken($alice)->postJson('/api/v1/shift-swap/requests', ['requester_shift_id' => $b->id, 'target_shift_id' => $c->id])
            ->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingWithToken($alice)->postJson('/api/v1/shift-swap/requests', ['requester_shift_id' => $a->id])->assertStatus(422)->assertJsonValidationErrors(['target_shift_id']);
        $this->actingWithToken($alice)->postJson('/api/v1/shift-swap/requests', ['requester_shift_id' => $a->id, 'target_shift_id' => 99999])->assertStatus(422);
        $outsider = $this->employeeOf($this->b);
        $this->actingWithToken($outsider)->postJson('/api/v1/shift-swap/requests', ['requester_shift_id' => $this->shift($outsider, $this->b, 10)->id, 'target_shift_id' => $b->id])->assertStatus(422);
        $this->assertSame(0, ShiftSwapRequest::count());
        $this->assertNotNull($bob);
    }

    public function test_approval_answers_409_shift_warnings_until_it_is_forced(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $this->shift($alice, $this->a, 12, '10:00', '12:00');
        $manager = $this->manager($this->a);
        $swap = app(SwapActions::class)->request($alice, $a->id, $b->id, null);
        app(SwapActions::class)->answer($bob, $swap, true);

        $this->actingWithToken($manager)->postJson("/api/v1/shift-swap/requests/{$swap->id}/approve")
            ->assertStatus(409)->assertJson(['code' => 'shift_warnings'])->assertJsonCount(1, 'warnings')->assertJsonPath('warnings.0.kind', 'overlap');
        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);

        $this->actingWithToken($manager)->postJson("/api/v1/shift-swap/requests/{$swap->id}/approve", ['force' => true])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSoftDeleted($a);
    }

    public function test_decline_cancel_refuse_update_and_delete(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $manager = $this->manager($this->a);
        $actions = app(SwapActions::class);

        $declined = $actions->request($alice, $a->id, $b->id, null);
        $this->actingWithToken($alice)->postJson("/api/v1/shift-swap/requests/{$declined->id}/decline")->assertForbidden();
        $this->actingWithToken($bob)->postJson("/api/v1/shift-swap/requests/{$declined->id}/decline")->assertOk()->assertJsonPath('data.status', 'refused');
        $this->actingWithToken($manager)->deleteJson("/api/v1/shift-swap/requests/{$declined->id}")->assertNoContent();

        $cancelled = $actions->request($alice, $a->id, $b->id, null);
        $this->actingWithToken($bob)->postJson("/api/v1/shift-swap/requests/{$cancelled->id}/cancel")->assertForbidden();
        $this->actingWithToken($alice)->postJson("/api/v1/shift-swap/requests/{$cancelled->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $refused = $actions->request($alice, $a->id, $b->id, 'Old');
        $this->actingWithToken($manager)->putJson("/api/v1/shift-swap/requests/{$refused->id}", ['reason' => 'New'])->assertOk()->assertJsonPath('data.reason', 'New');
        $this->actingWithToken($alice)->putJson("/api/v1/shift-swap/requests/{$refused->id}", ['reason' => 'Mine'])->assertForbidden();
        $this->actingWithToken($alice)->deleteJson("/api/v1/shift-swap/requests/{$refused->id}")->assertForbidden();
        $this->actingWithToken($manager)->postJson("/api/v1/shift-swap/requests/{$refused->id}/refuse")->assertOk()->assertJsonPath('data.status', 'refused');
        $this->actingWithToken($manager)->postJson("/api/v1/shift-swap/requests/{$refused->id}/refuse")->assertStatus(422);
        $this->actingWithToken($this->manager($this->b))->deleteJson("/api/v1/shift-swap/requests/{$refused->id}")->assertNotFound();
        $this->actingWithToken($manager)->deleteJson("/api/v1/shift-swap/requests/{$refused->id}")->assertNoContent();
        $this->assertSame(1, ShiftSwapRequest::count());
    }

    public function test_a_manager_applies_a_swap_and_deleting_it_restores_the_shifts(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $manager = $this->manager($this->a);

        $this->actingWithToken($alice)->postJson('/api/v1/shift-swap/requests/apply', ['requester_shift_id' => $a->id, 'target_shift_id' => $b->id])->assertForbidden();
        $this->actingWithToken($this->managerWith(['swaps.view', 'swaps.approve', 'shifts.update', 'shifts.create'], $this->a))
            ->postJson('/api/v1/shift-swap/requests/apply', ['requester_shift_id' => $a->id, 'target_shift_id' => $b->id])->assertForbidden();

        $id = $this->actingWithToken($manager)->postJson('/api/v1/shift-swap/requests/apply', ['requester_shift_id' => $a->id, 'target_shift_id' => $b->id, 'reason' => 'Training'])
            ->assertCreated()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.can_delete', true)->json('data.id');
        $this->assertSoftDeleted($a);

        $this->actingWithToken($manager)->deleteJson("/api/v1/shift-swap/requests/{$id}")->assertNoContent();

        $this->assertSame($alice->id, Shift::query()->where('shift_date', $a->shift_date->toDateString())->value('user_id'));
        $this->assertSame($bob->id, Shift::query()->where('shift_date', $b->shift_date->toDateString())->value('user_id'));
    }
}
