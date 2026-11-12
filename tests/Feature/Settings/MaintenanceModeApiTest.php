<?php

namespace Tests\Feature\Settings;

use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class MaintenanceModeApiTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->employee = $this->employeeOf(Store::factory()->create());
    }

    private function enableMaintenance(string $message = 'Back at 6pm.'): void
    {
        $this->app->make(AppSettings::class)->setMany(['maintenance.enabled' => true, 'maintenance.message' => $message]);
    }

    public function test_the_api_ping_stays_reachable(): void
    {
        $this->enableMaintenance();

        $this->getJson('/api/v1/ping')->assertOk()->assertJsonPath('api_version', '1');
    }

    public function test_the_api_answers_503_json_with_a_stable_code(): void
    {
        $this->enableMaintenance('Back at 6pm.');
        $token = $this->tokenFor($this->employee);

        $response = $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(503)->assertExactJson(['error' => 'Back at 6pm.', 'code' => 'maintenance']);
        $this->assertSame('300', $response->headers->get('Retry-After'));

        // La connexion est fermée aussi ; sans jeton, un endpoint protégé répond 401 avant tout (rien à protéger de plus).
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['login' => 'x', 'password' => 'y', 'device_name' => 'z'])->assertStatus(503)->assertJsonPath('code', 'maintenance');
    }

    public function test_an_owner_token_keeps_api_access(): void
    {
        $this->enableMaintenance();
        $token = $this->tokenFor($this->owner());

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();
    }
}
