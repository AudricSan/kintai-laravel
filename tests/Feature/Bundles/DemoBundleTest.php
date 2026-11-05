<?php

namespace Tests\Feature\Bundles;

use App\Bundles\BundleManager;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Fixtures\Bundles\DemoBundle\DemoBundleServiceProvider;
use Tests\TestCase;

/**
 * Le bundle de démonstration prouve le contrat : activé, il se branche partout ; désactivé, il n'apparaît nulle part
 * (sauf ses migrations, toujours chargées pour garder les tables).
 */
class DemoBundleTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_an_enabled_bundle_plugs_into_the_core_everywhere(): void
    {
        $this->installBundles(['demo']);
        $owner = $this->owner();

        $this->assertTrue(app(BundleManager::class)->isEnabled('demo'));
        $this->assertTrue(app(NavigationRegistry::class)->has('demo'));
        $this->assertTrue(app(PermissionRegistry::class)->has('demo.view'));
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('demo'));
        $this->assertTrue(app(DashboardWidgetRegistry::class)->has('demo.widget'));
        $this->assertTrue(app(ActivityActionRegistry::class)->has('demo.done'));

        $this->actingAs($owner);

        // Route préfixée, protégée par `auth` et par la permission du bundle, et vue au nom d'espace du slug.
        $this->get('/demo')->assertOk()->assertSee('data-test="demo-page"', false);
        $this->assertSame(url('/demo'), route('demo.index'));

        // Menu et tableau de bord.
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('demo.index'), false)
            ->assertSee('Demo widget content');

        // Formulaire de rôle : la catégorie du bundle est proposée.
        Livewire::test('pages::roles.form')->assertSee('Demo');

        // Fonctionnalités par magasin.
        $this->get(route('stores.create'))->assertOk()->assertSee('Demo feature');
    }

    public function test_an_enabled_bundle_is_announced_by_the_ping_with_a_prefix(): void
    {
        $this->installBundles(['demo']);

        $features = $this->getJson('/api/v1/ping')->assertOk()->json('features');

        $this->assertContains('bundle:demo', $features);
        $this->assertContains('demo', $features, 'Its store feature is listed like any other.');
    }

    public function test_a_disabled_bundle_appears_nowhere(): void
    {
        $this->installBundles([]);
        $owner = $this->owner();

        $this->assertFalse(app(BundleManager::class)->isEnabled('demo'));
        $this->assertFalse(app(NavigationRegistry::class)->has('demo'));
        $this->assertFalse(app(PermissionRegistry::class)->has('demo.view'));
        $this->assertArrayNotHasKey('demo', app(PermissionRegistry::class)->categories());
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('demo'));
        $this->assertFalse(app(DashboardWidgetRegistry::class)->has('demo.widget'));
        $this->assertFalse(app(ActivityActionRegistry::class)->has('demo.done'));

        $this->actingAs($owner);

        $this->get('/demo')->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Demo widget content')->assertDontSee('/demo"', false);
        Livewire::test('pages::roles.form')->assertDontSee('demo.view')->assertDontSee('Demo');
        $this->get(route('stores.create'))->assertOk()->assertDontSee('Demo feature');

        $features = $this->getJson('/api/v1/ping')->json('features');
        $this->assertNotContains('bundle:demo', $features);
        $this->assertNotContains('demo', $features);
    }

    public function test_a_bundle_that_is_installed_but_not_listed_stays_disabled_by_default(): void
    {
        $this->app->register(DemoBundleServiceProvider::class, true);

        $this->assertFalse(app(BundleManager::class)->isEnabled('demo'));
        $this->assertSame([], app(BundleManager::class)->enabledSlugs());
        $this->getJson('/api/v1/ping')->assertOk();
    }

    public function test_migrations_are_loaded_even_when_the_bundle_is_disabled(): void
    {
        $this->installBundles([]);

        $this->assertFalse(Schema::hasTable('demo_items'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('demo_items'));
    }

    public function test_disabling_keeps_the_data_and_enabling_again_finds_it(): void
    {
        $this->installBundles(['demo']);
        $this->artisan('migrate')->assertSuccessful();
        \DB::table('demo_items')->insert(['label' => 'kept', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue(app(BundleManager::class)->disable('demo'));

        $this->assertFalse(app(BundleManager::class)->isEnabled('demo'));
        $this->assertSame(1, \DB::table('demo_items')->where('label', 'kept')->count());

        $this->assertTrue(app(BundleManager::class)->enable('demo'));
        $this->assertTrue(app(BundleManager::class)->isEnabled('demo'));
        $this->assertSame(1, \DB::table('demo_items')->count());
    }

    public function test_the_store_feature_of_a_disabled_bundle_is_not_offered_to_stores(): void
    {
        $this->installBundles([]);
        $store = Store::factory()->create();

        $this->actingAs($this->owner());

        $this->get(route('stores.edit', $store))->assertOk()->assertDontSee('Demo feature');
    }
}
