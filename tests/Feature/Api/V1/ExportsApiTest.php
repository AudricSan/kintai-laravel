<?php

namespace Tests\Feature\Api\V1;

use App\Exports\ExportDatasetRegistry;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class ExportsApiTest extends TestCase
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
        $this->alpha = Store::factory()->create();
        $this->beta = Store::factory()->create();
        $this->owner = $this->owner();
        $this->manager = $this->managerOf($this->alpha);
        $this->employee = $this->employeeOf($this->alpha);
        Shift::factory()->create(['store_id' => $this->alpha->id, 'user_id' => $this->employee->id, 'shift_date' => '2026-11-02']);
    }

    public function test_exports_require_a_token(): void
    {
        $this->getJson('/api/v1/exports')->assertStatus(401);
        $this->getJson('/api/v1/exports/shifts?format=csv')->assertStatus(401);
    }

    public function test_the_list_follows_the_rights(): void
    {
        $keys = fn () => collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->pluck('key')->all();
        $registered = array_keys(app(ExportDatasetRegistry::class)->all());

        $this->actingWithToken($this->owner);
        $this->assertEqualsCanonicalizing($registered, $keys());
        $this->getJson('/api/v1/exports')->assertJsonStructure(['data' => [['key', 'label', 'formats', 'has_period', 'per_employee', 'store_ids', 'self_service']]]);

        $this->actingWithToken($this->manager);
        $managerEntry = collect($this->getJson('/api/v1/exports')->json('data'))->firstWhere('key', 'shifts');
        $this->assertSame([$this->alpha->id], $managerEntry['store_ids']);

        // Un employé n'exporte que ses propres données, pour les jeux qui le permettent.
        $this->actingWithToken($this->employee);
        $employeeEntries = collect($this->getJson('/api/v1/exports')->json('data'));
        $this->assertTrue($employeeEntries->isNotEmpty());
        $this->assertTrue($employeeEntries->every(fn (array $entry): bool => $entry['self_service'] === true && $entry['store_ids'] === []));
    }

    public function test_downloading_an_export_is_scoped_and_logged(): void
    {
        $this->actingWithToken($this->manager);
        $query = '?format=csv&from=2026-11-01&to=2026-11-30';

        $response = $this->get('/api/v1/exports/shifts'.$query)->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertDatabaseHas('activity_entries', ['action' => 'export.downloaded', 'actor_id' => $this->manager->id]);

        $this->getJson('/api/v1/exports/shifts'.$query.'&stores[]='.$this->beta->id)->assertStatus(403);
        $this->getJson('/api/v1/exports/shifts?format=docx')->assertStatus(422)->assertJsonValidationErrorFor('format', 'errors');
        $this->getJson('/api/v1/exports/shifts?format=csv&from=2026-01-01&to=2026-12-31&')->assertOk();
        $this->getJson('/api/v1/exports/unknown?format=csv')->assertStatus(404);

        $this->actingWithToken($this->employee)->getJson('/api/v1/exports/shifts'.$query)->assertStatus(403);
        $this->get('/api/v1/exports/shifts'.$query.'&target=me')->assertOk();
    }
}
