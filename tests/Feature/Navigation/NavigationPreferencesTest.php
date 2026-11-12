<?php

namespace Tests\Feature\Navigation;

use App\Actions\Navigation\SaveNavigationPreferences;
use App\Models\Store;
use App\Models\UserNavPref;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class NavigationPreferencesTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
    }

    public function test_hidden_entries_and_bottom_bar_are_saved(): void
    {
        $manager = $this->managerOf($this->store);

        app(SaveNavigationPreferences::class)->handle($manager, ['employees', 'stores'], ['dashboard', 'shifts', 'calendar']);

        $pref = UserNavPref::where('user_id', $manager->id)->firstOrFail();
        $this->assertSame(['employees', 'stores'], $pref->hidden_items);
        $this->assertSame(['dashboard', 'shifts', 'calendar'], $pref->bottom_nav_items);
    }

    public function test_unknown_unauthorized_and_non_hideable_keys_are_ignored(): void
    {
        $manager = $this->managerOf($this->store);

        app(SaveNavigationPreferences::class)->handle(
            $manager,
            ['employees', 'unknown', 'roles', 'settings', 'employees', 42],
            ['dashboard', 'roles', 'unknown', 'shifts'],
        );

        $pref = UserNavPref::where('user_id', $manager->id)->firstOrFail();
        $this->assertSame(['employees'], $pref->hidden_items);
        $this->assertSame(['dashboard', 'shifts'], $pref->bottom_nav_items);
    }

    public function test_bottom_bar_needs_between_two_and_four_entries(): void
    {
        $manager = $this->managerOf($this->store);

        foreach ([['dashboard'], ['dashboard', 'shifts', 'calendar', 'timeline', 'conflicts']] as $bottom) {
            try {
                app(SaveNavigationPreferences::class)->handle($manager, [], $bottom);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('bottom', $e->errors());
            }
        }

        $this->assertDatabaseCount('user_nav_prefs', 0);
    }

    public function test_an_empty_bar_restores_the_default(): void
    {
        $manager = $this->managerOf($this->store);
        UserNavPref::factory()->create(['user_id' => $manager->id, 'bottom_nav_items' => ['dashboard', 'shifts']]);

        app(SaveNavigationPreferences::class)->handle($manager, [], []);

        $this->assertNull(UserNavPref::where('user_id', $manager->id)->firstOrFail()->bottom_nav_items);
    }

    public function test_saving_twice_keeps_a_single_row(): void
    {
        $manager = $this->managerOf($this->store);

        app(SaveNavigationPreferences::class)->handle($manager, ['employees'], []);
        app(SaveNavigationPreferences::class)->handle($manager, ['stores'], []);

        $this->assertDatabaseCount('user_nav_prefs', 1);
        $this->assertSame(['stores'], UserNavPref::firstOrFail()->hidden_items);
    }

    public function test_preferences_page_saves_the_choices(): void
    {
        $manager = $this->managerOf($this->store);
        $this->actingAs($manager);

        Livewire::test('pages::settings.navigation')
            ->assertSet('bottom', ['dashboard', 'shifts', 'my-shifts'])
            ->set('hidden', ['stores'])
            ->set('bottom', ['dashboard', 'employees'])
            ->call('save')
            ->assertHasNoErrors();

        $pref = UserNavPref::where('user_id', $manager->id)->firstOrFail();
        $this->assertSame(['stores'], $pref->hidden_items);
        $this->assertSame(['dashboard', 'employees'], $pref->bottom_nav_items);
    }

    public function test_preferences_page_reports_a_wrong_bar_size(): void
    {
        $this->actingAs($this->managerOf($this->store));

        Livewire::test('pages::settings.navigation')
            ->set('bottom', ['dashboard'])
            ->call('save')
            ->assertHasErrors('bottom');
    }

    public function test_preferences_page_can_restore_the_default_bar(): void
    {
        $manager = $this->managerOf($this->store);
        UserNavPref::factory()->create(['user_id' => $manager->id, 'hidden_items' => ['stores'], 'bottom_nav_items' => ['employees', 'stores']]);
        $this->actingAs($manager);

        Livewire::test('pages::settings.navigation')->call('useDefaultBar');

        $pref = UserNavPref::where('user_id', $manager->id)->firstOrFail();
        $this->assertNull($pref->bottom_nav_items);
        $this->assertSame(['stores'], $pref->hidden_items);
    }
}
