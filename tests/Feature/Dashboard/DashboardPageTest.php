<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\DashboardWidget;
use App\Dashboard\DashboardWidgetRegistry;
use App\Models\DashboardLayout;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesAccess;
use Tests\Support\ThrowingWidget;
use Tests\TestCase;

class DashboardPageTest extends TestCase
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

    public function test_manager_lands_on_the_manager_dashboard_and_can_switch(): void
    {
        $this->actingAs($this->managerOf($this->store));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="widget-counters"', false)
            ->assertSee('data-test="widget-alerts"', false)
            ->assertDontSee('data-test="widget-my-month"', false)
            ->assertSee(route('dashboard.employee'), false);
    }

    public function test_owner_lands_on_the_manager_dashboard(): void
    {
        $this->actingAs($this->owner());

        $this->get(route('dashboard'))->assertOk()->assertSee('data-test="widget-counters"', false);
    }

    public function test_employee_lands_on_the_employee_dashboard_without_switch(): void
    {
        $this->actingAs($this->employeeOf($this->store));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="widget-my-month"', false)
            ->assertDontSee('data-test="widget-counters"', false)
            ->assertDontSee('data-test="switch-dashboard"', false);
    }

    public function test_user_without_any_role_gets_the_employee_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-test="widget-counters"', false);
    }

    public function test_employee_dashboard_is_open_to_everyone_including_managers(): void
    {
        $manager = $this->managerOf($this->store);
        $this->store->members()->attach($manager);
        $this->actingAs($manager);
        $this->get(route('dashboard.employee'))->assertOk()->assertSee('data-test="widget-my-month"', false);

        // Aucune appartenance de magasin : les widgets du planning sont masqués, la page reste servie.
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard.employee'))->assertOk()->assertSee('No widget to display.');
    }

    public function test_guests_cannot_see_the_employee_dashboard(): void
    {
        $this->get(route('dashboard.employee'))->assertRedirect(route('login'));
    }

    public function test_a_widget_that_throws_is_replaced_and_reported_while_the_page_stays_up(): void
    {
        Exceptions::fake();
        Livewire::component('throwing-widget', ThrowingWidget::class);
        app(DashboardWidgetRegistry::class)->register(new DashboardWidget(
            key: 'boom', label: 'Boom', audience: DashboardWidget::EMPLOYEE, component: 'throwing-widget', order: 1,
        ));
        $employee = $this->employeeOf($this->store);
        DashboardLayout::factory()->employee()->widgets(['boom', 'my-month'])->create(['user_id' => $employee->id, 'is_active' => true]);
        $this->actingAs($employee);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="widget-unavailable"', false)
            ->assertSee('This widget is unavailable.')
            ->assertDontSee('widget exploded')
            ->assertSee('data-test="widget-my-month"', false);

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'widget exploded');
    }

    public function test_the_frame_survives_a_throwing_widget_on_refresh(): void
    {
        Exceptions::fake();
        Livewire::component('throwing-widget', ThrowingWidget::class);
        app(DashboardWidgetRegistry::class)->register(new DashboardWidget(
            key: 'boom', label: 'Boom', audience: DashboardWidget::EMPLOYEE, component: 'throwing-widget', refresh: 30,
        ));
        $this->actingAs($this->employeeOf($this->store));

        Livewire::test('widgets::frame', ['widget' => 'boom'])
            ->assertSee('This widget is unavailable.')
            ->assertSeeHtml('wire:poll.30s="refresh"')
            ->call('refresh')
            ->assertSet('tick', 1)
            ->assertSee('This widget is unavailable.');
    }

    public function test_the_frame_shows_a_healthy_widget_and_polls_at_its_interval(): void
    {
        $this->actingAs($this->owner());

        Livewire::test('widgets::frame', ['widget' => 'counters'])
            ->assertSee('Key figures')
            ->assertSeeHtml('wire:poll.60s="refresh"');
    }

    public function test_the_frame_ignores_an_unknown_widget_key(): void
    {
        $this->actingAs($this->owner());

        Livewire::test('widgets::frame', ['widget' => 'gone'])->assertSee('This widget is unavailable.');
    }
}
