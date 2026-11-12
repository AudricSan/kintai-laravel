<?php

namespace Kintai\Bundles\ShiftClaim\Livewire;

use App\Models\Shift;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftClaim\ClaimAccess;
use Kintai\Bundles\ShiftClaim\ClaimActions;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;
use Kintai\Bundles\ShiftClaim\Models\ShiftOffer;
use Kintai\Contracts\Notifications\Notifier;
use Kintai\Contracts\Shifts\ShiftWarnings;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * La bourse des magasins du gérant : shifts disponibles et candidatures, approbation (avec les avertissements du
 * planning à confirmer), rejet, proposition d'un shift attribué à la bourse, retrait d'une proposition. Droits
 * revérifiés à chaque action (`ClaimAccess` et `ClaimActions`).
 */
#[Title('Shift applications')]
class Manage extends Component
{
    use ResolvesViews;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $store = '';

    /** Shift désigné par une notification. */
    #[Url(except: 0)]
    public int $highlight = 0;

    #[Locked]
    public int $approvingId = 0;

    #[Locked]
    public int $rejectingId = 0;

    #[Locked]
    public int $unofferingId = 0;

    /** @var list<string> Avertissements du planning à confirmer avant d'écrire. */
    public array $warnings = [];

    // Proposition d'un shift attribué à la bourse.
    public string $offerShift = '';

    public string $offerNote = '';

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

    public function mount(): void
    {
        abort_unless($this->user()->can('open_shifts.view'), 403);
    }

    private function find(int $id): ShiftClaim
    {
        $claim = ShiftClaim::query()->find($id);
        abort_if($claim === null || ! $this->access()->canView($this->user(), $claim), 404);

        return $claim;
    }

    public function approve(int $id, ClaimActions $actions): void
    {
        $this->runApproval($this->find($id), false, $actions);
    }

    private function runApproval(ShiftClaim $claim, bool $force, ClaimActions $actions): void
    {
        try {
            $actions->approve($this->user(), $claim, $force);
        } catch (ShiftWarnings $e) {
            $this->approvingId = $claim->id;
            $this->warnings = $e->messages();
            Flux::modal('claim-warnings')->show();

            return;
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        }

        $this->reset('warnings', 'approvingId');
        Flux::modal('claim-warnings')->close();
        Flux::toast(variant: 'success', text: __('Application approved: the shift is assigned.'));
    }

    /**
     * Le gérant confirme malgré les avertissements.
     */
    public function confirmWarnings(ClaimActions $actions): void
    {
        abort_if($this->approvingId === 0, 404);

        $this->runApproval($this->find($this->approvingId), true, $actions);
    }

    public function confirmRejection(int $id): void
    {
        $claim = $this->find($id);
        abort_unless($this->access()->canApprove($this->user(), $claim), 403);

        $this->rejectingId = $claim->id;
        Flux::modal('confirm-claim-rejection')->show();
    }

    public function reject(ClaimActions $actions): void
    {
        try {
            $actions->reject($this->user(), $this->find($this->rejectingId));
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        } finally {
            $this->reset('rejectingId');
            Flux::modal('confirm-claim-rejection')->close();
        }

        Flux::toast(variant: 'success', text: __('Application rejected.'));
    }

    public function offer(ClaimActions $actions): void
    {
        $validated = $this->validate([
            'offerShift' => ['required', 'integer'],
            'offerNote' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $actions->offer($this->user(), (int) $validated['offerShift'], $validated['offerNote'] ?? null);
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        }

        $this->reset('offerShift', 'offerNote');
        Flux::modal('offer-shift')->close();
        Flux::toast(variant: 'success', text: __('The shift is now open for applications.'));
    }

    public function confirmUnoffer(int $shiftId): void
    {
        $offer = ShiftOffer::query()->with('shift')->where('shift_id', $shiftId)->first();
        abort_if($offer === null || ! $this->access()->canPublish($this->user(), $offer->shift->store_id), 404);

        $this->unofferingId = $shiftId;
        Flux::modal('confirm-unoffer')->show();
    }

    public function unoffer(ClaimActions $actions): void
    {
        try {
            $actions->unoffer($this->user(), $this->unofferingId);
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        } finally {
            $this->reset('unofferingId');
            Flux::modal('confirm-unoffer')->close();
        }

        Flux::toast(variant: 'success', text: __('The shift is no longer open for applications.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();
        $stores = $access->storesWith($user, 'open_shifts.view');
        $storeIds = array_keys($stores);

        if (ctype_digit($this->store) && isset($stores[(int) $this->store])) {
            $storeIds = [(int) $this->store];
        }

        $shifts = $access->pool($storeIds)->with(['store:id,name', 'user:id,name'])->get();
        $claims = ShiftClaim::query()->with(['user:id,name', 'store:id,name', 'shift', 'resolver:id,name'])
            ->whereIn('store_id', $storeIds)
            ->when(in_array($this->status, ShiftClaim::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $offered = ShiftOffer::query()->whereIn('shift_id', $shifts->modelKeys())->pluck('note', 'shift_id');

        // Le gérant voit la bourse : les notifications « candidature à décider » correspondantes sont lues.
        app(Notifier::class)->markRead($user, 'open_shift', $shifts->modelKeys());

        $publishable = $access->storesWith($user, 'open_shifts.publish');
        $assigned = $publishable === [] ? collect() : Shift::query()
            ->with(['store:id,name', 'user:id,name'])
            ->whereIn('store_id', array_keys($publishable))->whereNotNull('user_id')->whereNull('replaced_by_id')
            ->where('starts_at', '>', Carbon::now())
            ->whereNotIn('id', ShiftOffer::query()->select('shift_id'))
            ->orderBy('starts_at')->limit(200)->get();

        return app('view')->make($this->viewName('manage'), [
            'shifts' => $shifts,
            'claimsByShift' => $claims->groupBy('shift_id'),
            'claims' => $claims,
            'offered' => $offered,
            'access' => $access,
            'user' => $user,
            'stores' => $stores,
            'statuses' => ShiftClaim::STATUSES,
            'assigned' => $assigned,
            'canPublish' => $publishable !== [],
        ]);
    }
}
