<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Dashboard\ActivateDashboardLayout;
use App\Actions\Dashboard\ClearRoleDashboardLayout;
use App\Actions\Dashboard\DeleteDashboardLayout;
use App\Actions\Dashboard\SaveDashboardLayout;
use App\Actions\Dashboard\SaveRoleDashboardLayout;
use App\Dashboard\DashboardLayoutResolver;
use App\Dashboard\DashboardPlacement;
use App\Models\DashboardLayout;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Requests\PendingRequestProvider;
use Kintai\Contracts\Requests\PendingRequestRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class DashboardCustomizationTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        // Un fournisseur de demandes rend le widget « pending-requests » disponible.
        app(PendingRequestRegistry::class)->register(new PendingRequestProvider('fake', 'Fake', 'requests.index', fn (User $user): int => 1));
        $this->store = Store::factory()->create();
        $this->manager = $this->managerOf($this->store);
    }

    /**
     * @return list<string>
     */
    private function displayed(User $user, string $audience = DashboardWidget::MANAGER): array
    {
        return array_map(
            fn (DashboardPlacement $placement): string => $placement->widget->key,
            app(DashboardLayoutResolver::class)->resolve($user, $audience),
        );
    }

    public function test_customizing_starts_from_the_current_layout(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('pages::dashboard.show')
            ->assertSet('editing', false)
            ->call('startEditing')
            ->assertSet('editing', true)
            ->assertSet('draft', [
                ['widget' => 'counters', 'size' => 'full'],
                ['widget' => 'pending-requests', 'size' => 'sm'],
                ['widget' => 'alerts', 'size' => 'md'],
                ['widget' => 'shifts-today', 'size' => 'md'],
            ]);
    }

    public function test_remove_reorder_resize_and_save_under_a_name(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->call('removeWidget', 1)
            ->call('moveDown', 0)
            ->call('moveUp', 2)
            ->set('draft.0.size', 'full')
            ->set('name', 'Morning')
            ->call('saveLayout')
            ->assertHasNoErrors()
            ->assertSet('editing', false);

        $layout = DashboardLayout::where('user_id', $this->manager->id)->firstOrFail();
        $this->assertSame('Morning', $layout->name);
        $this->assertTrue($layout->is_active);
        $this->assertSame('manager', $layout->dashboard);
        $this->assertSame([
            ['widget' => 'alerts', 'size' => 'full'],
            ['widget' => 'shifts-today'],
            ['widget' => 'counters'],
        ], $layout->layout);
        $this->assertSame(['alerts', 'shifts-today', 'counters'], $this->displayed($this->manager));
    }

    public function test_moves_at_the_edges_do_nothing(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test('pages::dashboard.show')->call('startEditing');
        $before = $component->get('draft');

        $component->call('moveUp', 0)->call('moveDown', 3)->call('moveDown', 99);

        $this->assertSame($before, $component->get('draft'));
    }

    public function test_adding_a_widget_only_accepts_available_ones_once(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->call('removeWidget', 0)
            ->call('addWidget', 'counters')
            ->call('addWidget', 'counters')
            ->call('addWidget', 'my-month')
            ->call('addWidget', 'ghost');

        $this->assertSame(['pending-requests', 'alerts', 'shifts-today', 'counters'], array_column($component->get('draft'), 'widget'));
    }

    public function test_widgets_without_permission_cannot_be_added(): void
    {
        $this->actingAs($this->employeeOf($this->store));

        $component = Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->call('addWidget', 'counters');

        $this->assertNotContains('counters', array_column($component->get('draft'), 'widget'));
    }

    public function test_a_layout_needs_a_name_and_at_least_one_widget(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->set('name', '   ')
            ->call('saveLayout')
            ->assertHasErrors('name');

        Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->set('draft', [])
            ->set('name', 'Empty')
            ->call('saveLayout')
            ->assertHasErrors('layout');

        $this->assertDatabaseCount('dashboard_layouts', 0);
    }

    public function test_several_named_layouts_with_a_single_active_one(): void
    {
        $this->actingAs($this->manager);

        foreach (['One', 'Two'] as $name) {
            Livewire::test('pages::dashboard.show')->call('startEditing')->set('name', $name)->call('saveLayout');
        }

        $this->assertSame(['Two'], DashboardLayout::where('is_active', true)->pluck('name')->all());
        $this->assertSame(2, DashboardLayout::where('user_id', $this->manager->id)->count());

        $one = DashboardLayout::where('name', 'One')->firstOrFail();
        Livewire::test('pages::dashboard.show')->call('activateLayout', $one->id);

        $this->assertSame(['One'], DashboardLayout::where('is_active', true)->pluck('name')->all());
    }

    public function test_editing_an_existing_layout_updates_it(): void
    {
        $this->actingAs($this->manager);
        $layout = DashboardLayout::factory()->widgets(['alerts', 'counters'])->create(['user_id' => $this->manager->id, 'name' => 'Old']);

        Livewire::test('pages::dashboard.show')
            ->call('startEditing', $layout->id)
            ->assertSet('name', 'Old')
            ->assertSet('editingId', $layout->id)
            ->call('removeWidget', 0)
            ->set('name', 'New')
            ->call('saveLayout');

        $this->assertDatabaseCount('dashboard_layouts', 1);
        $layout->refresh();
        $this->assertSame('New', $layout->name);
        $this->assertSame([['widget' => 'counters']], $layout->layout);
        $this->assertTrue($layout->is_active);
    }

    public function test_deleting_a_layout_goes_through_a_confirmation(): void
    {
        $this->actingAs($this->manager);
        $layout = DashboardLayout::factory()->widgets(['alerts'])->active()->create(['user_id' => $this->manager->id]);

        Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->call('confirmDelete', $layout->id)
            ->assertSet('deleteId', $layout->id);
        $this->assertDatabaseHas('dashboard_layouts', ['id' => $layout->id]);

        Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->set('deleteId', $layout->id)
            ->call('deleteLayout')
            ->assertSet('deleteId', null);

        $this->assertDatabaseMissing('dashboard_layouts', ['id' => $layout->id]);
        $this->assertSame(['counters', 'pending-requests', 'alerts', 'shifts-today'], $this->displayed($this->manager));
    }

    public function test_back_to_defaults_keeps_the_saved_layouts_but_deactivates_them(): void
    {
        $this->actingAs($this->manager);
        $layout = DashboardLayout::factory()->widgets(['alerts'])->active()->create(['user_id' => $this->manager->id]);

        Livewire::test('pages::dashboard.show')->call('startEditing')->call('useDefaults');

        $this->assertFalse($layout->refresh()->is_active);
        $this->assertSame(['counters', 'pending-requests', 'alerts', 'shifts-today'], $this->displayed($this->manager));
    }

    public function test_back_to_defaults_falls_back_on_the_role_layout(): void
    {
        $this->actingAs($this->manager);
        DashboardLayout::factory()->forRole($this->role('manager')->id)->widgets(['shifts-today'])->create();
        DashboardLayout::factory()->widgets(['alerts'])->active()->create(['user_id' => $this->manager->id]);

        Livewire::test('pages::dashboard.show')->call('useDefaults');

        $this->assertSame(['shifts-today'], $this->displayed($this->manager));
    }

    public function test_the_manager_and_employee_layouts_are_independent(): void
    {
        $this->store->members()->attach($this->manager);
        $this->actingAs($this->manager);

        Livewire::test('pages::dashboard.show', ['audience' => 'employee'])
            ->assertSet('dashboard', 'employee')
            ->call('startEditing')
            ->call('removeWidget', 0)
            ->set('name', 'Mine')
            ->call('saveLayout');

        $this->assertSame('employee', DashboardLayout::firstOrFail()->dashboard);
        $this->assertSame(['counters', 'pending-requests', 'alerts', 'shifts-today'], $this->displayed($this->manager));
    }

    public function test_nobody_touches_the_layouts_of_someone_else(): void
    {
        $other = User::factory()->create();
        $theirs = DashboardLayout::factory()->widgets(['alerts'])->create(['user_id' => $other->id]);
        $this->actingAs($this->manager);

        foreach (['startEditing', 'activateLayout', 'confirmDelete'] as $method) {
            Livewire::test('pages::dashboard.show')->call($method, $theirs->id)->assertNotFound();
        }

        $this->assertThrows(fn () => app(ActivateDashboardLayout::class)->handle($this->manager, $theirs), AuthorizationException::class);
        $this->assertThrows(fn () => app(DeleteDashboardLayout::class)->handle($this->manager, $theirs), AuthorizationException::class);
        $this->assertThrows(fn () => app(SaveDashboardLayout::class)->handle($this->manager, 'manager', 'x', [['widget' => 'alerts']], $theirs), AuthorizationException::class);
        $this->assertDatabaseHas('dashboard_layouts', ['id' => $theirs->id, 'is_active' => false]);
    }

    public function test_role_layouts_are_reserved_to_the_owner(): void
    {
        $this->actingAs($this->manager);
        $role = $this->role('manager');

        $this->assertThrows(
            fn () => app(SaveRoleDashboardLayout::class)->handle($this->manager, $role, 'manager', [['widget' => 'alerts']]),
            AuthorizationException::class,
        );
        $this->assertThrows(
            fn () => app(ClearRoleDashboardLayout::class)->handle($this->manager, $role, 'manager'),
            AuthorizationException::class,
        );

        $component = Livewire::test('pages::dashboard.show')->call('startEditing')->assertDontSee('data-test="role-layouts"', false);
        $component->set('roleId', (string) $role->id)->call('saveRoleLayout')->assertForbidden();

        $this->assertDatabaseCount('dashboard_layouts', 0);
    }

    public function test_owner_sets_and_clears_the_layout_of_a_role(): void
    {
        $owner = $this->owner();
        $role = $this->role('manager');
        $this->actingAs($owner);

        Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->assertSee('data-test="role-layouts"', false)
            ->call('removeWidget', 0)
            ->call('removeWidget', 0)
            ->set('roleId', (string) $role->id)
            ->call('saveRoleLayout')
            ->assertHasNoErrors();

        $layout = DashboardLayout::where('role_id', $role->id)->firstOrFail();
        $this->assertNull($layout->user_id);
        $this->assertTrue($layout->is_active);
        $this->assertSame([['widget' => 'alerts'], ['widget' => 'shifts-today']], $layout->layout);
        $this->assertSame(['alerts', 'shifts-today'], $this->displayed($this->manager));

        // Une seule disposition par rôle et par tableau : on la remplace.
        Livewire::test('pages::dashboard.show')
            ->call('startEditing')
            ->call('removeWidget', 0)
            ->set('roleId', (string) $role->id)
            ->call('saveRoleLayout');
        $this->assertSame(1, DashboardLayout::where('role_id', $role->id)->count());

        Livewire::test('pages::dashboard.show')->set('roleId', (string) $role->id)->call('clearRoleLayout');

        $this->assertDatabaseCount('dashboard_layouts', 0);
        $this->assertSame(['counters', 'pending-requests', 'alerts', 'shifts-today'], $this->displayed($this->manager));
    }

    public function test_a_role_layout_cannot_be_empty(): void
    {
        $this->assertThrows(
            fn () => app(SaveRoleDashboardLayout::class)->handle($this->owner(), $this->role('manager'), 'manager', [['widget' => 'unknown']]),
            ValidationException::class,
        );
    }

    public function test_personal_layout_overrides_the_role_layout_on_the_page(): void
    {
        $role = $this->role('manager');
        DashboardLayout::factory()->forRole($role->id)->widgets(['shifts-today'])->create();
        $this->actingAs($this->manager);

        $this->get(route('dashboard'))->assertSee('data-test="widget-shifts-today"', false)->assertDontSee('data-test="widget-counters"', false);

        DashboardLayout::factory()->widgets(['counters'])->active()->create(['user_id' => $this->manager->id]);

        $this->get(route('dashboard'))->assertSee('data-test="widget-counters"', false)->assertDontSee('data-test="widget-shifts-today"', false);
    }
}
