<?php

namespace Tests\Feature\Navigation;

use App\Models\Store;
use App\Models\User;
use App\Models\UserNavPref;
use App\Navigation\NavigationItem;
use App\Navigation\NavigationMenu;
use App\Navigation\NavigationRegistry;
use App\Requests\PendingRequestProvider;
use App\Requests\PendingRequestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class NavigationMenuTest extends TestCase
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

    /**
     * @return list<string>
     */
    private function keys(User $user): array
    {
        $keys = [];
        foreach (app(NavigationMenu::class)->sections($user) as $section) {
            foreach ($section['items'] as $item) {
                $keys[] = $item->key;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function sectionKeys(User $user): array
    {
        return array_column(app(NavigationMenu::class)->sections($user), 'key');
    }

    public function test_employee_sees_only_planning_and_account_entries(): void
    {
        $employee = $this->employeeOf($this->store);

        $this->assertSame(['dashboard', 'my-shifts', 'settings'], $this->keys($employee));
        $this->assertSame(['main', 'planning', 'account'], $this->sectionKeys($employee));
    }

    public function test_manager_sees_planning_and_hr_but_not_system(): void
    {
        $manager = $this->managerOf($this->store);

        $this->assertSame(
            ['dashboard', 'shifts', 'calendar', 'timeline', 'conflicts', 'shift-types', 'my-shifts', 'employees', 'stores', 'settings'],
            $this->keys($manager),
        );
        $this->assertNotContains('system', $this->sectionKeys($manager));
    }

    public function test_owner_also_sees_the_system_section(): void
    {
        $owner = $this->owner();

        $this->assertContains('roles', $this->keys($owner));
        $this->assertContains('system', $this->sectionKeys($owner));
    }

    public function test_a_section_without_visible_entry_is_not_listed(): void
    {
        $registry = app(NavigationRegistry::class);
        $registry->register(new NavigationItem('secret', 'Secret', 'roles.index', 'lock-closed', NavigationRegistry::SECTION_REPORTS, 10, 'manage-roles'));
        $registry->register(new NavigationItem('never', 'Never', 'roles.index', 'lock-closed', NavigationRegistry::SECTION_REPORTS, 20, visible: fn (User $user): bool => false));

        $this->assertNotContains('reports', $this->sectionKeys($this->managerOf($this->store)));
        $this->assertContains('reports', $this->sectionKeys($this->owner()));
    }

    public function test_requests_entry_needs_at_least_one_provider(): void
    {
        $employee = $this->employeeOf($this->store);

        $this->assertNotContains('requests', $this->keys($employee));

        app(PendingRequestRegistry::class)->register(new PendingRequestProvider('timeoff', 'Time off', 'requests.index', fn (User $user): int => 0));

        $this->assertContains('requests', $this->keys($employee));
        $this->assertContains('requests', $this->sectionKeys($employee));
    }

    public function test_bundle_entries_follow_section_then_order(): void
    {
        app(NavigationRegistry::class)->register(new NavigationItem('early', 'Early', 'shifts.index', 'bolt', NavigationRegistry::SECTION_PLANNING, 5));

        $this->assertSame(['dashboard', 'early', 'shifts'], array_slice($this->keys($this->managerOf($this->store)), 0, 3));
    }

    public function test_sidebar_is_rendered_from_the_registry(): void
    {
        $this->actingAs($this->employeeOf($this->store));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="nav-item-my-shifts"', false)
            ->assertDontSee('data-test="nav-item-employees"', false)
            ->assertDontSee('data-test="nav-section-hr"', false)
            ->assertDontSee('Platform');
    }

    public function test_sidebar_hides_entries_the_user_masked(): void
    {
        $employee = $this->employeeOf($this->store);
        UserNavPref::factory()->create(['user_id' => $employee->id, 'hidden_items' => ['my-shifts']]);
        $this->actingAs($employee);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-test="nav-item-my-shifts"', false)
            ->assertSee('data-test="nav-item-dashboard"', false);
    }

    public function test_bottom_bar_is_rendered_for_mobile_only(): void
    {
        $this->actingAs($this->employeeOf($this->store));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="bottom-nav"', false)
            ->assertSee('lg:hidden', false)
            ->assertSee('data-test="bottom-nav-dashboard"', false)
            ->assertSee('data-test="bottom-nav-my-shifts"', false);
    }

    public function test_default_bottom_bar_uses_the_first_default_entries_the_user_can_see(): void
    {
        $menu = app(NavigationMenu::class);

        $employeeBar = array_map(fn (NavigationItem $item): string => $item->key, $menu->bottomBar($this->employeeOf($this->store)));
        $managerBar = array_map(fn (NavigationItem $item): string => $item->key, $menu->bottomBar($this->managerOf($this->store)));

        $this->assertSame(['dashboard', 'my-shifts'], $employeeBar);
        $this->assertSame(['dashboard', 'shifts', 'my-shifts'], $managerBar);
    }

    public function test_chosen_bottom_bar_is_used_and_filtered_by_what_is_visible(): void
    {
        $manager = $this->managerOf($this->store);
        UserNavPref::factory()->create(['user_id' => $manager->id, 'bottom_nav_items' => ['employees', 'stores', 'roles', 'nope']]);

        $bar = array_map(fn (NavigationItem $item): string => $item->key, app(NavigationMenu::class)->bottomBar($manager));

        $this->assertSame(['employees', 'stores'], $bar);
    }

    public function test_a_saved_bar_falling_below_two_entries_reverts_to_defaults(): void
    {
        $manager = $this->managerOf($this->store);
        UserNavPref::factory()->create(['user_id' => $manager->id, 'bottom_nav_items' => ['employees', 'roles']]);

        $bar = array_map(fn (NavigationItem $item): string => $item->key, app(NavigationMenu::class)->bottomBar($manager));

        $this->assertSame(['dashboard', 'shifts', 'my-shifts'], $bar);
    }

    public function test_hideable_entries_exclude_the_owner_zone_and_settings(): void
    {
        $keys = array_map(fn (NavigationItem $item): string => $item->key, app(NavigationMenu::class)->hideable($this->owner()));

        $this->assertNotContains('roles', $keys);
        $this->assertNotContains('settings', $keys);
        $this->assertContains('employees', $keys);
    }

    public function test_a_non_hideable_entry_stays_even_if_listed_as_hidden(): void
    {
        $owner = $this->owner();
        UserNavPref::factory()->create(['user_id' => $owner->id, 'hidden_items' => ['roles', 'settings', 'employees']]);

        $keys = $this->keys($owner);

        $this->assertContains('roles', $keys);
        $this->assertContains('settings', $keys);
        $this->assertNotContains('employees', $keys);
    }

    public function test_navigation_page_is_in_the_profile_sections(): void
    {
        $this->actingAs($this->employeeOf($this->store));

        $this->get(route('navigation.edit'))->assertOk()->assertSee('Navigation settings');
        $this->get(route('profile.edit'))->assertOk()->assertSee(route('navigation.edit'), false);
    }
}
