<?php

namespace Kintai\Bundles\Analytics\Livewire;

use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Kintai\Bundles\Analytics\EmployeeStatsService;
use Kintai\Contracts\Activity\ActivityRecorder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Fiche statistique d'un employé dans un magasin (`?user=`). Le magasin est l'un de ceux que l'utilisateur voit
 * (`payroll.view`) et où l'employé est membre ; sinon 403. L'ouverture de la fiche est journalisée.
 */
#[Title('Employee statistics')]
class EmployeeStats extends StorePage
{
    #[Url(as: 'user', except: 0)]
    public int $employee = 0;

    public function mount(): void
    {
        $stores = $this->employeeStores();
        abort_if($stores === [], 403);

        $this->store = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? $this->store : (string) array_key_first($stores);
        $this->period = $this->clampPeriod($this->period);

        app(ActivityRecorder::class)->record('analytics.employee_viewed', $this->employeeModel(), (int) $this->store, ['period' => $this->period], $this->user());
    }

    /**
     * Magasins que l'utilisateur voit où l'employé est membre, nom par identifiant.
     *
     * @return array<int, string>
     */
    private function employeeStores(): array
    {
        $ids = $this->access()->storesOfEmployee($this->user(), $this->employee);
        $stores = [];
        foreach ($this->stores() as $id => $name) {
            if (in_array($id, $ids, true)) {
                $stores[$id] = $name;
            }
        }

        return $stores;
    }

    private function employeeModel(): User
    {
        return User::withTrashed()->findOrFail($this->employee);
    }

    public function render(EmployeeStatsService $service): View
    {
        $stores = $this->employeeStores();
        abort_unless(isset($stores[(int) $this->store]), 403);

        $store = Store::query()->findOrFail((int) $this->store);

        return app('view')->make($this->viewName('employee'), [
            'stores' => $stores,
            'storeModel' => $store,
            'employeeModel' => $this->employeeModel(),
            'periods' => self::PERIODS,
            'stats' => $service->employeeStats($store->id, $this->employee, $this->clampPeriod($this->period)),
        ]);
    }
}
