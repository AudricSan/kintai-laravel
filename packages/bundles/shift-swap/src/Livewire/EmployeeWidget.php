<?php

namespace Kintai\Bundles\ShiftSwap\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\ShiftSwap\SwapAccess;

/**
 * Widget « Échanges en attente de ma réponse » du tableau de bord employé : les demandes de collègues auxquelles il n'a
 * pas encore répondu.
 */
class EmployeeWidget extends ManagerWidget
{
    protected function title(): string
    {
        return __('Swaps awaiting my answer');
    }

    protected function link(): string
    {
        return 'shift-swap.index';
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $pending = app(SwapAccess::class)->awaitingAnswer($user);

        return app('view')->make($this->viewName('widget'), [
            'title' => $this->title(),
            'link' => $this->link(),
            'total' => (clone $pending)->count(),
            'requests' => $pending->with(['requester:id,name', 'target:id,name', 'requesterShift', 'targetShift'])->orderBy('created_at')->orderBy('id')->limit(self::LIMIT)->get(),
        ]);
    }
}
