<?php

namespace Kintai\Bundles\ShiftSwap\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\ShiftSwap\SwapAccess;
use Livewire\Component;

/**
 * Widget « Échanges à approuver » du tableau de bord gérant : les demandes acceptées par le collègue qui attendent sa
 * décision dans ses magasins.
 */
class ManagerWidget extends Component
{
    use ResolvesViews;

    public const LIMIT = 5;

    protected function title(): string
    {
        return __('Swaps to approve');
    }

    protected function link(): string
    {
        return 'shift-swap.manage';
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $pending = app(SwapAccess::class)->awaitingDecision($user);

        return app('view')->make($this->viewName('widget'), [
            'title' => $this->title(),
            'link' => $this->link(),
            'total' => (clone $pending)->count(),
            'requests' => $pending->with(['requester:id,name', 'target:id,name', 'requesterShift', 'targetShift'])->orderBy('created_at')->orderBy('id')->limit(self::LIMIT)->get(),
        ]);
    }
}
