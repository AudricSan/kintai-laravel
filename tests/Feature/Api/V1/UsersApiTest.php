<?php

namespace Tests\Feature\Api\V1;

use App\Models\ActivityEntry;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class UsersApiTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    private Store $alpha;

    private Store $beta;

    private User $owner;

    private User $manager;

    private User $employee;

    private User $betaEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->alpha = Store::factory()->create(['name' => 'Alpha']);
        $this->beta = Store::factory()->create(['name' => 'Beta']);
        $this->owner = $this->owner();
        $this->manager = $this->managerOf($this->alpha);
        $this->alpha->members()->attach($this->manager);
        $this->employee = $this->employeeOf($this->alpha);
        $this->betaEmployee = $this->employeeOf($this->beta);
    }

    public function test_users_require_a_token(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_the_list_is_restricted_to_the_stores_of_the_actor(): void
    {
        $response = $this->actingWithToken($this->manager)->getJson('/api/v1/users')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->employee->id, $ids);
        $this->assertContains($this->manager->id, $ids);
        $this->assertNotContains($this->betaEmployee->id, $ids);
        $response->assertJsonStructure(['data' => [['id', 'name', 'email', 'employee_code', 'is_active', 'is_owner', 'stores']], 'links', 'meta']);
        $this->assertArrayNotHasKey('password', $response->json('data.0'));

        $all = $this->actingWithToken($this->owner)->getJson('/api/v1/users?store_id='.$this->beta->id)->assertOk();
        $this->assertSame([$this->betaEmployee->id], collect($all->json('data'))->pluck('id')->all());

        // Un filtre sur un magasin hors périmètre ne révèle rien.
        $this->actingWithToken($this->manager)->getJson('/api/v1/users?store_id='.$this->beta->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_employee_cannot_list_users_but_reads_himself(): void
    {
        $this->actingWithToken($this->employee)->getJson('/api/v1/users')->assertStatus(403)->assertJsonPath('code', 'forbidden');

        $this->getJson('/api/v1/users/'.$this->employee->id)->assertOk()
            ->assertJsonPath('data.id', $this->employee->id)
            ->assertJsonPath('data.email', $this->employee->email);

        $this->getJson('/api/v1/users/'.$this->betaEmployee->id)->assertStatus(403);
        $this->getJson('/api/v1/users/999999')->assertStatus(403);
    }

    public function test_a_manager_reads_an_employee_of_his_store_but_not_another_store_nor_an_owner(): void
    {
        $this->actingWithToken($this->manager);

        $this->getJson('/api/v1/users/'.$this->employee->id)->assertOk()->assertJsonPath('data.stores.0.id', $this->alpha->id);
        $this->getJson('/api/v1/users/'.$this->betaEmployee->id)->assertStatus(403);

        $this->alpha->members()->attach($this->owner);
        $this->getJson('/api/v1/users/'.$this->owner->id)->assertStatus(403);

        $this->actingWithToken($this->owner)->getJson('/api/v1/users/999999')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_creating_an_employee_returns_the_temporary_password_once(): void
    {
        $payload = [
            'first_name' => 'Hana', 'last_name' => 'Sato', 'furigana_first_name' => 'ハナ', 'furigana_last_name' => 'サトウ',
            'email' => 'hana@example.com', 'employee_code' => 'hs01', 'store_id' => $this->alpha->id,
        ];

        $response = $this->actingWithToken($this->manager)->postJson('/api/v1/users', $payload)->assertCreated()
            ->assertJsonPath('data.email', 'hana@example.com')
            ->assertJsonPath('data.employee_code', 'HS01')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.temporary_password', '0000')
            ->assertJsonPath('data.stores.0.id', $this->alpha->id);

        $created = User::query()->findOrFail($response->json('data.id'));
        $this->assertTrue($this->alpha->members()->where('users.id', $created->id)->exists());
        $this->assertDatabaseHas('activity_entries', ['action' => 'employee.created', 'subject_id' => $created->id, 'actor_id' => $this->manager->id]);

        $this->getJson('/api/v1/users/'.$created->id)->assertOk()->assertJsonMissingPath('data.temporary_password');
    }

    public function test_creating_with_a_chosen_password_does_not_echo_one(): void
    {
        $this->actingWithToken($this->manager)->postJson('/api/v1/users', [
            'first_name' => 'Ken', 'last_name' => 'Ito', 'furigana_first_name' => 'ケン', 'furigana_last_name' => 'イトウ',
            'store_id' => $this->alpha->id, 'password' => 'a-Strong-Passw0rd!',
        ])->assertCreated()->assertJsonMissingPath('data.temporary_password');
    }

    public function test_business_rules_are_validation_errors(): void
    {
        $this->actingWithToken($this->manager);
        $base = ['first_name' => 'A', 'last_name' => 'B', 'furigana_first_name' => 'エー', 'furigana_last_name' => 'ビー', 'store_id' => $this->alpha->id];

        // Code employé déjà pris.
        $this->employee->forceFill(['employee_code' => 'DUP1'])->save();
        $this->postJson('/api/v1/users', $base + ['employee_code' => 'dup1'])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrorFor('employee_code', 'errors');

        // Plafond de rôle : le gérant n'a pas stores.create, il ne peut pas l'attribuer.
        $role = $this->customRole(['stores.create']);
        $this->postJson('/api/v1/users', $base + ['role_id' => $role->id])->assertStatus(422)->assertJsonValidationErrorFor('role_id', 'errors');

        // Magasin hors périmètre.
        $this->postJson('/api/v1/users', array_merge($base, ['store_id' => $this->beta->id]))->assertStatus(422)->assertJsonValidationErrorFor('store_id', 'errors');

        // Seul un Owner crée un Owner : droit manquant.
        $this->postJson('/api/v1/users', $base + ['is_owner' => true])->assertStatus(403);
        $this->actingWithToken($this->employee)->postJson('/api/v1/users', $base)->assertStatus(403);
    }

    public function test_updating_an_employee_keeps_the_omitted_fields(): void
    {
        $this->actingWithToken($this->manager)->putJson('/api/v1/users/'.$this->employee->id, ['phone' => '03-1234-5678', 'color' => '#112233'])
            ->assertOk()
            ->assertJsonPath('data.color', '#112233')
            ->assertJsonPath('data.profile.phone', '03-1234-5678')
            ->assertJsonPath('data.first_name', $this->employee->first_name)
            ->assertJsonPath('data.email', $this->employee->email);

        $this->putJson('/api/v1/users/'.$this->employee->id, ['first_name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', $this->employee->last_name.' Renamed');
        $this->putJson('/api/v1/users/'.$this->betaEmployee->id, ['color' => '#112233'])->assertStatus(403);
        $this->assertDatabaseHas('activity_entries', ['action' => 'employee.updated', 'subject_id' => $this->employee->id, 'actor_id' => $this->manager->id]);
    }

    public function test_the_owner_is_protected_and_nobody_edits_himself_into_trouble(): void
    {
        $second = $this->owner();

        // Le seul Owner actif ne peut pas être désactivé ; avec un second, soi-même non plus.
        $this->actingWithToken($this->owner)->putJson('/api/v1/users/'.$this->owner->id, ['is_active' => false])->assertStatus(422)->assertJsonValidationErrorFor('is_active', 'errors');
        $this->deleteJson('/api/v1/users/'.$this->owner->id)->assertStatus(422);

        // Un gérant ne retire pas ses propres droits.
        $this->actingWithToken($this->manager)->putJson('/api/v1/users/'.$this->manager->id, ['is_active' => false])->assertStatus(422);

        // Un Owner peut en désactiver un autre.
        $this->actingWithToken($this->owner)->putJson('/api/v1/users/'.$second->id, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_deleting_an_employee_anonymizes_it(): void
    {
        $this->actingWithToken($this->manager)->deleteJson('/api/v1/users/'.$this->employee->id)->assertNoContent();

        $this->assertNotSame($this->employee->email, User::withTrashed()->find($this->employee->id)?->email);

        $other = $this->employeeOf($this->alpha);
        $this->actingWithToken($other)->deleteJson('/api/v1/users/'.$this->betaEmployee->id)->assertStatus(403);
        $this->actingWithToken($this->manager)->deleteJson('/api/v1/users/'.$this->betaEmployee->id)->assertStatus(403);
    }

    public function test_reset_password_returns_the_temporary_password_only_when_defaulted(): void
    {
        $this->actingWithToken($this->manager);
        $this->employee->createToken('Phone');

        $this->postJson('/api/v1/users/'.$this->employee->id.'/reset-password')->assertOk()
            ->assertJsonPath('data.temporary_password', '0000')->assertJsonPath('data.must_change_password', true);
        $this->assertSame(0, $this->employee->tokens()->count());
        $this->assertTrue($this->employee->refresh()->must_change_password);

        $this->postJson('/api/v1/users/'.$this->employee->id.'/reset-password', ['password' => 'Another-Passw0rd!', 'must_change' => false])->assertNoContent();
        $this->assertFalse($this->employee->refresh()->must_change_password);

        $this->postJson('/api/v1/users/'.$this->employee->id.'/reset-password', ['password' => '1'])->assertStatus(422);
        $this->postJson('/api/v1/users/'.$this->betaEmployee->id.'/reset-password')->assertStatus(403);
        $this->actingWithToken($this->employee)->postJson('/api/v1/users/'.$this->employee->id.'/reset-password')->assertStatus(403);
    }

    public function test_personal_rates_lifecycle_and_rules(): void
    {
        $type = ShiftType::factory()->create(['hourly_rate' => 1000]);
        $type->stores()->attach($this->alpha);
        $foreign = ShiftType::factory()->create();
        $foreign->stores()->attach($this->beta);

        $this->actingWithToken($this->manager);
        $this->getJson('/api/v1/users/'.$this->employee->id.'/rates')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/users/'.$this->employee->id.'/rates', ['shift_type_id' => $type->id, 'hourly_rate' => 1250.5])->assertCreated()
            ->assertJsonPath('data.id', $type->id)->assertJsonPath('data.hourly_rate', '1250.50')->assertJsonPath('data.default_hourly_rate', '1000.00');

        $this->postJson('/api/v1/users/'.$this->employee->id.'/rates', ['shift_type_id' => $type->id, 'hourly_rate' => 1])->assertStatus(422)->assertJsonValidationErrorFor('shift_type_id', 'errors');
        $this->postJson('/api/v1/users/'.$this->employee->id.'/rates', ['shift_type_id' => $foreign->id, 'hourly_rate' => 1])->assertStatus(422);
        $this->putJson('/api/v1/users/'.$this->employee->id.'/rates/'.$type->id, ['hourly_rate' => 'abc'])->assertStatus(422);

        $this->putJson('/api/v1/users/'.$this->employee->id.'/rates/'.$type->id, ['hourly_rate' => 1300])->assertOk()->assertJsonPath('data.hourly_rate', '1300.00');
        $this->getJson('/api/v1/users/'.$this->employee->id.'/rates/'.$type->id)->assertOk();
        $this->getJson('/api/v1/users/'.$this->employee->id.'/rates')->assertOk()->assertJsonCount(1, 'data');

        // Lecture : soi-même ou payroll.view ; écriture : employees.update.
        $this->actingWithToken($this->employee)->getJson('/api/v1/users/'.$this->employee->id.'/rates')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/users/'.$this->employee->id.'/rates/'.$type->id, ['hourly_rate' => 1])->assertStatus(403);
        $this->deleteJson('/api/v1/users/'.$this->employee->id.'/rates/'.$type->id)->assertStatus(403);
        $colleague = $this->employeeOf($this->alpha);
        $this->actingWithToken($colleague)->getJson('/api/v1/users/'.$this->employee->id.'/rates')->assertStatus(403);

        $this->actingWithToken($this->manager)->deleteJson('/api/v1/users/'.$this->employee->id.'/rates/'.$type->id)->assertNoContent();
        $this->getJson('/api/v1/users/'.$this->employee->id.'/rates/'.$type->id)->assertStatus(404);
        $this->deleteJson('/api/v1/users/'.$this->employee->id.'/rates/'.$type->id)->assertStatus(404);
        $this->getJson('/api/v1/users/'.$this->betaEmployee->id.'/rates')->assertStatus(403);
    }

    public function test_activity_is_written_by_the_existing_listeners(): void
    {
        $this->actingWithToken($this->manager)->putJson('/api/v1/users/'.$this->employee->id, ['color' => '#445566'])->assertOk();

        $entry = ActivityEntry::query()->where('action', 'employee.updated')->latest('id')->firstOrFail();
        $this->assertSame($this->manager->id, $entry->actor_id);
        $this->assertSame($this->employee->id, $entry->subject_id);
    }
}
