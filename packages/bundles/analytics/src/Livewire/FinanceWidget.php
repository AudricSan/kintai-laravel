<?php

namespace Kintai\Bundles\Analytics\Livewire;

use Illuminate\Contracts\View\View;

/**
 * Widget « Vue d'ensemble financière » : masse salariale du mois, comparée au mois précédent, et tendance des six derniers mois.
 */
class FinanceWidget extends Widget
{
    public function render(): View
    {
        return app('view')->make($this->viewName('widgets.finance'), [
            'groups' => $this->figures()->finance(array_keys($this->stores())),
        ]);
    }
}
