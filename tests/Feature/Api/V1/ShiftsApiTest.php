<?php

namespace Tests\Feature\Api\V1;

use App\Models\Availability;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Contracts\Planning\TimeOffChecker;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class ShiftsApiTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    private Store $alpha;

    private Store $beta;

    private User $owner;

    private User $manager;

    private User $employee;

    private User $colleague;

    private User $betaEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.timezone' => 'Asia/Tokyo']);
        $this->seedRoles();
        $this->alpha = Store::factory()->create(['name' => 'Alpha', 'min_shift_minutes' => 0, 'max_shift_minutes' => 0]);
        $this->beta = Store::factory()->create(['name' => 'Beta', 'min_shift_minutes' => 0, 'max_shift_minutes' => 0]);
        $this->owner = $this->owner();
        $this->manager = $this->managerOf($this->alpha);
        $this->alpha->members()->attach($this->manager);
        $this->employee = $this->employeeOf($this->alpha);
        $this->colleague = $this->employeeOf($this->alpha);
        $this->betaEmployee = $this->employeeOf($this->beta);
    }

    /**
     * 2026-11-02 est un lundi.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'store_id' => $this->alpha->id,
            'user_id' => $this->employee->id,
            'shift_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'pause_minutes' => 60,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(?User $user, Store $store, string $date = '2026-11-02', string $start = '09:00', string $end = '17:00', array $attributes = []): Shift
    {
        $shift = Shift::fromLocalTimes($date, $start, $end, $attributes + ['store_id' => $store->id, 'user_id' => $user?->id, 'pause_minutes' => 60]);
        $shift->save();

        return $shift;
    }

    private function fakeTimeOff(bool $result): void
    {
        $this->app->bind(TimeOffChecker::class, fn () => new class($result) implements TimeOffChecker
        {
            public function __construct(private readonly bool $result) {}

            public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool
            {
                return $this->result;
            }
        });
    }

    /**
     * @return list<int>
     */
    private function listedIds(string $query = ''): array
    {
        return collect($this->getJson('/api/v1/shifts'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_shifts_require_a_token(): void
    {
        $this->getJson('/api/v1/shifts')->assertStatus(401);
    }

    public function test_an_employee_without_shifts_view_sees_only_his_own_shifts(): void
    {
        $mine = $this->shift($this->employee, $this->alpha);
        $this->shift($this->colleague, $this->alpha);
        $this->shift(null, $this->alpha);
        $trashed = $this->shift($this->employee, $this->alpha, '2026-11-03');
        $trashed->delete();

        $this->actingWithToken($this->employee);

        $this->assertSame([$mine->id], $this->listedIds());
        $this->assertSame([$mine->id], $this->listedIds('?user_id='.$this->employee->id));
        $this->assertSame([$mine->id], $this->listedIds('?trashed=with'));

        $this->getJson('/api/v1/shifts?user_id='.$this->colleague->id)->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/v1/shifts?open=1')->assertStatus(403);

        $this->getJson('/api/v1/shifts/'.$mine->id)->assertOk()->assertJsonPath('data.id', $mine->id)->assertJsonMissingPath('data.cost');
        $this->getJson('/api/v1/shifts/'.$this->shift($this->colleague, $this->alpha, '2026-11-04')->id)->assertStatus(404);
        $this->getJson('/api/v1/shifts/'.$trashed->id)->assertStatus(404);

        // Libre-service en lecture seulement.
        $this->postJson('/api/v1/shifts', $this->payload())->assertStatus(403);
        $this->putJson('/api/v1/shifts/'.$mine->id, ['notes' => 'x'])->assertStatus(403);
        $this->deleteJson('/api/v1/shifts/'.$mine->id)->assertStatus(403);
        $this->postJson('/api/v1/shifts/'.$mine->id.'/move', ['start_time' => '10:00'])->assertStatus(403);
        $this->postJson('/api/v1/shifts/bulk-delete', ['ids' => [$mine->id]])->assertStatus(403);
        $this->getJson('/api/v1/shifts/conflicts')->assertStatus(403);
    }

    public function test_a_restricted_manager_lists_only_the_shifts_of_his_stores_with_filters(): void
    {
        $a1 = $this->shift($this->employee, $this->alpha, '2026-11-02');
        $a2 = $this->shift(null, $this->alpha, '2026-11-10');
        $a3 = $this->shift($this->colleague, $this->alpha, '2026-12-01');
        $b1 = $this->shift($this->betaEmployee, $this->beta, '2026-11-02');
        $gone = $this->shift($this->employee, $this->alpha, '2026-11-20');
        $gone->delete();

        $this->actingWithToken($this->manager);
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id, $a3->id], $this->listedIds());
        $this->assertSame([$a1->id, $a2->id], $this->listedIds('?from=2026-11-01&to=2026-11-30'));
        $this->assertSame([$a1->id], $this->listedIds('?user_id='.$this->employee->id));
        $this->assertSame([$a2->id], $this->listedIds('?open=1'));
        $this->assertSame([$gone->id], $this->listedIds('?trashed=only'));
        $this->assertCount(4, $this->listedIds('?trashed=with'));
        $this->assertSame([], $this->listedIds('?store_id='.$this->beta->id));

        $this->getJson('/api/v1/shifts/'.$b1->id)->assertStatus(404);
        $this->getJson('/api/v1/shifts/'.$a1->id)->assertOk();
        $this->getJson('/api/v1/shifts?from=nope')->assertStatus(422);
        $this->getJson('/api/v1/shifts?from=2026-12-01&to=2026-11-01')->assertStatus(422);

        $this->actingWithToken($this->owner);
        $this->assertContains($b1->id, $this->listedIds());
        $this->assertSame([$b1->id], $this->listedIds('?store_id='.$this->beta->id));
    }

    public function test_the_cost_is_given_only_with_payroll_view(): void
    {
        $type = ShiftType::factory()->create(['starts_at' => '00:00', 'ends_at' => '23:59', 'hourly_rate' => 1000]);
        $type->stores()->attach($this->alpha);
        $shift = $this->shift($this->employee, $this->alpha);

        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['shifts.view']), $this->alpha);

        $this->actingWithToken($viewer)->getJson('/api/v1/shifts')->assertOk()->assertJsonMissingPath('data.0.cost')->assertJsonMissingPath('data.0.hourly_rate_override');
        $this->getJson('/api/v1/shifts/'.$shift->id)->assertOk()->assertJsonMissingPath('data.cost');

        $this->actingWithToken($this->manager)->getJson('/api/v1/shifts/'.$shift->id)->assertOk()->assertJsonPath('data.cost', 7000);
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('data.0.cost', 7000);
    }

    public function test_hours_are_local_in_input_and_utc_in_output(): void
    {
        $response = $this->actingWithToken($this->manager)->postJson('/api/v1/shifts', $this->payload(['start_time' => '22:00', 'end_time' => '06:00']))->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-02T13:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-11-02T21:00:00Z')
            ->assertJsonPath('data.local', ['date' => '2026-11-02', 'start' => '22:00', 'end' => '06:00', 'crosses_midnight' => true])
            ->assertJsonPath('data.duration_minutes', 480)
            ->assertJsonPath('data.net_minutes', 420)
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.created_by', $this->manager->id);

        $this->getJson('/api/v1/shifts/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.local.start', '22:00');
    }

    public function test_creating_a_shift_notifies_the_employee_and_is_logged(): void
    {
        $id = $this->actingWithToken($this->manager)->postJson('/api/v1/shifts', $this->payload())->assertCreated()->json('data.id');

        $this->assertTrue($this->employee->notifications()->exists());
        $this->assertDatabaseHas('activity_entries', ['action' => 'shift.created', 'subject_id' => $id, 'actor_id' => $this->manager->id]);

        $open = $this->postJson('/api/v1/shifts', $this->payload(['user_id' => null, 'start_time' => '08:00', 'end_time' => '09:30']))->assertCreated()
            ->assertJsonPath('data.user_id', null)->assertJsonPath('data.is_open', true);
        $this->assertNotNull($open->json('data.id'));
    }

    public function test_warnings_answer_409_until_forced(): void
    {
        $this->shift($this->employee, $this->alpha);
        Availability::query()->create(['user_id' => $this->colleague->id, 'store_id' => $this->alpha->id, 'day_of_week' => 1, 'is_available' => false]);
        $this->actingWithToken($this->manager);

        $response = $this->postJson('/api/v1/shifts', $this->payload(['start_time' => '12:00', 'end_time' => '18:00']))->assertStatus(409)
            ->assertJsonPath('code', 'shift_warnings')
            ->assertJsonPath('warnings.0.kind', 'overlap')
            ->assertJsonStructure(['error', 'code', 'warnings' => [['kind', 'message', 'conflicting_shift_id']]]);
        $this->assertNotEmpty($response->json('warnings.0.message'));
        $this->assertSame(1, Shift::query()->count());

        $this->postJson('/api/v1/shifts', $this->payload(['user_id' => $this->colleague->id]))->assertStatus(409)->assertJsonPath('warnings.0.kind', 'unavailable');

        $this->postJson('/api/v1/shifts', $this->payload(['start_time' => '12:00', 'end_time' => '18:00']) + ['force' => true])->assertCreated();
        $this->assertSame(2, Shift::query()->count());
    }

    public function test_approved_time_off_blocks_even_when_forced(): void
    {
        $this->fakeTimeOff(true);

        $this->actingWithToken($this->manager)->postJson('/api/v1/shifts', $this->payload() + ['force' => true])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrorFor('user_id', 'errors');
        $this->assertSame(0, Shift::query()->count());

        // Un shift ouvert n'a pas d'employé à vérifier.
        $this->postJson('/api/v1/shifts', $this->payload(['user_id' => null]))->assertCreated();
    }

    public function test_creation_rules_and_rights(): void
    {
        $this->actingWithToken($this->manager);

        $this->postJson('/api/v1/shifts', $this->payload(['end_time' => '09:00']))->assertStatus(422)->assertJsonValidationErrorFor('end_time', 'errors');
        $this->postJson('/api/v1/shifts', $this->payload(['pause_minutes' => 5000]))->assertStatus(422);
        $this->postJson('/api/v1/shifts', $this->payload(['store_id' => 999999]))->assertStatus(422)->assertJsonValidationErrorFor('store_id', 'errors');
        // Employé d'un autre magasin.
        $this->postJson('/api/v1/shifts', $this->payload(['user_id' => $this->betaEmployee->id]))->assertStatus(422)->assertJsonValidationErrorFor('user_id', 'errors');
        // Magasin hors droits.
        $this->postJson('/api/v1/shifts', $this->payload(['store_id' => $this->beta->id, 'user_id' => $this->betaEmployee->id]))->assertStatus(403);
        // Surcharge de paie : réservée à payroll.generate (le gérant l'a).
        $this->postJson('/api/v1/shifts', $this->payload(['hourly_rate_override' => 1500]))->assertCreated()->assertJsonPath('data.hourly_rate_override', '1500.00');

        $limited = User::factory()->create();
        $limited->assignRole($this->customRole(['shifts.view', 'shifts.create']), $this->alpha);
        $this->actingWithToken($limited)->postJson('/api/v1/shifts', $this->payload(['hourly_rate_override' => 1500]))->assertStatus(403);
    }

    public function test_update_and_move_keep_unsent_fields_and_warn(): void
    {
        $shift = $this->shift($this->employee, $this->alpha, '2026-11-02', '09:00', '17:00', ['notes' => 'keep me']);
        $other = $this->shift($this->colleague, $this->alpha, '2026-11-02', '09:00', '17:00');
        $this->actingWithToken($this->manager);

        $this->putJson('/api/v1/shifts/'.$shift->id, ['end_time' => '18:00'])->assertOk()
            ->assertJsonPath('data.local.end', '18:00')->assertJsonPath('data.local.start', '09:00')->assertJsonPath('data.notes', 'keep me');
        $this->assertSame(1, $shift->refresh()->ical_sequence);
        $this->assertDatabaseHas('activity_entries', ['action' => 'shift.updated', 'subject_id' => $shift->id]);

        // Déplacer sur le créneau d'un autre employé en prenant sa place : chevauchement du même employé seulement.
        $this->postJson('/api/v1/shifts/'.$other->id.'/move', ['user_id' => $this->employee->id])->assertStatus(409)->assertJsonPath('warnings.0.kind', 'overlap');
        $this->postJson('/api/v1/shifts/'.$other->id.'/move', ['user_id' => $this->employee->id, 'force' => true])->assertOk()->assertJsonPath('data.user_id', $this->employee->id);

        $this->postJson('/api/v1/shifts/'.$shift->id.'/move', ['shift_date' => '2026-11-05', 'start_time' => '07:00', 'end_time' => '08:00'])->assertOk()
            ->assertJsonPath('data.local.date', '2026-11-05')->assertJsonPath('data.starts_at', '2026-11-04T22:00:00Z');

        $this->putJson('/api/v1/shifts/'.$shift->id, ['start_time' => 'bad'])->assertStatus(422);
        $this->putJson('/api/v1/shifts/'.$this->shift($this->betaEmployee, $this->beta)->id, ['notes' => 'x'])->assertStatus(404);
    }

    public function test_delete_and_restore(): void
    {
        $shift = $this->shift($this->employee, $this->alpha);
        $this->actingWithToken($this->manager);

        $this->deleteJson('/api/v1/shifts/'.$shift->id)->assertNoContent();
        $this->assertSoftDeleted($shift);
        $this->assertDatabaseHas('activity_entries', ['action' => 'shift.deleted', 'subject_id' => $shift->id, 'actor_id' => $this->manager->id]);
        $this->deleteJson('/api/v1/shifts/'.$shift->id)->assertStatus(422)->assertJsonValidationErrorFor('shift', 'errors');
        $this->putJson('/api/v1/shifts/'.$shift->id, ['notes' => 'x'])->assertStatus(422);

        $this->postJson('/api/v1/shifts/'.$shift->id.'/restore')->assertOk()->assertJsonPath('data.is_deleted', false);
        $this->postJson('/api/v1/shifts/'.$shift->id.'/restore')->assertStatus(422);
        $this->assertDatabaseHas('activity_entries', ['action' => 'shift.restored', 'subject_id' => $shift->id]);

        $reader = User::factory()->create();
        $reader->assignRole($this->customRole(['shifts.view']), $this->alpha);
        $this->actingWithToken($reader)->deleteJson('/api/v1/shifts/'.$shift->id)->assertStatus(403);
        $this->postJson('/api/v1/shifts/'.$shift->id.'/restore')->assertStatus(403);
    }

    public function test_replacing_a_shift_and_restoring_the_replaced_one(): void
    {
        $old = $this->shift($this->employee, $this->alpha);
        $this->actingWithToken($this->manager);

        $response = $this->postJson('/api/v1/shifts/'.$old->id.'/replace', ['user_id' => $this->colleague->id])->assertCreated()
            ->assertJsonPath('data.user_id', $this->colleague->id)
            ->assertJsonPath('data.local.start', '09:00')
            ->assertJsonPath('data.pause_minutes', 60);
        $newId = $response->json('data.id');

        $this->assertSoftDeleted($old);
        $this->assertSame($newId, $old->refresh()->replaced_by_id);
        $this->assertDatabaseHas('activity_entries', ['action' => 'shift.replaced', 'actor_id' => $this->manager->id]);

        // Tant que le remplaçant est actif, l'ancien shift ne se restaure pas.
        $this->postJson('/api/v1/shifts/'.$old->id.'/restore')->assertStatus(422);

        // Remplacer un shift supprimé.
        $this->postJson('/api/v1/shifts/'.$old->id.'/replace', ['user_id' => $this->colleague->id])->assertStatus(422);
        $this->getJson('/api/v1/shifts/'.$old->id)->assertOk()->assertJsonPath('data.replaced_by_id', $newId)->assertJsonPath('data.is_deleted', true);
    }

    public function test_replace_keeps_the_employee_unless_user_id_is_explicitly_null(): void
    {
        $this->actingWithToken($this->manager);

        $kept = $this->shift($this->employee, $this->alpha);
        $this->postJson('/api/v1/shifts/'.$kept->id.'/replace', ['notes' => 'same person'])->assertCreated()
            ->assertJsonPath('data.user_id', $this->employee->id)->assertJsonPath('data.is_open', false);

        $open = $this->shift($this->colleague, $this->alpha, '2026-11-03');
        $this->postJson('/api/v1/shifts/'.$open->id.'/replace', ['user_id' => null])->assertCreated()
            ->assertJsonPath('data.user_id', null)->assertJsonPath('data.is_open', true);
    }

    public function test_bulk_delete_is_all_or_nothing(): void
    {
        $a = $this->shift($this->employee, $this->alpha, '2026-11-02');
        $b = $this->shift($this->colleague, $this->alpha, '2026-11-03');
        $foreign = $this->shift($this->betaEmployee, $this->beta, '2026-11-03');

        $this->actingWithToken($this->manager);
        $this->postJson('/api/v1/shifts/bulk-delete', ['ids' => [$a->id, $foreign->id]])->assertStatus(403);
        $this->assertNotSoftDeleted($a);

        $this->postJson('/api/v1/shifts/bulk-delete', ['ids' => [$a->id, $b->id, $a->id, 999999]])->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);

        $this->postJson('/api/v1/shifts/bulk-delete', ['ids' => []])->assertStatus(422);
        $this->postJson('/api/v1/shifts/bulk-delete', ['ids' => ['x']])->assertStatus(422);
    }

    public function test_conflicts_are_listed_and_resolved_by_keeping_the_newer_shift(): void
    {
        $older = $this->shift($this->employee, $this->alpha, '2026-11-02', '09:00', '17:00');
        $newer = $this->shift($this->employee, $this->alpha, '2026-11-02', '15:00', '20:00');
        $this->shift($this->betaEmployee, $this->beta, '2026-11-02', '09:00', '17:00');
        $this->shift($this->betaEmployee, $this->beta, '2026-11-02', '10:00', '12:00');
        $this->alpha->update(['max_shift_minutes' => 420, 'min_shift_minutes' => 0]);

        $this->actingWithToken($this->manager);
        $response = $this->getJson('/api/v1/shifts/conflicts?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertJsonCount(1, 'data.overlaps')
            ->assertJsonPath('data.overlaps.0.overlap_minutes', 120)
            ->assertJsonPath('data.overlaps.0.keep_id', $newer->id)
            ->assertJsonCount(1, 'data.too_long')
            ->assertJsonPath('data.too_long.0.id', $older->id);
        $this->assertSame([$older->id, $newer->id], collect([$response->json('data.overlaps.0.first.id'), $response->json('data.overlaps.0.second.id')])->sort()->values()->all());

        $this->getJson('/api/v1/shifts/conflicts?from=2026-01-01&to=2026-12-31')->assertStatus(422)->assertJsonValidationErrorFor('to', 'errors');

        $this->postJson('/api/v1/shifts/conflicts/resolve', ['first_id' => $older->id, 'second_id' => $newer->id])->assertOk()->assertJsonPath('data.id', $newer->id);
        $this->assertSoftDeleted($older);
        $this->assertSame($newer->id, $older->refresh()->replaced_by_id);

        // Plus rien à résoudre.
        $this->postJson('/api/v1/shifts/conflicts/resolve', ['first_id' => $older->id, 'second_id' => $newer->id])->assertNoContent();
        $this->getJson('/api/v1/shifts/conflicts?from=2026-11-01&to=2026-11-30')->assertJsonCount(0, 'data.overlaps');
    }

    public function test_resolving_requires_a_real_conflict_and_rights(): void
    {
        $a = $this->shift($this->employee, $this->alpha, '2026-11-02', '09:00', '10:00');
        $b = $this->shift($this->employee, $this->alpha, '2026-11-03', '09:00', '10:00');
        $foreign = $this->shift($this->betaEmployee, $this->beta);

        $this->actingWithToken($this->manager);
        $this->postJson('/api/v1/shifts/conflicts/resolve', ['first_id' => $a->id, 'second_id' => $b->id])->assertStatus(422)->assertJsonValidationErrorFor('shift', 'errors');
        $this->postJson('/api/v1/shifts/conflicts/resolve', ['first_id' => $a->id, 'second_id' => $foreign->id])->assertStatus(404);
        $this->postJson('/api/v1/shifts/conflicts/resolve', ['first_id' => $a->id])->assertStatus(422);

        $reader = User::factory()->create();
        $reader->assignRole($this->customRole(['shifts.view']), $this->alpha);
        $c = $this->shift($this->employee, $this->alpha, '2026-11-03', '09:30', '11:00');
        $this->actingWithToken($reader)->postJson('/api/v1/shifts/conflicts/resolve', ['first_id' => $b->id, 'second_id' => $c->id])->assertStatus(403);
    }
}
