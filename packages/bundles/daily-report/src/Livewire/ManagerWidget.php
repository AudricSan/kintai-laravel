<?php

namespace Kintai\Bundles\DailyReport\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\DailyReport\DailyReportAccess;
use Livewire\Component;

/**
 * Widget « Rapports à valider » du tableau de bord gérant : les rapports soumis de ses magasins.
 */
class ManagerWidget extends Component
{
    use ResolvesViews;

    public const LIMIT = 5;

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $pending = app(DailyReportAccess::class)->toValidate($user);

        return app('view')->make($this->viewName('widget'), [
            'total' => (clone $pending)->count(),
            'reports' => $pending->with('store:id,name')->orderBy('report_date')->orderBy('id')->limit(self::LIMIT)->get(),
        ]);
    }
}
