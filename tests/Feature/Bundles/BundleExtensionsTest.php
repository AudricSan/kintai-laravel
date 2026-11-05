<?php

namespace Tests\Feature\Bundles;

use App\Actions\Bundles\DisableBundle;
use App\Actions\Bundles\EnableBundle;
use App\Models\ActivityEntry;
use App\Models\Role;
use App\Models\Store;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\Fixtures\Bundles\DemoBundle\DemoBundleServiceProvider;
use Tests\TestCase;

/**
 * Contrat 1.2 : droits par défaut accordés aux rôles à la première activation, et API des bundles
 * (`routes/api.php` sous `/api/v1/<slug>`, pile et erreurs de l'API du Core).
 */
class BundleExtensionsTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;
    use UsesApi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->app->register(DemoBundleServiceProvider::class, true);
    }

    /**
     * @return list<string>
     */
    private function permissionsOf(string $slug): array
    {
        return Role::where('slug', $slug)->firstOrFail()->permissions()->where('permission', 'like', 'demo.%')->pluck('permission')->sort()->values()->all();
    }

    // -- Droits par défaut ---------------------------------------------------------------------------------------

    public function test_the_first_activation_grants_the_defaults_to_manager_and_employee_only(): void
    {
        $custom = $this->customRole(['shifts.view']);
        $owner = $this->owner();

        $this->assertTrue(app(EnableBundle::class)->handle($owner, 'demo'));

        $this->assertSame(['demo.manage', 'demo.view'], $this->permissionsOf('manager'));
        $this->assertSame(['demo.view'], $this->permissionsOf('employee'));
        $this->assertSame([], $this->permissionsOf('owner'));
        $this->assertSame(0, $custom->permissions()->where('permission', 'like', 'demo.%')->count());

        $entry = ActivityEntry::where('action', 'bundle.permissions_granted')->firstOrFail();
        $this->assertSame(['manager' => ['demo.view', 'demo.manage'], 'employee' => ['demo.view']], $entry->properties['roles']);
        $this->assertFalse(Role::where('slug', 'manager')->firstOrFail()->permissions()->where('permission', 'demo.view')->firstOrFail()->all_stores);
    }

    public function test_a_reactivation_does_not_restore_rights_the_owner_removed(): void
    {
        $owner = $this->owner();
        app(EnableBundle::class)->handle($owner, 'demo');
        Role::where('slug', 'employee')->firstOrFail()->permissions()->where('permission', 'demo.view')->delete();

        app(DisableBundle::class)->handle($owner, 'demo');
        app(EnableBundle::class)->handle($owner, 'demo');

        $this->assertSame([], $this->permissionsOf('employee'));
        $this->assertSame(1, ActivityEntry::where('action', 'bundle.permissions_granted')->count());
    }

    public function test_existing_rights_are_left_alone(): void
    {
        $manager = Role::where('slug', 'manager')->firstOrFail();
        $manager->permissions()->create(['permission' => 'demo.view', 'all_stores' => true]);

        app(EnableBundle::class)->handle($this->owner(), 'demo');

        $this->assertTrue($manager->permissions()->where('permission', 'demo.view')->firstOrFail()->all_stores);
    }

    public function test_an_employee_holds_the_default_rights_after_the_first_activation(): void
    {
        $store = Store::factory()->create();
        $employee = $this->employeeOf($store);
        app(EnableBundle::class)->handle($this->owner(), 'demo');
        $this->app->register(DemoBundleServiceProvider::class, true);

        $this->assertTrue(Gate::forUser($employee)->allows('demo.view'));
        $this->assertFalse(Gate::forUser($employee)->allows('demo.manage'));
    }

    public function test_the_defaults_only_target_manager_and_employee(): void
    {
        $provider = $this->probe();
        $provider->declare(['manager' => ['view']]);
        $this->assertSame(['manager' => ['bad.view']], $provider->defaultPermissions());

        $this->expectException(InvalidArgumentException::class);
        $provider->declare(['owner' => ['view']]);
    }

    public function test_the_defaults_must_be_declared_actions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->probe()->declare(['employee' => ['edit']]);
    }

    private function probe(): object
    {
        return new class($this->app) extends BundleServiceProvider
        {
            public function slug(): string
            {
                return 'bad';
            }

            public function name(): string
            {
                return 'Bad';
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function description(): string
            {
                return 'Bad';
            }

            /**
             * @param  array<string, list<string>>  $defaults
             */
            public function declare(array $defaults): void
            {
                $this->permissions('bad', ['view'], $defaults);
            }
        };
    }

    // -- API des bundles -----------------------------------------------------------------------------------------

    public function test_a_bundle_api_exists_only_when_enabled(): void
    {
        $user = $this->owner();
        $this->actingWithToken($user)->getJson('/api/v1/demo/items')->assertNotFound()->assertJson(['code' => 'not_found']);

        $this->enableBundle('demo');

        $this->actingWithToken($user)->getJson('/api/v1/demo/items?per_page=500')
            ->assertOk()->assertJson(['user_id' => $user->id, 'per_page' => 100]);
        $this->assertSame(url('/api/v1/demo/items'), route('api.v1.demo.items.index'));
    }

    public function test_a_bundle_api_has_the_stack_of_the_core_api(): void
    {
        $this->enableBundle('demo');
        $user = $this->owner();

        $this->getJson('/api/v1/demo/items')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->actingWithToken($user)->postJson('/api/v1/demo/items', [])
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors(['name']);
        $this->actingWithToken($user)->getJson('/api/v1/demo/conflict')
            ->assertStatus(409)->assertExactJson(['error' => 'Demo conflict.', 'code' => 'demo_conflict']);
        $this->actingWithToken($user)->deleteJson('/api/v1/demo/items')->assertStatus(405)->assertJson(['code' => 'method_not_allowed']);

        $token = $this->tokenFor($user);
        $user->update(['is_active' => false]);
        $this->flushHeaders();
        $this->withToken($token)->getJson('/api/v1/demo/items')->assertStatus(401);
    }

    public function test_a_bundle_api_follows_the_maintenance_mode(): void
    {
        $this->enableBundle('demo');
        $employee = $this->employeeOf(Store::factory()->create());
        app(AppSettings::class)->set('maintenance.enabled', true);

        $this->actingWithToken($employee)->getJson('/api/v1/demo/items')->assertStatus(503)->assertJson(['code' => 'maintenance']);
    }
}
