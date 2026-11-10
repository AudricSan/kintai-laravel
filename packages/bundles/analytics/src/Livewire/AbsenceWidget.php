<?php

namespace Kintai\Bundles\Analytics\Livewire;

use Illuminate\Contracts\View\View;
use Kintai\Bundles\Analytics\DashboardFigures;

/**
 * Widget « RH et absentéisme » : taux d'absentéisme et congés approuvés des 30 derniers jours.
 */
class AbsenceWidget extends Widget
{
    public function render(): View
    {
        return app('view')->make($this->viewName('widgets.absence'), [
            'period' => DashboardFigures::PERIOD_DAYS,
            'figures' => $this->figures()->absence(array_keys($this->stores())),
        ]);
    }
}
