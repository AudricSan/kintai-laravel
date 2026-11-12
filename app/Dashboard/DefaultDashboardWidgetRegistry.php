<?php

namespace App\Dashboard;

use InvalidArgumentException;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;

/**
 * Registre en mémoire (voir l'interface du contrat pour le mode d'emploi).
 */
class DefaultDashboardWidgetRegistry implements DashboardWidgetRegistry
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
