<?php

namespace Tests\Feature\Api\V1;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class StoresApiTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    private Store $alpha;

    private Store $beta;

    private User $owner;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->alpha = Store::factory()->create(['name' => 'Alpha', 'code' => 'ALPHA']);
        $this->beta = Store::factory()->create(['name' => 'Beta', 'code' => 'BETA']);
        $this->owner = $this->owner();
        $this->manager = $this->managerOf($this->alpha);
        $this->alpha->members()->attach($this->manager);
        $this->employee = $this->employeeOf($this->alpha);
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(string $code = 'NEW1'): array
    {
        return ['code' => $code, 'name' => 'New store', 'timezone' => 'Asia/Tokyo', 'currency' => 'JPY'];
    }

    public function test_the_store_list_is_restricted_by_scope_and_rights(): void
    {
        $ids = fn () => collect($this->getJson('/api/v1/stores')->assertOk()->json('data'))->pluck('id')->all();

        $this->actingWithToken($this->owner);
        $this->assertEqualsCanonicalizing([$this->alpha->id, $this->beta->id], $ids());

        $this->actingWithToken($this->manager);
        $this->assertSame([$this->alpha->id], $ids());

        $this->actingWithToken($this->employee)->getJson('/api/v1/stores')->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    public function test_reading_a_store_hides_the_ones_out_of_scope(): void
    {
        $this->actingWithToken($this->manager)->getJson('/api/v1/stores/'.$this->alpha->id)->assertOk()
            ->assertJsonPath('data.code', 'ALPHA')
            ->assertJsonStructure(['data' => ['id', 'name', 'timezone', 'currency', 'min_shift_minutes', 'max_shift_minutes', 'features']]);
        $this->getJson('/api/v1/stores/'.$this->beta->id)->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->getJson('/api/v1/stores/999999')->assertStatus(404);
        $this->actingWithToken($this->employee)->getJson('/api/v1/stores/'.$this->alpha->id)->assertStatus(403);
    }

    public function test_creating_updating_and_deleting_a_store(): void
    {
        $this->actingWithToken($this->owner);

        $response = $this->postJson('/api/v1/stores', $this->storePayload('new1') + ['features' => ['shifts' => true]])->assertCreated()
            ->assertJsonPath('data.code', 'NEW1')
            ->assertJsonPath('data.currency', 'JPY')
            ->assertJsonPath('data.min_shift_minutes', 120)
            ->assertJsonPath('data.is_active', true);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('activity_entries', ['action' => 'store.created', 'subject_id' => $id, 'actor_id' => $this->owner->id]);

        $this->putJson('/api/v1/stores/'.$id, ['name' => 'Renamed', 'min_shift_minutes' => 60])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.code', 'NEW1')->assertJsonPath('data.min_shift_minutes', 60)
            ->assertJsonPath('data.max_shift_minutes', 480);

        $this->putJson('/api/v1/stores/'.$id, ['features' => ['shifts' => false]])->assertOk()->assertJsonPath('data.features.shifts', false);

        $this->deleteJson('/api/v1/stores/'.$id)->assertNoContent();
        $this->getJson('/api/v1/stores/'.$id)->assertStatus(404);
    }

    public function test_store_rules_and_rights(): void
    {
        $this->actingWithToken($this->owner);
        $this->postJson('/api/v1/stores', $this->storePayload('ALPHA'))->assertStatus(422)->assertJsonValidationErrorFor('code', 'errors');
        $this->postJson('/api/v1/stores', ['code' => 'x'])->assertStatus(422)->assertJsonValidationErrorFor('name', 'errors');
        $this->postJson('/api/v1/stores', ['timezone' => 'Mars/Base'] + $this->storePayload('MARS'))->assertStatus(422)->assertJsonValidationErrorFor('timezone', 'errors');
        $this->putJson('/api/v1/stores/'.$this->alpha->id, ['max_shift_minutes' => 10])->assertStatus(422);

        // Le gérant modifie son magasin mais ne crée ni ne supprime.
        $this->actingWithToken($this->manager);
        $this->putJson('/api/v1/stores/'.$this->alpha->id, ['name' => 'Alpha 2'])->assertOk();
        $this->postJson('/api/v1/stores', $this->storePayload('NEW2'))->assertStatus(403);
        $this->deleteJson('/api/v1/stores/'.$this->alpha->id)->assertStatus(403);
        $this->putJson('/api/v1/stores/'.$this->beta->id, ['name' => 'Hack'])->assertStatus(404);
        $this->assertSame('Beta', $this->beta->refresh()->name);
    }

    public function test_members_list_read_and_scope(): void
    {
        $this->actingWithToken($this->manager);

        $response = $this->getJson('/api/v1/stores/'.$this->alpha->id.'/members')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->employee->id, $ids);
        $response->assertJsonStructure(['data' => [['id', 'name', 'store_id', 'staff_code', 'hire_date', 'contract_type', 'is_active', 'roles']], 'meta']);

        $this->getJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$this->employee->id)->assertOk()
            ->assertJsonPath('data.roles.0.slug', 'employee');
        $this->getJson('/api/v1/stores/'.$this->alpha->id.'/members/999999')->assertStatus(404);
        $this->getJson('/api/v1/stores/'.$this->beta->id.'/members')->assertStatus(404);
        $this->actingWithToken($this->employee)->getJson('/api/v1/stores/'.$this->alpha->id.'/members')->assertStatus(403);
    }

    public function test_members_lifecycle_and_rules(): void
    {
        $newcomer = User::factory()->create();
        $other = $this->employeeOf($this->alpha, ['employee_code' => 'OTH1']);
        $this->alpha->members()->updateExistingPivot($other->id, ['staff_code' => 'S-1']);
        $manager = $this->role('manager');

        $this->actingWithToken($this->manager);

        $this->postJson('/api/v1/stores/'.$this->alpha->id.'/members', ['user_id' => $newcomer->id, 'staff_code' => 'S-2', 'hire_date' => '2026-01-05'])->assertCreated()
            ->assertJsonPath('data.staff_code', 'S-2')->assertJsonPath('data.hire_date', '2026-01-05')->assertJsonPath('data.roles.0.slug', 'employee');
        $this->assertDatabaseHas('activity_entries', ['action' => 'store.member_added', 'actor_id' => $this->manager->id]);

        // Déjà membre ; code personnel déjà pris dans le magasin.
        $this->postJson('/api/v1/stores/'.$this->alpha->id.'/members', ['user_id' => $newcomer->id])->assertStatus(422)->assertJsonValidationErrorFor('user_id', 'errors');
        $spare = User::factory()->create();
        $this->postJson('/api/v1/stores/'.$this->alpha->id.'/members', ['user_id' => $spare->id, 'staff_code' => 'S-1'])->assertStatus(422)->assertJsonValidationErrorFor('staff_code', 'errors');
        // Compte inactif.
        $this->postJson('/api/v1/stores/'.$this->alpha->id.'/members', ['user_id' => User::factory()->inactive()->create()->id])->assertStatus(422);

        $this->putJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$newcomer->id, ['contract_type' => 'part-time'])->assertOk()
            ->assertJsonPath('data.contract_type', 'part-time')->assertJsonPath('data.staff_code', 'S-2')->assertJsonPath('data.is_active', true);
        $this->putJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$newcomer->id, ['staff_code' => 'S-1'])->assertStatus(422);
        $this->putJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$newcomer->id, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        // Plafond de rôle : un rôle avec stores.create est au-dessus des droits du gérant.
        $above = $this->customRole(['stores.create']);
        $this->putJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$newcomer->id, ['role_id' => $above->id])->assertStatus(422)->assertJsonValidationErrorFor('role_id', 'errors');
        $this->putJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$newcomer->id, ['role_id' => $manager->id])->assertOk()->assertJsonPath('data.roles.0.slug', 'manager');

        // Soi-même : pas de changement de rôle ni de retrait.
        $this->putJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$this->manager->id, ['role_id' => $this->role('employee')->id])->assertStatus(422);
        $this->deleteJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$this->manager->id)->assertStatus(422);

        $this->deleteJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$newcomer->id)->assertNoContent();
        $this->getJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$newcomer->id)->assertStatus(404);
        $this->deleteJson('/api/v1/stores/'.$this->alpha->id.'/members/'.$newcomer->id)->assertStatus(404);
    }

    public function test_an_owner_member_cannot_be_added_by_a_manager(): void
    {
        $this->actingWithToken($this->manager)->postJson('/api/v1/stores/'.$this->alpha->id.'/members', ['user_id' => $this->owner->id])->assertStatus(403);
    }

    public function test_shift_types_list_read_and_scope(): void
    {
        $mine = ShiftType::factory()->create(['code' => 'MINE']);
        $mine->stores()->attach($this->alpha);
        $theirs = ShiftType::factory()->create(['code' => 'THEIRS']);
        $theirs->stores()->attach($this->beta);

        $codes = fn () => collect($this->getJson('/api/v1/shift-types')->assertOk()->json('data'))->pluck('code')->all();

        $this->actingWithToken($this->manager);
        $this->assertSame(['MINE'], $codes());
        $this->getJson('/api/v1/shift-types/'.$mine->id)->assertOk()->assertJsonPath('data.store_ids', [$this->alpha->id])->assertJsonPath('data.starts_at', '09:00');
        $this->getJson('/api/v1/shift-types/'.$theirs->id)->assertStatus(404);

        $this->actingWithToken($this->owner);
        $this->assertEqualsCanonicalizing(['MINE', 'THEIRS'], $codes());
        $this->getJson('/api/v1/shift-types?store_id='.$this->beta->id)->assertJsonCount(1, 'data');

        $this->actingWithToken($this->employee)->getJson('/api/v1/shift-types')->assertStatus(403);
    }

    public function test_shift_types_lifecycle_and_rules(): void
    {
        $this->actingWithToken($this->manager);
        $payload = ['code' => 'day', 'name' => 'Day', 'starts_at' => '08:00', 'ends_at' => '16:00', 'hourly_rate' => 1100, 'store_ids' => [$this->alpha->id]];

        $id = $this->postJson('/api/v1/shift-types', $payload)->assertCreated()
            ->assertJsonPath('data.code', 'DAY')->assertJsonPath('data.hourly_rate', '1100.00')->assertJsonPath('data.store_ids', [$this->alpha->id])
            ->json('data.id');
        $this->assertDatabaseHas('activity_entries', ['action' => 'shift_type.created', 'subject_id' => $id, 'actor_id' => $this->manager->id]);

        $this->putJson('/api/v1/shift-types/'.$id, ['name' => 'Early'])->assertOk()->assertJsonPath('data.name', 'Early')->assertJsonPath('data.code', 'DAY')->assertJsonPath('data.store_ids', [$this->alpha->id]);

        // Règles : plage nulle, magasin hors périmètre, magasin obligatoire.
        $this->postJson('/api/v1/shift-types', ['ends_at' => '08:00'] + $payload)->assertStatus(422)->assertJsonValidationErrorFor('ends_at', 'errors');
        $this->postJson('/api/v1/shift-types', ['store_ids' => [$this->beta->id]] + $payload)->assertStatus(422);
        $this->postJson('/api/v1/shift-types', ['store_ids' => []] + $payload)->assertStatus(422)->assertJsonValidationErrorFor('store_ids', 'errors');

        // Type d'usage : les shifts le référencent, il ne se supprime pas.
        Shift::factory()->create(['store_id' => $this->alpha->id, 'shift_type_id' => $id]);
        $this->deleteJson('/api/v1/shift-types/'.$id)->assertStatus(422)->assertJsonValidationErrorFor('delete', 'errors');

        $free = ShiftType::factory()->create();
        $free->stores()->attach($this->alpha);
        $this->deleteJson('/api/v1/shift-types/'.$free->id)->assertNoContent();
        $this->assertModelMissing($free);

        $this->actingWithToken($this->employee)->postJson('/api/v1/shift-types', $payload)->assertStatus(403);
        $this->deleteJson('/api/v1/shift-types/'.$id)->assertStatus(403);
    }

    public function test_a_shared_type_cannot_be_edited_by_a_restricted_manager(): void
    {
        $shared = ShiftType::factory()->create();
        $shared->stores()->attach([$this->alpha->id, $this->beta->id]);

        $this->actingWithToken($this->manager)->putJson('/api/v1/shift-types/'.$shared->id, ['name' => 'Hack'])->assertStatus(403);
        $this->deleteJson('/api/v1/shift-types/'.$shared->id)->assertStatus(403);

        $this->actingWithToken($this->owner)->putJson('/api/v1/shift-types/'.$shared->id, ['name' => 'Fine'])->assertOk();
    }

    public function test_a_type_is_attached_and_detached_per_store(): void
    {
        $type = ShiftType::factory()->create();
        $type->stores()->attach($this->alpha);

        $this->actingWithToken($this->owner);
        $this->postJson('/api/v1/shift-types/'.$type->id.'/stores/'.$this->beta->id)->assertOk()
            ->assertJsonPath('data.store_ids', [$this->alpha->id, $this->beta->id]);
        // Idempotent.
        $this->postJson('/api/v1/shift-types/'.$type->id.'/stores/'.$this->beta->id)->assertOk()->assertJsonCount(2, 'data.store_ids');

        $this->deleteJson('/api/v1/shift-types/'.$type->id.'/stores/'.$this->beta->id)->assertOk()->assertJsonPath('data.store_ids', [$this->alpha->id]);
        $this->deleteJson('/api/v1/shift-types/'.$type->id.'/stores/'.$this->beta->id)->assertOk();

        // Un type reste dans au moins un magasin.
        $this->deleteJson('/api/v1/shift-types/'.$type->id.'/stores/'.$this->alpha->id)->assertStatus(422)->assertJsonValidationErrorFor('store', 'errors');
        $this->postJson('/api/v1/shift-types/'.$type->id.'/stores/999999')->assertStatus(404);

        $this->actingWithToken($this->manager)->postJson('/api/v1/shift-types/'.$type->id.'/stores/'.$this->beta->id)->assertStatus(403);
    }
}
