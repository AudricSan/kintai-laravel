<?php

namespace Tests\Unit\Dashboard;

use App\Dashboard\DefaultDashboardWidgetRegistry;
use InvalidArgumentException;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use PHPUnit\Framework\TestCase;

class DashboardWidgetRegistryTest extends TestCase
{
    private function widget(string $key, string $audience = DashboardWidget::MANAGER, int $order = 100): DashboardWidget
    {
        return new DashboardWidget(key: $key, label: $key, audience: $audience, component: 'c::'.$key, order: $order);
    }

    public function test_duplicate_keys_are_refused(): void
    {
        $registry = new DefaultDashboardWidgetRegistry;
        $registry->register($this->widget('a'));

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->widget('a', DashboardWidget::EMPLOYEE));
    }

    public function test_unknown_audience_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DefaultDashboardWidgetRegistry)->register($this->widget('a', 'admin'));
    }

    public function test_widgets_are_filtered_by_audience_and_sorted_by_order_then_registration(): void
    {
        $registry = new DefaultDashboardWidgetRegistry;
        $registry->register($this->widget('c', order: 30));
        $registry->register($this->widget('a', order: 10));
        $registry->register($this->widget('mine', DashboardWidget::EMPLOYEE, 5));
        $registry->register($this->widget('b1'));
        $registry->register($this->widget('b2'));

        $keys = fn (string $audience): array => array_map(fn (DashboardWidget $widget): string => $widget->key, $registry->forAudience($audience));

        $this->assertSame(['a', 'c', 'b1', 'b2'], $keys(DashboardWidget::MANAGER));
        $this->assertSame(['mine'], $keys(DashboardWidget::EMPLOYEE));
    }

    public function test_widget_defaults(): void
    {
        $widget = $this->widget('a');

        $this->assertNull($widget->permission);
        $this->assertNull($widget->storeFeature);
        $this->assertNull($widget->refresh);
        $this->assertNull($widget->when);
        $this->assertTrue($widget->default);
        $this->assertTrue($widget->resizable);
        $this->assertSame(WidgetSize::Medium, $widget->size);
    }

    public function test_sizes_map_to_grid_columns(): void
    {
        $this->assertStringContainsString('lg:col-span-3', WidgetSize::Small->cssClass());
        $this->assertStringContainsString('lg:col-span-6', WidgetSize::Medium->cssClass());
        $this->assertStringContainsString('lg:col-span-9', WidgetSize::Large->cssClass());
        $this->assertSame('col-span-12', WidgetSize::Full->cssClass());
    }
}
