<?php

namespace Tests\Feature\Api\V1;

use App\Activity\ActivityLogger;
use App\Models\ActivityEntry;
use App\Models\Availability;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class AvailabilityAndActivityApiTest extends TestCase
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

        $this->seedRoles();
        $this->alpha = Store::factory()->create();
        $this->beta = Store::factory()->create();
        $this->owner = $this->owner();
        $this->manager = $this->managerOf($this->alpha);
        $this->alpha->members()->attach($this->manager);
        $this->employee = $this->employeeOf($this->alpha);
        $this->colleague = $this->employeeOf($this->alpha);
        $this->betaEmployee = $this->employeeOf($this->beta);
    }

    private function availability(User $user, Store $store, int $day = 1, bool $available = true): Availability
    {
        return Availability::query()->create([
            'user_id' => $user->id, 'store_id' => $store->id, 'day_of_week' => $day, 'is_available' => $available,
            'starts_at' => $available ? '09:00' : null, 'ends_at' => $available ? '17:00' : null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function week(): array
    {
        return [
            '1' => ['available' => true, 'start' => '09:00', 'end' => '17:00'],
            '2' => ['available' => true, 'start' => '10:00', 'end' => '18:00'],
            '7' => ['available' => false],
        ];
    }

    public function test_availabilities_are_scoped_to_the_viewer(): void
    {
        $mine = $this->availability($this->employee, $this->alpha);
        $theirs = $this->availability($this->colleague, $this->alpha);
        $foreign = $this->availability($this->betaEmployee, $this->beta);

        $ids = fn (string $query = '') => collect($this->getJson('/api/v1/availabilities'.$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->actingWithToken($this->employee);
        $this->assertSame([$mine->id], $ids());
        $this->assertSame([$mine->id], $ids('?user_id='.$this->employee->id));
        $this->getJson('/api/v1/availabilities?user_id='.$this->colleague->id)->assertStatus(403);

        $this->actingWithToken($this->manager);
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $ids());
        $this->assertSame([$theirs->id], $ids('?user_id='.$this->colleague->id));
        $this->assertSame([], $ids('?store_id='.$this->beta->id));

        $this->actingWithToken($this->owner);
        $this->assertContains($foreign->id, $ids());
        $this->assertSame([$foreign->id], $ids('?store_id='.$this->beta->id));

        $this->getJson('/api/v1/availabilities')->assertJsonStructure(['data' => [['id', 'user_id', 'store_id', 'day_of_week', 'is_available', 'start', 'end']], 'meta']);
    }

    public function test_an_employee_saves_his_whole_week(): void
    {
        $this->availability($this->employee, $this->alpha, 3);

        $response = $this->actingWithToken($this->employee)->putJson('/api/v1/users/'.$this->employee->id.'/availabilities/'.$this->alpha->id, ['days' => $this->week()])->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.day_of_week', 1)
            ->assertJsonPath('data.0.start', '09:00')
            ->assertJsonPath('data.1.end', '18:00')
            ->assertJsonPath('data.2.is_available', false)
            ->assertJsonPath('data.6.is_available', false);

        $this->assertNull($response->json('data.2.start'));
        $this->assertSame(7, Availability::query()->where('user_id', $this->employee->id)->count());
    }

    public function test_only_the_employee_himself_can_save_and_the_data_is_validated(): void
    {
        $url = fn (User $user, Store $store) => '/api/v1/users/'.$user->id.'/availabilities/'.$store->id;

        $this->actingWithToken($this->manager)->putJson($url($this->employee, $this->alpha), ['days' => $this->week()])->assertStatus(403);
        $this->actingWithToken($this->owner)->putJson($url($this->employee, $this->alpha), ['days' => $this->week()])->assertStatus(403);
        $this->assertSame(0, Availability::query()->count());

        $this->actingWithToken($this->employee);
        // Magasin dont il n'est pas membre.
        $this->putJson($url($this->employee, $this->beta), ['days' => $this->week()])->assertStatus(403);
        $this->putJson($url($this->employee, $this->alpha).'9', ['days' => $this->week()])->assertStatus(404);
        // Données invalides.
        $this->putJson($url($this->employee, $this->alpha), [])->assertStatus(422);
        $this->putJson($url($this->employee, $this->alpha), ['days' => ['9' => ['available' => true]]])->assertStatus(422);
        $this->putJson($url($this->employee, $this->alpha), ['days' => ['1' => ['available' => true, 'start' => '09:00']]])->assertStatus(422);
        $this->putJson($url($this->employee, $this->alpha), ['days' => ['1' => ['available' => true, 'start' => '09:00', 'end' => '09:00']]])->assertStatus(422);
        $this->assertSame(0, Availability::query()->count());
    }

    public function test_activity_requires_stores_view_and_is_restricted_by_store(): void
    {
        $this->actingWithToken($this->employee)->getJson('/api/v1/activity')->assertStatus(403)->assertJsonPath('code', 'forbidden');

        ActivityEntry::query()->delete();
        $alpha = ActivityEntry::factory()->create(['action' => 'shift.created', 'store_id' => $this->alpha->id, 'actor_id' => $this->manager->id]);
        $beta = ActivityEntry::factory()->create(['action' => 'shift.deleted', 'store_id' => $this->beta->id]);
        $global = ActivityEntry::factory()->create(['action' => 'auth.login', 'store_id' => null]);

        $ids = fn (string $query = '') => collect($this->getJson('/api/v1/activity'.$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->actingWithToken($this->manager);
        $this->assertSame([$alpha->id], $ids());
        $this->assertSame([], $ids('?store='.$this->beta->id));

        $this->actingWithToken($this->owner);
        $this->assertEqualsCanonicalizing([$alpha->id, $beta->id, $global->id], $ids());
        $this->assertSame([$beta->id], $ids('?store='.$this->beta->id));
        $this->assertSame([$alpha->id], $ids('?actor='.$this->manager->id));
        $this->assertSame([$beta->id], $ids('?action=shift.deleted'));
        $this->assertSame([], $ids('?from=2999-01-01'));
        $this->getJson('/api/v1/activity?from=nope')->assertStatus(422);
    }

    public function test_activity_entries_have_the_documented_shape_and_pagination(): void
    {
        ActivityEntry::query()->delete();
        // Écrit par le journal lui-même : les secrets sont filtrés à l'écriture.
        foreach (range(1, 3) as $ignored) {
            app(ActivityLogger::class)->record('store.updated', $this->alpha, $this->alpha->id, ['name' => 'Alpha', 'password' => 'secret'], $this->manager);
        }

        $response = $this->actingWithToken($this->owner)->getJson('/api/v1/activity?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonStructure(['data' => [['id', 'action', 'label', 'actor' => ['id', 'name'], 'store' => ['id', 'name'], 'subject' => ['type', 'id'], 'target', 'details', 'properties', 'ip_address', 'created_at']], 'links', 'meta']);

        $this->assertStringNotContainsString('secret', (string) $response->getContent());
        $this->getJson('/api/v1/activity?per_page=2&page=2')->assertJsonCount(1, 'data');
    }
}
