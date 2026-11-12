<?php

namespace Kintai\Bundles\Timeoff\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Timeoff\TimeoffAccess;
use Livewire\Component;

/**
 * Widget « Congés en attente » du tableau de bord gérant : les demandes à décider dans ses magasins.
 */
class ManagerWidget extends Component
{
    use ResolvesViews;

    public const LIMIT = 5;

    protected function title(): string
    {
        return __('Pending time off');
    }

    protected function link(): string
    {
        return 'timeoff.manage';
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $pending = app(TimeoffAccess::class)->pendingFor($user);

        return app('view')->make($this->viewName('widget'), [
            'title' => $this->title(),
            'link' => $this->link(),
            'total' => (clone $pending)->count(),
            'requests' => $pending->with(['user:id,name', 'store:id,name'])->orderBy('start_date')->orderBy('id')->limit(self::LIMIT)->get(),
        ]);
    }
}
