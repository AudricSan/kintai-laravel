<?php

namespace Kintai\Bundles\Timeclock\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Timeclock\TimeclockActions;
use Kintai\Contracts\Api\ApiException;

/**
 * Gestes d'entrée et de sortie, communs à la page « Pointage » et au widget de l'employé.
 */
trait ClocksIn
{
    public string $storeId = '';

    public function clockIn(TimeclockActions $actions): void
    {
        $this->resetErrorBag();

        try {
            $actions->clockIn($this->employee(), ctype_digit($this->storeId) ? (int) $this->storeId : null, null, request()->ip());
        } catch (ApiException $e) {
            $this->addError('clock', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Clock-in recorded.'));
    }

    public function clockOut(TimeclockActions $actions): void
    {
        $this->resetErrorBag();

        try {
            $actions->clockOut($this->employee(), null, request()->ip());
        } catch (ApiException $e) {
            $this->addError('clock', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Clock-out recorded.'));
    }

    private function employee(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
