<?php

namespace Tests\Fixtures\Bundles\DemoBundle;

use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Livewire\Livewire;

/**
 * Bundle de démonstration, chargé uniquement par les tests (jamais découvert par Composer) : il prouve qu'un bundle
 * activé se branche partout (menu, widget, droits, fonctionnalité de magasin, routes, vues, migrations) et qu'un bundle
 * désactivé n'apparaît nulle part.
 */
class DemoBundleServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'demo';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Demo bundle';
    }

    public function version(): string
    {
        return '1.2.3';
    }

    public function description(): string
    {
        return 'Fixture bundle used by the test suite.';
    }

    public function basePath(string $path = ''): string
    {
        return $path === '' ? __DIR__ : __DIR__.DIRECTORY_SEPARATOR.$path;
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('demo-widget', DemoWidget::class);
        }

        $this->permissions('demo', ['view', 'manage'], ['manager' => ['view', 'manage'], 'employee' => ['view']]);
        $this->storeFeature('demo', 'Demo feature');
        $this->navigation(new NavigationItem(
            key: 'demo', label: 'Demo', route: 'demo.index', icon: 'beaker',
            section: NavigationRegistry::SECTION_REPORTS, order: 90, permission: 'demo.view',
        ));
        $this->widgets(new DashboardWidget(
            key: 'demo.widget', label: 'Demo widget', audience: DashboardWidget::MANAGER, component: 'demo-widget',
            size: WidgetSize::Small, order: 90,
        ));
        $this->activityAction('demo.done', 'Demo done', 'Demo');
    }
}
