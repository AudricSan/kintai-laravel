<?php

namespace Kintai\Bundles\ShiftSwap\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Bundles\ShiftSwap\SwapAccess;
use Kintai\Bundles\ShiftSwap\SwapActions;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Mes échanges » : les demandes reçues (accepter, refuser) et envoyées (retirer) de l'employé, et le chemin vers une
 * nouvelle demande.
 */
#[Title('Shift swaps')]
class Index extends Component
{
    use ResolvesViews;

    /** Demande désignée par une notification. */
    #[Url(except: 0)]
    public int $highlight = 0;

    #[Locked]
    public int $refusingId = 0;

    #[Locked]
    public int $cancellingId = 0;

    public function mount(): void
    {
        abort_if($this->access()->memberStores($this->user()) === [] && ! $this->user()->can('swaps.view'), 404);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): SwapAccess
    {
        return app(SwapAccess::class);
    }

    /** Une demande reçue par l'employé. */
    private function received(int $id): ShiftSwapRequest
    {
        $swap = ShiftSwapRequest::query()->where('target_user_id', $this->user()->id)->find($id);
        abort_if($swap === null, 404);

        return $swap;
    }

    /** Une demande envoyée par l'employé. */
    private function sent(int $id): ShiftSwapRequest
    {
        $swap = ShiftSwapRequest::query()->where('requester_id', $this->user()->id)->find($id);
        abort_if($swap === null, 404);

        return $swap;
    }

    public function accept(int $id, SwapActions $actions): void
    {
        try {
            $actions->answer($this->user(), $this->received($id), true);
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        }

        Flux::toast(variant: 'success', text: __('Swap accepted. It now waits for a manager.'));
    }

    public function confirmRefusal(int $id): void
    {
        $this->refusingId = $this->received($id)->id;
        Flux::modal('confirm-swap-refusal')->show();
    }

    public function refuse(SwapActions $actions): void
    {
        try {
            $actions->answer($this->user(), $this->received($this->refusingId), false);
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        } finally {
            $this->reset('refusingId');
            Flux::modal('confirm-swap-refusal')->close();
        }

        Flux::toast(variant: 'success', text: __('Swap declined.'));
    }

    public function confirmCancellation(int $id): void
    {
        $this->cancellingId = $this->sent($id)->id;
        Flux::modal('confirm-swap-cancellation')->show();
    }

    public function cancel(SwapActions $actions): void
    {
        try {
            $actions->cancel($this->user(), $this->sent($this->cancellingId));
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        } finally {
            $this->reset('cancellingId');
            Flux::modal('confirm-swap-cancellation')->close();
        }

        Flux::toast(variant: 'success', text: __('Request cancelled.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $with = ['requester:id,name', 'target:id,name', 'store:id,name', 'requesterShift', 'targetShift'];

        $received = ShiftSwapRequest::query()->with($with)->where('target_user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->get();
        $sent = ShiftSwapRequest::query()->with($with)->where('requester_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        // L'employé voit ses échanges : les notifications correspondantes sont lues.
        app(Notifier::class)->markRead($user, 'swap', $received->merge($sent)->modelKeys());

        return app('view')->make($this->viewName('index'), [
            'received' => $received,
            'sent' => $sent,
            'canRequest' => $this->access()->memberStores($user) !== [],
            'canManage' => $user->can('swaps.view'),
        ]);
    }
}
