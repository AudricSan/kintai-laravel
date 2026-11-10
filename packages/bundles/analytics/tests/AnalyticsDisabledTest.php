<?php

namespace Kintai\Bundles\Analytics\Tests;

use App\Dashboard\DashboardLayoutResolver;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;
use Kintai\Contracts\Employees\EmployeeActionRegistry;
use Kintai\Contracts\Exports\ExportDatasetRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

/**
 * Bundle désactivé : aucune page, aucun widget, aucune entrée de menu, aucun jeu de données d'export, aucune action sur la
 * liste des employés (le Core ne dépend pas du bundle).
 */
class AnalyticsDisabledTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->seedRoles();
        $store = Store::factory()->create();
        $owner = $this->owner();

        foreach (['/analytics', '/analytics/employees', '/analytics/employee?user='.$owner->id, '/analytics/profitability'] as $page) {
            $this->actingAs($owner)->get($page)->assertNotFound();
        }

        foreach (['analytics.stats', 'analytics.finance', 'analytics.absence'] as $key) {
            $this->assertFalse(app(DashboardWidgetRegistry::class)->has($key));
        }
        $this->assertSame([], array_filter(
            app(DashboardLayoutResolver::class)->available($owner, DashboardWidget::MANAGER),
            fn (DashboardWidget $widget): bool => str_starts_with($widget->key, 'analytics.'),
        ));

        $this->assertFalse(app(NavigationRegistry::class)->has('analytics'));
        $this->assertFalse(app(NavigationRegistry::class)->has('analytics-employees'));
        $this->assertFalse(app(NavigationRegistry::class)->has('analytics-profitability'));
        $this->assertNull(app(ExportDatasetRegistry::class)->find('store-stats'));
        $this->assertSame([], app(EmployeeActionRegistry::class)->actionsFor($owner, $owner->id));
        $this->assertNotNull($store);
    }
}
