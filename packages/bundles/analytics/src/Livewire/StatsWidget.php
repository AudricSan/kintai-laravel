<?php

namespace Kintai\Bundles\Analytics\Livewire;

use Illuminate\Contracts\View\View;
use Kintai\Bundles\Analytics\DashboardFigures;

/**
 * Widget « Aperçu des statistiques » : heures nettes, coût et stabilité des 30 derniers jours, par magasin.
 */
class StatsWidget extends Widget
{
    public function render(): View
    {
        $stores = $this->stores();

        return app('view')->make($this->viewName('widgets.stats'), [
            'period' => DashboardFigures::PERIOD_DAYS,
        ] + $this->figures()->summary(array_keys($stores)));
    }
}
