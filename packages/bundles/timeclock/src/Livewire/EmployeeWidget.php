<?php

namespace Kintai\Bundles\Timeclock\Livewire;

use Illuminate\Contracts\View\View;
use Kintai\Bundles\Timeclock\TimeclockAccess;
use Kintai\Bundles\Timeclock\TimeclockActions;
use Livewire\Component;

/**
 * Widget « Pointage » du tableau de bord employé : l'état du pointage et les boutons d'entrée et de sortie.
 */
class EmployeeWidget extends Component
{
    use ClocksIn;
    use ResolvesViews;

    public function mount(): void
    {
        $this->storeId = (string) array_key_first(app(TimeclockAccess::class)->memberStores($this->employee()));
    }

    public function render(): View
    {
        $user = $this->employee();

        return app('view')->make($this->viewName('employee-widget'), [
            'active' => app(TimeclockActions::class)->active($user)?->load('store:id,name'),
            'stores' => app(TimeclockAccess::class)->memberStores($user),
        ]);
    }
}
