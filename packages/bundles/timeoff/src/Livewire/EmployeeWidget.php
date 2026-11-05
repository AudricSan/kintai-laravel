<?php

namespace Kintai\Bundles\Timeoff\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;

/**
 * Widget « Mes congés en attente » du tableau de bord employé : ses propres demandes qui attendent une décision.
 */
class EmployeeWidget extends ManagerWidget
{
    protected function title(): string
    {
        return __('My pending time off');
    }

    protected function link(): string
    {
        return 'timeoff.index';
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $pending = TimeoffRequest::query()->where('user_id', $user->id)->where('status', TimeoffRequest::PENDING);

        return app('view')->make($this->viewName('widget'), [
            'title' => $this->title(),
            'link' => $this->link(),
            'total' => (clone $pending)->count(),
            'requests' => $pending->with(['user:id,name', 'store:id,name'])->orderBy('start_date')->orderBy('id')->limit(self::LIMIT)->get(),
        ]);
    }
}
