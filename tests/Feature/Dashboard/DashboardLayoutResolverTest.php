<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\DashboardLayoutResolver;
use App\Dashboard\DashboardPlacement;
use App\Dashboard\DashboardWidget;
use App\Dashboard\DashboardWidgetRegistry;
use App\Dashboard\WidgetSize;
use App\Models\DashboardLayout;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class DashboardLayoutResolverTest extends TestCase
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
     * @param  list<DashboardPlacement>  $placements
     * @return list<string>
     */
    private function keys(array $placements): array
    {
        return array_map(fn (DashboardPlacement $placement): string => $placement->widget->key, $placements);
    }

    private function resolve(User $user, string $audience = DashboardWidget::MANAGER): array
    {
        return $this->keys(app(DashboardLayoutResolver::class)->resolve($user, $audience));
    }

    public function test_defaults_come_from_the_registry_in_order(): void
    {
        $manager = $this->managerOf($this->store);

        $this->assertSame(['counters', 'pending-requests', 'alerts', 'shifts-today'], $this->resolve($manager));
        $this->assertSame(
            ['my-shifts-today', 'my-upcoming-shifts', 'my-month'],
            $this->resolve($this->employeeOf($this->store), DashboardWidget::EMPLOYEE),
        );
    }

    public function test_widgets_that_are_not_default_are_left_out_of_the_defaults_but_available(): void
    {
        app(DashboardWidgetRegistry::class)->register(new DashboardWidget(
            key: 'extra', label: 'Extra', audience: DashboardWidget::MANAGER, component: 'x::extra', order: 5, default: false,
        ));
        $manager = $this->managerOf($this->store);
        $resolver = app(DashboardLayoutResolver::class);

        $this->assertNotContains('extra', $this->resolve($manager));
        $this->assertContains('extra', array_map(fn (DashboardWidget $widget): string => $widget->key, $resolver->available($manager, DashboardWidget::MANAGER)));
    }

    public function test_personal_layout_beats_the_role_layout_and_the_defaults(): void
    {
        $manager = $this->managerOf($this->store);
        DashboardLayout::factory()->forRole($this->role('manager')->id)->widgets(['alerts'])->create();
        DashboardLayout::factory()->create(['user_id' => $manager->id, 'is_active' => true, 'layout' => [['widget' => 'shifts-today', 'size' => 'full'], ['widget' => 'counters']]]);

        $placements = app(DashboardLayoutResolver::class)->resolve($manager, DashboardWidget::MANAGER);

        $this->assertSame(['shifts-today', 'counters'], $this->keys($placements));
        $this->assertSame(WidgetSize::Full, $placements[0]->size);
        $this->assertSame(WidgetSize::Full, $placements[1]->size, 'counters keeps its own default size');
    }

    public function test_inactive_personal_layout_is_ignored_in_favour_of_the_role_layout(): void
    {
        $manager = $this->managerOf($this->store);
        DashboardLayout::factory()->widgets(['counters'])->create(['user_id' => $manager->id, 'is_active' => false]);
        DashboardLayout::factory()->forRole($this->role('manager')->id)->widgets(['alerts', 'counters'])->create();

        $this->assertSame(['alerts', 'counters'], $this->resolve($manager));
    }

    public function test_the_first_role_by_position_wins(): void
    {
        $extra = $this->customRole(['shifts.view', 'stores.view']);
        $user = $this->managerOf($this->store);
        $user->assignRole($extra, $this->store);

        DashboardLayout::factory()->forRole($this->role('manager')->id)->widgets(['alerts'])->create(['position' => 2]);
        DashboardLayout::factory()->forRole($extra->id)->widgets(['shifts-today'])->create(['position' => 1]);

        $this->assertSame(['shifts-today'], $this->resolve($user));
    }

    public function test_role_layout_of_another_dashboard_or_role_does_not_apply(): void
    {
        $manager = $this->managerOf($this->store);
        DashboardLayout::factory()->forRole($this->role('employee')->id)->widgets(['alerts'])->create();
        DashboardLayout::factory()->employee()->forRole($this->role('manager')->id)->widgets(['my-month'])->create();

        $this->assertSame(['counters', 'pending-requests', 'alerts', 'shifts-today'], $this->resolve($manager));
    }

    public function test_unknown_widgets_are_ignored(): void
    {
        $manager = $this->managerOf($this->store);
        DashboardLayout::factory()->create([
            'user_id' => $manager->id,
            'is_active' => true,
            'layout' => [['widget' => 'removed-bundle'], ['widget' => 'alerts'], ['widget' => 'alerts'], 'garbage', ['nothing' => 1]],
        ]);

        $this->assertSame(['alerts'], $this->resolve($manager));
    }

    public function test_widgets_of_the_other_audience_or_without_permission_are_ignored(): void
    {
        $employee = $this->employeeOf($this->store);
        DashboardLayout::factory()->employee()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'layout' => [['widget' => 'counters'], ['widget' => 'my-month']],
        ]);

        $this->assertSame(['my-month'], $this->resolve($employee, DashboardWidget::EMPLOYEE));
        // « counters » demande shifts.view : un employé sans droit ne le reçoit pas, même par la disposition de son rôle.
        DashboardLayout::factory()->forRole($this->role('employee')->id)->widgets(['counters'])->create();
        $this->assertSame([], $this->resolve($employee));
    }

    public function test_permission_filters_the_widgets(): void
    {
        $role = $this->customRole(['employees.view']);
        $user = User::factory()->create();
        $user->assignRole($role, $this->store);

        $this->assertSame(['pending-requests'], $this->resolve($user));
    }

    public function test_store_feature_must_be_enabled_on_at_least_one_visible_store(): void
    {
        $other = Store::factory()->create();
        $manager = $this->managerOf($this->store);
        $manager->assignRole($this->role('manager'), $other);

        StoreFeature::create(['store_id' => $this->store->id, 'feature' => 'shifts', 'enabled' => false]);
        $this->assertContains('alerts', $this->resolve($manager), 'the other store still has the feature');

        StoreFeature::create(['store_id' => $other->id, 'feature' => 'shifts', 'enabled' => false]);
        $this->assertNotContains('alerts', $this->resolve($manager));
        $this->assertNotContains('shifts-today', $this->resolve($manager));
        $this->assertContains('counters', $this->resolve($manager), 'widgets without feature stay');
    }

    public function test_feature_of_a_store_the_user_does_not_manage_does_not_count(): void
    {
        $other = Store::factory()->create();
        StoreFeature::create(['store_id' => $this->store->id, 'feature' => 'shifts', 'enabled' => false]);

        $this->assertNotContains('alerts', $this->resolve($this->managerOf($this->store)));
        $this->assertContains('alerts', $this->resolve($this->owner()), 'the owner sees $other');
        unset($other);
    }

    public function test_normalize_drops_unknown_duplicates_wrong_audience_and_invalid_sizes(): void
    {
        $normalized = app(DashboardLayoutResolver::class)->normalize(DashboardWidget::MANAGER, [
            ['widget' => 'counters', 'size' => 'sm'],
            ['widget' => 'counters'],
            ['widget' => 'alerts', 'size' => 'huge'],
            ['widget' => 'my-month'],
            ['widget' => 'ghost'],
            ['widget' => 'shifts-today', 'size' => 'md'],
        ]);

        $this->assertSame([
            ['widget' => 'counters', 'size' => 'sm'],
            ['widget' => 'alerts'],
            ['widget' => 'shifts-today'],
        ], $normalized);
    }

    public function test_a_non_resizable_widget_keeps_its_size(): void
    {
        app(DashboardWidgetRegistry::class)->register(new DashboardWidget(
            key: 'fixed', label: 'Fixed', audience: DashboardWidget::EMPLOYEE, component: 'x::fixed', size: WidgetSize::Small, resizable: false,
        ));
        $employee = $this->employeeOf($this->store);
        DashboardLayout::factory()->employee()->create(['user_id' => $employee->id, 'is_active' => true, 'layout' => [['widget' => 'fixed', 'size' => 'full']]]);

        $placements = app(DashboardLayoutResolver::class)->resolve($employee, DashboardWidget::EMPLOYEE);

        $this->assertSame(WidgetSize::Small, $placements[0]->size);
    }
}
