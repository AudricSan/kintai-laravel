<?php

namespace Tests\Feature\Api\V1;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * Contrat public du 422 `validation_failed` pour les entrées validées par les FormRequest de l'API v1 : corps
 * `{error, code, errors}`, erreurs par champ, et ordre 403/404 avant 422 inchangé.
 */
class ValidationFormatTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    public function test_bulk_delete_reports_the_ids_errors(): void
    {
        $this->seedRoles();
        $manager = $this->managerOf(Store::factory()->create());

        $response = $this->actingWithToken($manager)->postJson('/api/v1/shifts/bulk-delete', ['ids' => ['abc']])->assertStatus(422);

        $this->assertSame(['error', 'code', 'errors'], array_keys($response->json()));
        $this->assertSame('validation_failed', $response->json('code'));
        $this->assertSame(['ids.0'], array_keys($response->json('errors')));
        $this->assertSame('The ids.0 field must be an integer.', $response->json('errors')['ids.0'][0]);
    }

    public function test_a_filter_is_validated_after_the_authorization(): void
    {
        $this->seedRoles();
        $store = Store::factory()->create();
        $employee = $this->employeeOf($store);

        // Sans droit : 403 même avec un filtre invalide.
        $this->actingWithToken($employee)->getJson('/api/v1/users?is_active=maybe')->assertStatus(403);

        $response = $this->actingWithToken($this->managerOf($store))->getJson('/api/v1/users?is_active=maybe&search='.str_repeat('a', 101))->assertStatus(422);

        $this->assertSame('validation_failed', $response->json('code'));
        $this->assertEqualsCanonicalizing(['is_active', 'search'], array_keys($response->json('errors')));
        $this->assertSame('The is active field must be true or false.', $response->json('errors.is_active.0'));
    }

    public function test_the_export_download_reports_the_format_error(): void
    {
        $this->seedRoles();
        $owner = $this->owner();

        $response = $this->actingWithToken($owner)->getJson('/api/v1/exports/shifts?format=docx')->assertStatus(422);

        $this->assertSame('validation_failed', $response->json('code'));
        $this->assertSame(['format'], array_keys($response->json('errors')));
    }
}
