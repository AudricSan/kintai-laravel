<?php

namespace Kintai\Bundles\Notebook;

use Kintai\Bundles\Notebook\Livewire\EmployeeWidget;
use Kintai\Bundles\Notebook\Livewire\Index;
use Kintai\Bundles\Notebook\Livewire\ManagerWidget;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Livewire\Livewire;

/**
 * Carnet de notes d'équipe : messages d'un magasin ou de toute l'organisation, épinglés, avec expiration, repris sur les
 * tableaux de bord. Droits `notebook.view`, `notebook.create` et `notebook.manage` (voir `NotebookAccess`).
 */
class NotebookServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'notebook';

    public const FEATURE = 'notebook';

    public const NOTIFICATION = 'notebook_entry_created';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Notebook';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function description(): string
    {
        return 'Team notebook: notes for a store or the whole organization, pinning and expiry, shown on the dashboards.';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('notebook.index', Index::class);
            Livewire::component('notebook.manager-widget', ManagerWidget::class);
            Livewire::component('notebook.employee-widget', EmployeeWidget::class);
        }

        // Par défaut (première activation) : les gérants gèrent le carnet, les employés le lisent.
        $this->permissions('notebook', ['view', 'create', 'manage'], ['manager' => ['view', 'create', 'manage'], 'employee' => ['view']]);
        $this->storeFeature(self::FEATURE, 'Notebook');
        $this->navigation(new NavigationItem(
            key: 'notebook', label: 'Notebook', route: 'notebook.index', icon: 'book-open',
            section: NavigationRegistry::SECTION_MAIN, order: 28, permission: 'notebook.view', current: ['notebook.index'],
        ));
        $this->widgets(
            new DashboardWidget(
                key: 'notebook.manager', label: 'Team notes', audience: DashboardWidget::MANAGER, component: 'notebook.manager-widget',
                permission: 'notebook.view', storeFeature: self::FEATURE, size: WidgetSize::Medium, order: 40,
            ),
            new DashboardWidget(
                key: 'notebook.employee', label: 'Notes from my stores', audience: DashboardWidget::EMPLOYEE, component: 'notebook.employee-widget',
                permission: 'notebook.view', storeFeature: self::FEATURE, size: WidgetSize::Medium, order: 40,
            ),
        );
        $this->notificationTypes(new NotificationType(
            self::NOTIFICATION, 'New note', 'messages', 'book-open', push: true, referenceType: 'notebook_entry',
        ));
        $this->activityAction('notebook.created', 'Note published', 'Notebook');
        $this->activityAction('notebook.updated', 'Note updated', 'Notebook');
        $this->activityAction('notebook.deleted', 'Note deleted', 'Notebook');
        $this->activityAction('notebook.pinned', 'Note pinned or unpinned', 'Notebook');
    }
}
