<?php

namespace Kintai\Bundles\Analytics\Livewire;

use Illuminate\Contracts\View\View;
use Kintai\Bundles\Analytics\StoreStatsService;
use Livewire\Attributes\Title;

/**
 * Rapport par employé d'un magasin : shifts, heures, coût estimé, jours travaillés, congés approuvés et série la plus longue,
 * triés par heures nettes. Chaque ligne mène à la fiche de l'employé.
 */
#[Title('Employee report')]
class EmployeeReport extends StorePage
{
    public function render(StoreStatsService $service): View
    {
        $store = $this->currentStore();
        $report = $service->employeeReport($store->id, $this->clampPeriod($this->period));

        return app('view')->make($this->viewName('employees'), [
            'stores' => $this->stores(),
            'storeModel' => $store,
            'periods' => self::PERIODS,
            'report' => $report,
        ]);
    }
}
