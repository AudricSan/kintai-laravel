<?php

namespace Kintai\Bundles\Timeclock\Tests;

use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API du pointage : `/api/v1/timeclock`, mêmes droits que les écrans, mêmes erreurs que l'API du Core.
 */
class TimeclockApiTest extends TestCase
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
        $this->enableBundle('timeclock');
        Carbon::setTestNow('2026-11-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    private function entry(User $user, Store $store, ?string $out = '2026-11-10 17:30:00'): Timeclock
    {
        return Timeclock::create([
            'user_id' => $user->id, 'store_id' => $store->id, 'shift_date' => '2026-11-10', 'clock_in_at' => '2026-11-10 09:00:00',
            'clock_out_at' => $out, 'duration_minutes' => $out === null ? null : 510,
        ]);
    }

    public function test_the_api_needs_a_token(): void
    {
        $this->getJson('/api/v1/timeclock/entries')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->postJson('/api/v1/timeclock/clock-in')->assertUnauthorized();
        $this->assertSame(url('/api/v1/timeclock/clock-in'), route('api.v1.timeclock.clock_in'));
    }

    public function test_an_employee_clocks_in_and_out(): void
    {
        $employee = $this->employeeOf($this->a);

        $this->actingWithToken($employee)->getJson('/api/v1/timeclock/active')->assertOk()->assertJson(['data' => null]);

        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-in')
            ->assertCreated()->assertJsonPath('data.running', true)->assertJsonPath('data.store.id', $this->a->id)->assertJsonPath('data.date', '2026-11-10');
        $this->actingWithToken($employee)->getJson('/api/v1/timeclock/active')->assertJsonPath('data.running', true);

        Carbon::setTestNow('2026-11-10 17:30:00');
        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-out')
            ->assertOk()->assertJsonPath('data.running', false)->assertJsonPath('data.duration_minutes', 510);
    }

    public function test_errors_have_stable_codes(): void
    {
        $employee = $this->employeeOf($this->a);

        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-out')->assertNotFound()->assertJson(['code' => 'not_clocked_in']);

        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-in')->assertCreated();
        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-in')->assertStatus(409)->assertJson(['code' => 'already_clocked_in']);

        $this->actingWithToken($this->employeeOf($this->a))->postJson('/api/v1/timeclock/clock-in', ['store_id' => $this->b->id])
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors(['store_id']);
        $this->actingWithToken(User::factory()->create())->postJson('/api/v1/timeclock/clock-in')->assertStatus(422);
    }

    public function test_an_offline_gesture_keeps_its_real_time_within_a_plausible_window(): void
    {
        $employee = $this->employeeOf($this->a);

        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-in', ['client_time' => '2026-11-10T07:30:00+00:00'])
            ->assertCreated()->assertJsonPath('data.clock_in_at', '2026-11-10T07:30:00+00:00');
        $entry = Timeclock::firstOrFail();
        $this->assertTrue(ActivityEntry::where('action', 'timeclock.clock_in')->firstOrFail()->properties['offline_sync']);

        // Une sortie rejouée avant l'entrée retombe sur l'heure du serveur : jamais de durée négative.
        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-out', ['client_time' => '2026-11-10T06:00:00+00:00'])
            ->assertOk()->assertJsonPath('data.duration_minutes', 90);
        $this->assertSame(90, $entry->fresh()->duration_minutes);
    }

    public function test_an_implausible_client_time_is_replaced_by_the_server_time(): void
    {
        $employee = $this->employeeOf($this->a);

        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-in', ['client_time' => '2026-11-08T07:30:00+00:00'])
            ->assertCreated()->assertJsonPath('data.clock_in_at', '2026-11-10T09:00:00+00:00');
        Timeclock::query()->delete();
        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-in', ['client_time' => '2026-11-10T09:30:00+00:00'])
            ->assertCreated()->assertJsonPath('data.clock_in_at', '2026-11-10T09:00:00+00:00');
        Timeclock::query()->delete();
        $this->actingWithToken($employee)->postJson('/api/v1/timeclock/clock-in', ['client_time' => 'garbage'])
            ->assertCreated()->assertJsonPath('data.clock_in_at', '2026-11-10T09:00:00+00:00');
    }

    public function test_an_employee_lists_only_their_entries_and_a_manager_those_of_their_stores(): void
    {
        $employee = $this->employeeOf($this->a);
        $mine = $this->entry($employee, $this->a);
        $colleague = $this->entry($this->employeeOf($this->a), $this->a);
        $elsewhere = $this->entry($this->employeeOf($this->b), $this->b);

        $this->actingWithToken($employee)->getJson('/api/v1/timeclock/entries')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);

        $manager = $this->managerWith(['timeclock.view'], $this->a);
        $ids = array_column($this->actingWithToken($manager)->getJson('/api/v1/timeclock/entries')->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$mine->id, $colleague->id], $ids);
        $this->assertNotContains($elsewhere->id, $ids);

        $this->actingWithToken($manager)->getJson('/api/v1/timeclock/entries?user_id='.$employee->id)->assertJsonCount(1, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/timeclock/entries?from=2026-11-11')->assertJsonCount(0, 'data');
        $this->actingWithToken($manager)->getJson('/api/v1/timeclock/entries?active=1')->assertJsonCount(0, 'data');
    }

    public function test_show_hides_entries_out_of_scope(): void
    {
        $employee = $this->employeeOf($this->a);
        $mine = $this->entry($employee, $this->a);
        $foreign = $this->entry($this->employeeOf($this->a), $this->a);

        $this->actingWithToken($employee)->getJson('/api/v1/timeclock/entries/'.$mine->id)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->actingWithToken($employee)->getJson('/api/v1/timeclock/entries/'.$foreign->id)->assertNotFound()->assertJson(['code' => 'not_found']);
    }

    public function test_update_needs_the_update_permission_and_validates_the_hours(): void
    {
        $entry = $this->entry($this->employeeOf($this->a), $this->a);
        $editor = $this->managerWith(['timeclock.view', 'timeclock.update'], $this->a);

        $this->actingWithToken($this->managerWith(['timeclock.view'], $this->a))->putJson('/api/v1/timeclock/entries/'.$entry->id, ['notes' => 'x'])->assertForbidden();
        $this->actingWithToken($this->employeeOf($this->a))->putJson('/api/v1/timeclock/entries/'.$entry->id, ['notes' => 'x'])->assertNotFound();

        $this->actingWithToken($editor)->putJson('/api/v1/timeclock/entries/'.$entry->id, ['clock_out_at' => '2026-11-10T12:00:00+00:00', 'notes' => 'Left early'])
            ->assertOk()->assertJsonPath('data.duration_minutes', 180)->assertJsonPath('data.notes', 'Left early');
        $this->actingWithToken($editor)->putJson('/api/v1/timeclock/entries/'.$entry->id, ['clock_out_at' => '2026-11-10T08:00:00+00:00'])
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors(['clock_out_at']);
        $this->actingWithToken($editor)->putJson('/api/v1/timeclock/entries/'.$entry->id, ['clock_in_at' => 'whenever'])->assertStatus(422);
    }

    public function test_delete_needs_the_delete_permission(): void
    {
        $entry = $this->entry($this->employeeOf($this->a), $this->a);

        $this->actingWithToken($this->managerWith(['timeclock.view', 'timeclock.update'], $this->a))->deleteJson('/api/v1/timeclock/entries/'.$entry->id)->assertForbidden();
        $this->actingWithToken($this->managerWith(['timeclock.view', 'timeclock.delete'], $this->b))->deleteJson('/api/v1/timeclock/entries/'.$entry->id)->assertNotFound();
        $this->actingWithToken($this->managerWith(['timeclock.view', 'timeclock.delete'], $this->a))->deleteJson('/api/v1/timeclock/entries/'.$entry->id)->assertNoContent();
        $this->assertDatabaseMissing('timeclocks', ['id' => $entry->id]);
    }
}
