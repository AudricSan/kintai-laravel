<?php

namespace Kintai\Contracts\Dashboard;

/**
 * Point d'extension : le Core et les bundles y déclarent les widgets des tableaux de bord, dans le `boot()` de leur
 * ServiceProvider :
 *
 *     $this->app->make(DashboardWidgetRegistry::class)->register(new DashboardWidget(
 *         key: 'analytics.summary', label: 'Statistics summary', audience: DashboardWidget::MANAGER,
 *         component: 'analytics::summary', permission: 'analytics.view', storeFeature: 'analytics',
 *         size: WidgetSize::Large, refresh: 300, order: 40,
 *     ));
 *
 * `component` est le nom d'un composant Livewire (par exemple d'un espace de noms ajouté par le bundle avec
 * `Livewire::addNamespace`). Il est rendu dans une enveloppe qui isole ses erreurs : une exception retire le widget de
 * la page, elle est journalisée, et les autres widgets restent affichés. La clé est unique (un doublon est refusé).
 * Un widget dont le bundle est retiré est ignoré à l'affichage des dispositions enregistrées.
 */
interface DashboardWidgetRegistry
{
    /**
     * @throws \InvalidArgumentException Clé déjà enregistrée ou audience inconnue.
     */
    public function register(DashboardWidget $widget): void;

    public function has(string $key): bool;

    public function get(string $key): ?DashboardWidget;

    /**
     * Widgets d'une audience, triés par ordre puis par enregistrement.
     *
     * @return list<DashboardWidget>
     */
    public function forAudience(string $audience): array;
}
