<?php

namespace Tests\Feature\Bundles;

use App\Actions\Bundles\EnableBundle;
use App\Bundles\BundleManager;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Contract;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

class BundlesScreenTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
    }

    public function test_the_screen_is_reserved_to_the_owner(): void
    {
        $this->installBundles([]);

        $this->get(route('bundles.index'))->assertRedirect(route('login'));

        $this->actingAs($this->managerOf($this->store))->get(route('bundles.index'))->assertForbidden();
        $this->actingAs($this->employeeOf($this->store))->get(route('bundles.index'))->assertForbidden();

        $this->actingAs($this->owner())->get(route('bundles.index'))->assertOk();

        Livewire::actingAs($this->managerOf($this->store))->test('pages::admin.bundles')->assertForbidden();
    }

    public function test_the_menu_entry_is_in_the_system_section_for_the_owner_only(): void
    {
        $this->installBundles([]);

        $this->actingAs($this->owner())->get(route('dashboard'))->assertSee(route('bundles.index'), false);
        $this->actingAs($this->managerOf($this->store))->get(route('dashboard'))->assertDontSee(route('bundles.index'), false);
    }

    public function test_it_lists_the_installed_bundles_with_their_state(): void
    {
        $this->installBundles(['demo'], withFuture: true);

        $this->actingAs($this->owner())->get(route('bundles.index'))
            ->assertOk()
            ->assertSee('Demo bundle')
            ->assertSee('demo')
            ->assertSee('1.2.3')
            ->assertSee('Fixture bundle used by the test suite.')
            ->assertSee('^1.0')
            ->assertSee('data-test="bundle-enabled"', false)
            ->assertSee('Future bundle')
            ->assertSee('^2.0')
            ->assertSee('data-test="bundle-incompatible"', false)
            ->assertSee('Kintai contract version: '.Contract::VERSION);
    }

    public function test_with_no_bundle_installed_it_says_so(): void
    {
        $this->actingAs($this->owner())->get(route('bundles.index'))->assertOk()->assertSee('No bundle is installed.');
    }

    public function test_no_bundle_is_enabled_by_default(): void
    {
        $this->assertSame('[]', app(AppSettings::class)->string(BundleManager::SETTING));
        $this->assertSame([], app(BundleManager::class)->enabledSlugs());
    }

    public function test_the_owner_enables_then_disables_a_bundle_and_both_are_logged(): void
    {
        $this->installBundles([]);
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::admin.bundles')
            ->call('confirm', 'demo', 'enable')
            ->assertSet('targetSlug', 'demo')
            ->assertSet('targetAction', 'enable')
            ->assertSee('Enable Demo bundle?')
            ->call('applyChange')
            ->assertRedirect(route('bundles.index'));

        $this->assertSame(['demo'], app(BundleManager::class)->selected());
        $entry = ActivityEntry::query()->where('action', 'bundle.enabled')->sole();
        $this->assertSame($owner->id, $entry->actor_id);
        $this->assertSame('demo', $entry->properties['bundle']);
        $this->assertSame('1.2.3', $entry->properties['version']);

        Livewire::test('pages::admin.bundles')
            ->call('confirm', 'demo', 'disable')
            ->assertSee('None of its data is deleted')
            ->call('applyChange')
            ->assertRedirect(route('bundles.index'));

        $this->assertSame([], app(BundleManager::class)->selected());
        $this->assertSame(1, ActivityEntry::query()->where('action', 'bundle.disabled')->count());
    }

    public function test_changing_nothing_is_not_logged(): void
    {
        $this->installBundles(['demo']);
        $this->actingAs($this->owner());

        Livewire::test('pages::admin.bundles')->call('confirm', 'demo', 'enable')->call('applyChange');

        $this->assertSame(0, ActivityEntry::query()->where('action', 'bundle.enabled')->count());
    }

    public function test_an_unknown_bundle_or_action_is_ignored_by_the_screen(): void
    {
        $this->installBundles([]);
        $this->actingAs($this->owner());

        Livewire::test('pages::admin.bundles')
            ->call('confirm', 'nope', 'enable')
            ->assertSet('targetSlug', '')
            ->call('confirm', 'demo', 'delete')
            ->assertSet('targetSlug', '')
            ->call('applyChange');

        $this->assertSame([], app(BundleManager::class)->selected());
    }

    public function test_a_bundle_needing_another_major_version_of_the_contract_is_refused(): void
    {
        $this->installBundles([], withFuture: true);
        $this->actingAs($this->owner());

        Livewire::test('pages::admin.bundles')
            ->call('confirm', 'future', 'enable')
            ->call('applyChange')
            ->assertNoRedirect()
            ->assertSee('requires the Kintai contract "^2.0"')
            ->assertSee('provides version '.Contract::VERSION);

        $this->assertSame([], app(BundleManager::class)->selected());
        $this->assertSame(0, ActivityEntry::query()->where('action', 'bundle.enabled')->count());
    }

    public function test_the_action_refuses_the_incompatible_bundle_and_non_owners(): void
    {
        $this->installBundles([], withFuture: true);

        try {
            app(EnableBundle::class)->handle($this->owner(), 'future');
            $this->fail('The incompatible bundle was enabled.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('^2.0', $e->errors()['bundle'][0]);
        }

        $this->expectException(AuthorizationException::class);
        app(EnableBundle::class)->handle($this->managerOf($this->store), 'demo');
    }

    public function test_a_bundle_that_becomes_incompatible_is_not_considered_enabled_even_if_listed(): void
    {
        $this->installBundles(['future'], withFuture: true);

        $manager = app(BundleManager::class);

        $this->assertSame(['future'], $manager->selected());
        $this->assertFalse($manager->isEnabled('future'));
        $this->assertNotContains('future', $manager->enabledSlugs());
    }

    public function test_the_choice_of_a_bundle_that_is_not_installed_is_kept(): void
    {
        $this->installBundles(['demo', 'removed-bundle']);

        $manager = app(BundleManager::class);
        $manager->disable('demo');

        $this->assertSame(['removed-bundle'], $manager->selected());
        $this->assertNotContains('removed-bundle', $manager->enabledSlugs());
    }

    public function test_a_corrupted_setting_means_no_bundle(): void
    {
        app(AppSettings::class)->set(BundleManager::SETTING, '{not json');
        $this->assertSame([], app(BundleManager::class)->selected());

        app(AppSettings::class)->set(BundleManager::SETTING, '["a",1,"",null,"a","b"]');
        $this->assertSame(['a', 'b'], app(BundleManager::class)->selected());
    }
}
