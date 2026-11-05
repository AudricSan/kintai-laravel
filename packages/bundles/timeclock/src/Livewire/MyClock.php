<?php

namespace Kintai\Bundles\Timeclock\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Bundles\Timeclock\TimeclockAccess;
use Kintai\Bundles\Timeclock\TimeclockActions;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * « Pointage » : l'employé pointe son entrée et sa sortie et voit ses pointages du jour et de la semaine.
 */
#[Title('Time clock')]
class MyClock extends Component
{
    use ClocksIn;
    use ResolvesViews;

    public function mount(): void
    {
        $stores = app(TimeclockAccess::class)->memberStores($this->employee());
        abort_if($stores === [], 404);

        $this->storeId = (string) array_key_first($stores);
    }

    public function render(): View
    {
        $user = $this->employee();
        $access = app(TimeclockAccess::class);
        $stores = $access->memberStores($user);
        abort_if($stores === [], 404);

        $today = Carbon::now(Timeclock::timezone());
        $weekStart = $today->copy()->startOfWeek();

        $week = Timeclock::query()
            ->with(['store:id,name', 'shift'])
            ->where('user_id', $user->id)
            ->whereDate('shift_date', '>=', $weekStart->toDateString())
            ->whereDate('shift_date', '<=', $weekStart->copy()->endOfWeek()->toDateString())
            ->orderByDesc('clock_in_at')
            ->get();

        return app('view')->make($this->viewName('my-clock'), [
            'stores' => $stores,
            'active' => app(TimeclockActions::class)->active($user)?->load('store:id,name'),
            'week' => $week,
            'weekMinutes' => (int) $week->sum('duration_minutes'),
            'threshold' => $access->lateThreshold(),
            'today' => $today->toDateString(),
        ]);
    }
}
