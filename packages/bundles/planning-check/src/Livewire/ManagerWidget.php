<?php

namespace Kintai\Bundles\PlanningCheck\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheck;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;
use Kintai\Bundles\PlanningCheck\PlanningCheckAccess;
use Kintai\Bundles\PlanningCheck\PlanningCheckPolicy;
use Livewire\Component;

/**
 * Widget « Planning de demain » du tableau de bord gérant : pour chaque magasin où la validation est activée, l'état de
 * celle de demain et ce qu'il reste à faire.
 */
class ManagerWidget extends Component
{
    use ResolvesViews;

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $access = app(PlanningCheckAccess::class);
        $date = PlanningCheckPolicy::targetDate();
        $lines = [];

        foreach ($access->visibleStores($user) as $storeId => $name) {
            $setting = PlanningCheckSetting::forStore($storeId);
            if (! $setting->enabled || ! PlanningCheckPolicy::isWorkingDay($setting, $date)) {
                continue;
            }

            $status = (string) PlanningCheck::query()->where('store_id', $storeId)->whereDate('target_date', $date)->value('status');
            $lines[] = [
                'store' => $name,
                'storeId' => $storeId,
                'status' => $status === '' ? PlanningCheck::PENDING : $status,
                'late' => PlanningCheckPolicy::isLate($setting) && ($status === '' || in_array($status, PlanningCheck::OPEN, true)),
            ];
        }

        return app('view')->make($this->viewName('widget'), ['lines' => $lines, 'date' => $date]);
    }
}
