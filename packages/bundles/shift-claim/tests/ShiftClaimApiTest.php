<?php

namespace Kintai\Bundles\ShiftClaim\Tests;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\ShiftClaim\ClaimActions;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;
use Kintai\Bundles\ShiftClaim\Models\ShiftOffer;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API de la bourse : `/api/v1/shift-claim`, mêmes droits et mêmes règles que les écrans, mêmes erreurs que l'API du Core
 * (dont `409 shift_warnings` quand le planning demande une confirmation).
 */
class ShiftClaimApiTest extends TestCase
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
        $this->enableBundle('shift-claim');
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
        return $this->managerWith(['open_shifts.view', 'open_shifts.publish', 'open_shifts.approve', 'shifts.view', 'shifts.create', 'shifts.update', 'payroll.generate'], $store);
    }

    private function shift(?User $user, Store $store, int $offset = 10, string $start = '09:00', string $end = '17:00'): Shift
    {
        $date = now()->addDays($offset)->toDateString();

        return Shift::factory()->create([
            'store_id' => $store->id, 'user_id' => $user?->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ]);
    }

    public function test_the_api_needs_a_token_and_the_routes_are_named(): void
    {
        $this->getJson('/api/v1/shift-claim/open-shifts')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->assertSame(url('/api/v1/shift-claim/claims'), route('api.v1.shift-claim.claims.index'));
    }

    public function test_the_pool_is_limited_to_the_stores_of_the_caller(): void
    {
        $eve = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a);
        $this->shift(null, $this->b, 11);
        $this->shift($this->employeeOf($this->a), $this->a, 12);

        $this->actingWithToken($eve)->getJson('/api/v1/shift-claim/open-shifts')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.can_claim', true)->assertJsonPath('data.0.offered', false)->assertJsonPath('data.0.start', '09:00');
        $this->actingWithToken($this->manager($this->b))->getJson('/api/v1/shift-claim/open-shifts')->assertJsonCount(1, 'data');
        $this->actingWithToken($this->owner())->getJson('/api/v1/shift-claim/open-shifts')->assertJsonCount(2, 'data');
    }

    public function test_claim_approve_flow_with_forbidden_and_replayed_decisions(): void
    {
        $eve = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a);
        $manager = $this->manager($this->a);

        $id = $this->actingWithToken($eve)->postJson("/api/v1/shift-claim/open-shifts/{$open->id}/claim", ['note' => 'Me', 'status' => 'approved', 'user_id' => 999])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.applicant.id', $eve->id)->assertJsonPath('data.can_withdraw', true)
            ->json('data.id');
        $this->actingWithToken($eve)->postJson("/api/v1/shift-claim/open-shifts/{$open->id}/claim")->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->actingWithToken($eve)->postJson("/api/v1/shift-claim/claims/{$id}/approve")->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingWithToken($this->employeeOf($this->a))->getJson("/api/v1/shift-claim/claims/{$id}")->assertNotFound();

        $this->actingWithToken($manager)->postJson("/api/v1/shift-claim/claims/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.resolved_by', $manager->id);
        $this->assertSoftDeleted($open);
        $this->actingWithToken($manager)->postJson("/api/v1/shift-claim/claims/{$id}/approve")->assertStatus(422);
        $this->actingWithToken($manager)->postJson("/api/v1/shift-claim/claims/{$id}/reject")->assertStatus(422);
        $this->actingWithToken($eve)->postJson("/api/v1/shift-claim/claims/{$id}/withdraw")->assertStatus(422);
    }

    public function test_claiming_outside_the_stores_of_the_caller_is_not_found(): void
    {
        $foreign = $this->shift(null, $this->b);
        $assigned = $this->shift($this->employeeOf($this->a), $this->a, 11);
        $eve = $this->employeeOf($this->a);

        $this->actingWithToken($eve)->postJson("/api/v1/shift-claim/open-shifts/{$foreign->id}/claim")->assertNotFound()->assertJson(['code' => 'not_found']);
        $this->actingWithToken($eve)->postJson("/api/v1/shift-claim/open-shifts/{$assigned->id}/claim")->assertStatus(422);
        $this->actingWithToken($eve)->postJson('/api/v1/shift-claim/open-shifts/99999/claim')->assertNotFound();
        $this->assertSame(0, ShiftClaim::count());
    }

    public function test_approval_answers_409_shift_warnings_until_it_is_forced(): void
    {
        $eve = $this->employeeOf($this->a);
        $open = $this->shift(null, $this->a);
        $this->shift($eve, $this->a, 10, '12:00', '14:00');
        $claim = app(ClaimActions::class)->claim($eve, $open->id, null);
        $manager = $this->manager($this->a);

        $this->actingWithToken($manager)->postJson("/api/v1/shift-claim/claims/{$claim->id}/approve")
            ->assertStatus(409)->assertJson(['code' => 'shift_warnings'])->assertJsonPath('warnings.0.kind', 'overlap');
        $this->assertSame('pending', $claim->fresh()->status);

        $this->actingWithToken($manager)->postJson("/api/v1/shift-claim/claims/{$claim->id}/approve", ['force' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSoftDeleted($open);
    }

    public function test_list_filters_withdraw_reject_offer_and_unoffer(): void
    {
        $eve = $this->employeeOf($this->a);
        $holder = $this->employeeOf($this->a);
        $manager = $this->manager($this->a);
        $actions = app(ClaimActions::class);
        $open = $this->shift(null, $this->a, 10);
        $other = $this->shift(null, $this->a, 11);
        $mine = $actions->claim($eve, $open->id, null);
        $rejectable = $actions->claim($this->employeeOf($this->a), $other->id, null);

        $this->actingWithToken($eve)->getJson('/api/v1/shift-claim/claims')->assertJsonCount(1, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/shift-claim/claims')->assertJsonCount(2, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/shift-claim/claims?shift_id='.$open->id)->assertJsonCount(1, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/shift-claim/claims?status=bogus')->assertStatus(422);
        $this->actingWithToken($this->manager($this->b))->getJson('/api/v1/shift-claim/claims')->assertJsonCount(0, 'data');

        $this->actingWithToken($eve)->postJson("/api/v1/shift-claim/claims/{$mine->id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->actingWithToken($manager)->postJson("/api/v1/shift-claim/claims/{$rejectable->id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');

        $assigned = $this->shift($holder, $this->a, 12);
        $this->actingWithToken($eve)->postJson("/api/v1/shift-claim/shifts/{$assigned->id}/offer")->assertForbidden();
        $this->actingWithToken($manager)->postJson("/api/v1/shift-claim/shifts/{$assigned->id}/offer", ['note' => 'Take it'])
            ->assertCreated()->assertJsonPath('data.offered', true)->assertJsonPath('data.holder.id', $holder->id);
        $this->actingWithToken($manager)->postJson("/api/v1/shift-claim/shifts/{$assigned->id}/offer")->assertStatus(422);
        $this->actingWithToken($eve)->deleteJson("/api/v1/shift-claim/shifts/{$assigned->id}/offer")->assertForbidden();
        $this->actingWithToken($manager)->deleteJson("/api/v1/shift-claim/shifts/{$assigned->id}/offer")->assertNoContent();
        $this->assertSame(0, ShiftOffer::count());
    }
}
