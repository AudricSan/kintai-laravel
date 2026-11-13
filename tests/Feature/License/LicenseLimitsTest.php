<?php

namespace Tests\Feature\License;

use App\Actions\Employees\CreateEmployee;
use App\Actions\Stores\CreateStore;
use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\SignsLicenseTokens;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * Plafonds de la licence à la création : refus au plafond, null illimité, existant jamais touché.
 */
class LicenseLimitsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use SignsLicenseTokens;
    use UsesApi;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.license.issuer' => null]);
        $this->useTestKey();
        $this->seedRoles();
        $this->owner = $this->owner();
    }

    /** @param  array<string, int|string|list<string>>  $limits */
    private function activeLicense(array $limits): void
    {
        $this->app->make(AppSettings::class)->setMany(['license.key' => 'KEY', 'license.token' => $this->token(['limits' => $limits])]);
    }

    /** @return array<string, mixed> */
    private function storeInput(string $code): array
    {
        return ['code' => $code, 'name' => 'Shop '.$code, 'timezone' => 'Asia/Tokyo', 'currency' => 'JPY', 'is_active' => true, 'week_start_day' => 1,
            'min_staff_per_day' => 0, 'min_shift_minutes' => 120, 'max_shift_minutes' => 480];
    }

    /** @return array<string, string> */
    private function employeeInput(string $last = 'Martin'): array
    {
        return ['last_name' => $last, 'first_name' => 'Alice', 'furigana_last_name' => 'マルタン', 'furigana_first_name' => 'アリス'];
    }

    public function test_free_tier_refuses_a_second_store_and_keeps_the_existing_one(): void
    {
        config(['kintai.license.free_limits' => ['max_stores' => 1, 'max_employees' => 15]]);
        $first = app(CreateStore::class)->handle($this->owner, $this->storeInput('ONE'));

        try {
            app(CreateStore::class)->handle($this->owner, $this->storeInput('TWO'));
            $this->fail('The second store must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('(1)', $e->errors()['name'][0]);
        }

        $this->assertSame(1, Store::query()->count());
        $this->assertTrue($first->fresh()->exists);
    }

    public function test_existing_data_above_the_limit_is_kept_and_only_creation_is_refused(): void
    {
        Store::factory()->count(3)->create();
        config(['kintai.license.free_limits' => ['max_stores' => 1]]);

        try {
            app(CreateStore::class)->handle($this->owner, $this->storeInput('NEW'));
            $this->fail('The creation must be refused.');
        } catch (ValidationException) {
            $this->assertSame(3, Store::query()->count());
        }
    }

    public function test_a_valid_license_raises_the_store_limit(): void
    {
        config(['kintai.license.free_limits' => ['max_stores' => 1]]);
        Store::factory()->create();
        $this->activeLicense(['max_stores' => 3, 'max_employees' => 50]);

        app(CreateStore::class)->handle($this->owner, $this->storeInput('TWO'));
        app(CreateStore::class)->handle($this->owner, $this->storeInput('THREE'));

        $this->expectException(ValidationException::class);
        app(CreateStore::class)->handle($this->owner, $this->storeInput('FOUR'));
    }

    public function test_null_limit_means_unlimited(): void
    {
        config(['kintai.license.free_limits' => ['max_stores' => 1, 'max_employees' => 1]]);
        $this->activeLicense(['bundles' => '*']);
        Store::factory()->count(3)->create();

        app(CreateStore::class)->handle($this->owner, $this->storeInput('MORE'));
        app(CreateEmployee::class)->handle($this->owner, $this->employeeInput());

        $this->assertSame(4, Store::query()->count());
    }

    public function test_employee_creation_is_refused_at_the_limit_with_every_entry_point(): void
    {
        $store = Store::factory()->create();
        // Le propriétaire compte : un seul emplacement libre.
        config(['kintai.license.free_limits' => ['max_employees' => 2]]);

        app(CreateEmployee::class)->handle($this->owner, $this->employeeInput('Un') + ['store_id' => $store->id]);
        $this->assertSame(2, User::query()->count());

        try {
            app(CreateEmployee::class)->handle($this->owner, $this->employeeInput('Deux'));
            $this->fail('The employee must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('(2)', $e->errors()['last_name'][0]);
        }

        $this->actingWithToken($this->owner)
            ->postJson('/api/v1/users', $this->employeeInput('Trois') + ['store_id' => $store->id])
            ->assertStatus(422)->assertJsonValidationErrorFor('last_name', 'errors');

        $this->assertSame(2, User::query()->count());
    }

    public function test_store_creation_through_the_api_is_refused_at_the_limit(): void
    {
        Store::factory()->create();
        config(['kintai.license.free_limits' => ['max_stores' => 1]]);

        $this->actingWithToken($this->owner)->postJson('/api/v1/stores', $this->storeInput('API'))
            ->assertStatus(422)->assertJsonValidationErrorFor('name', 'errors');

        $this->assertSame(1, Store::query()->count());
    }
}
