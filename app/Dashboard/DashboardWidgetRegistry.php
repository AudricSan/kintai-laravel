<?php

namespace App\Dashboard;

use InvalidArgumentException;

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
class DashboardWidgetRegistry
{
    /** @var array<string, DashboardWidget> */
    private array $widgets = [];

    public function register(DashboardWidget $widget): void
    {
        if (isset($this->widgets[$widget->key])) {
            throw new InvalidArgumentException("Dashboard widget [{$widget->key}] is already registered.");
        }

        if (! in_array($widget->audience, [DashboardWidget::MANAGER, DashboardWidget::EMPLOYEE], true)) {
            throw new InvalidArgumentException("Dashboard audience [{$widget->audience}] is unknown.");
        }

        $this->widgets[$widget->key] = $widget;
    }

    public function has(string $key): bool
    {
        return isset($this->widgets[$key]);
    }

    public function get(string $key): ?DashboardWidget
    {
        return $this->widgets[$key] ?? null;
    }

    /**
     * Widgets d'une audience, triés par ordre puis par enregistrement.
     *
     * @return list<DashboardWidget>
     */
    public function forAudience(string $audience): array
    {
        $widgets = array_values(array_filter(
            $this->widgets,
            fn (DashboardWidget $widget): bool => $widget->audience === $audience,
        ));

        // usort est stable depuis PHP 8 : à ordre égal, l'ordre d'enregistrement est conservé.
        usort($widgets, fn (DashboardWidget $a, DashboardWidget $b): int => $a->order <=> $b->order);

        return $widgets;
    }
}
