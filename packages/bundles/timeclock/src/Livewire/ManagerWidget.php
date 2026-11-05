<?php

namespace Kintai\Bundles\Timeclock\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Bundles\Timeclock\TimeclockAccess;
use Livewire\Component;

/**
 * Widget « Pointages actifs » du tableau de bord gérant : les employés en poste en ce moment dans ses magasins.
 */
class ManagerWidget extends Component
{
    use ResolvesViews;

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $access = app(TimeclockAccess::class);
        $stores = $access->storesWith($user, 'timeclock.view');

        $active = Timeclock::query()
            ->with(['user:id,name', 'store:id,name'])
            ->whereIn('store_id', array_keys($stores))
            ->whereNull('clock_out_at')
            ->orderBy('clock_in_at')
            ->get();

        return app('view')->make($this->viewName('manager-widget'), ['active' => $active]);
    }
}
