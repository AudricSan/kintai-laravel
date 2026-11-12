<?php

namespace Tests\Feature\Api\V1;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class MeTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_me_returns_the_account_without_sensitive_fields(): void
    {
        $store = Store::factory()->create();
        $user = $this->employeeOf($store, ['email' => 'jane@example.com', 'employee_code' => 'E001']);

        $response = $this->actingWithToken($user)->getJson('/api/v1/auth/me')->assertOk();

        $response->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('data.user.employee_code', 'E001')
            ->assertJsonPath('data.user.two_factor_enabled', false)
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.is_manager', false);

        $content = (string) $response->getContent();
        foreach (['"password"', 'remember_token', 'two_factor_secret', 'recovery_codes', 'avatar_path'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_an_employee_has_no_management_right_and_sees_only_his_stores(): void
    {
        $alpha = Store::factory()->create(['name' => 'Alpha']);
        $beta = Store::factory()->create(['name' => 'Beta']);
        $user = $this->employeeOf($alpha);

        $response = $this->actingWithToken($user)->getJson('/api/v1/auth/me')->assertOk();

        $response->assertJsonCount(1, 'data.stores');
        $response->assertJsonPath('data.stores.0.id', $alpha->id);
        $response->assertJsonPath('data.stores.0.membership.is_active', true);
        $this->assertNotContains($beta->id, array_column($response->json('data.stores'), 'id'));

        $response->assertJsonPath('data.stores.0.permissions', []);
        $response->assertJsonPath('data.permissions.global', []);
        $response->assertJsonPath('data.roles.0.slug', 'employee');
        $response->assertJsonPath('data.roles.0.store_id', $alpha->id);
    }

    public function test_a_manager_has_the_rights_of_his_store_and_not_of_others(): void
    {
        $alpha = Store::factory()->create();
        $beta = Store::factory()->create();
        $manager = $this->managerOf($alpha);
        $manager->stores()->attach($alpha);
        $manager->stores()->attach($beta);

        $response = $this->actingWithToken($manager)->getJson('/api/v1/auth/me')->assertOk();

        $response->assertJsonPath('data.is_manager', true);
        $stores = collect($response->json('data.stores'))->keyBy('id');
        $this->assertContains('employees.update', $stores[$alpha->id]['permissions']);
        $this->assertNotContains('employees.update', $stores[$beta->id]['permissions']);
    }

    public function test_an_owner_has_every_permission(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner();
        $owner->stores()->attach($store);

        $response = $this->actingWithToken($owner)->getJson('/api/v1/auth/me')->assertOk();

        $response->assertJsonPath('data.is_owner', true);
        $global = $response->json('data.permissions.global');
        $this->assertContains('stores.delete', $global);
        $this->assertContains('payroll.export', $global);
        $this->assertContains('stores.delete', $response->json('data.stores.0.permissions'));
    }

    public function test_a_user_with_no_store_has_empty_lists(): void
    {
        $user = User::factory()->create();

        $this->actingWithToken($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.stores', [])
            ->assertJsonPath('data.roles', []);
    }
}
