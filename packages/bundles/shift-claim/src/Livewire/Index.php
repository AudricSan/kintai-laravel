<?php

namespace Kintai\Bundles\ShiftClaim\Livewire;

use App\Models\Shift;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftClaim\ClaimAccess;
use Kintai\Bundles\ShiftClaim\ClaimActions;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;
use Kintai\Bundles\ShiftClaim\Models\ShiftOffer;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Shifts ouverts » : la bourse des magasins de l'employé (shifts ouverts et shifts proposés par un gérant). Il y
 * candidate, avec une note facultative, et retire sa candidature tant qu'elle est en attente.
 */
#[Title('Open shifts')]
class Index extends Component
{
    use ResolvesViews;

    /** Shift désigné par une notification. */
    #[Url(except: 0)]
    public int $highlight = 0;

    #[Locked]
    public int $claimingId = 0;

    public string $note = '';

    #[Locked]
    public int $withdrawingId = 0;

    public function mount(): void
    {
        abort_if($this->access()->memberStores($this->user()) === [], 404);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): ClaimAccess
    {
        return app(ClaimAccess::class);
    }

    public function confirmClaim(int $shiftId): void
    {
        $stores = array_keys($this->access()->memberStores($this->user()));
        $shift = $this->access()->pool($stores)->find($shiftId);
        abort_if($shift === null, 404);

        $this->claimingId = $shift->id;
        $this->note = '';
        Flux::modal('confirm-shift-claim')->show();
    }

    public function claim(ClaimActions $actions): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            $actions->claim($this->user(), $this->claimingId, $this->note);
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        } finally {
            $this->reset('claimingId', 'note');
            Flux::modal('confirm-shift-claim')->close();
        }

        Flux::toast(variant: 'success', text: __('Application sent.'));
    }

    public function confirmWithdrawal(int $claimId): void
    {
        $claim = ShiftClaim::query()->where('user_id', $this->user()->id)->find($claimId);
        abort_if($claim === null, 404);

        $this->withdrawingId = $claim->id;
        Flux::modal('confirm-claim-withdrawal')->show();
    }

    public function withdraw(ClaimActions $actions): void
    {
        $claim = ShiftClaim::query()->where('user_id', $this->user()->id)->find($this->withdrawingId);
        abort_if($claim === null, 404);

        try {
            $actions->withdraw($this->user(), $claim);
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        } finally {
            $this->reset('withdrawingId');
            Flux::modal('confirm-claim-withdrawal')->close();
        }

        Flux::toast(variant: 'success', text: __('Application withdrawn.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $stores = $this->access()->memberStores($user);

        $shifts = $this->access()->pool(array_keys($stores))->with('store:id,name')->get();
        $claims = ShiftClaim::query()->where('user_id', $user->id)->whereIn('shift_id', $shifts->modelKeys())->get()->keyBy('shift_id');
        $offered = ShiftOffer::query()->whereIn('shift_id', $shifts->modelKeys())->pluck('note', 'shift_id');

        // L'employé voit la bourse : les notifications correspondantes sont lues.
        app(Notifier::class)->markRead($user, 'open_shift', $shifts->modelKeys());

        return app('view')->make($this->viewName('index'), [
            'shifts' => $shifts,
            'claims' => $claims,
            'offered' => $offered,
            'user' => $user,
            'canManage' => $user->can('open_shifts.view'),
            'history' => ShiftClaim::query()->with('shift')->where('user_id', $user->id)->whereNotIn('shift_id', $shifts->modelKeys())
                ->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->get()
                ->filter(fn (ShiftClaim $claim): bool => $claim->shift instanceof Shift),
        ]);
    }
}
