<?php

namespace Kintai\Bundles\ShiftSwap\Livewire;

use App\Models\Shift;
use App\Models\StoreMembership;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Bundles\ShiftSwap\SwapAccess;
use Kintai\Bundles\ShiftSwap\SwapActions;
use Kintai\Contracts\Notifications\Notifier;
use Kintai\Contracts\Shifts\ShiftWarnings;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Échanges des magasins du gérant : filtres, approbation (avec les avertissements du planning à confirmer), refus,
 * suppression, échange appliqué d'emblée entre deux employés. Droits revérifiés à chaque action (`SwapAccess`).
 */
#[Title('Swap requests')]
class Manage extends Component
{
    use ResolvesViews;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $store = '';

    #[Url(except: 'desc')]
    public string $sort = 'desc';

    /** Demande désignée par une notification. */
    #[Url(except: 0)]
    public int $highlight = 0;

    #[Locked]
    public int $approvingId = 0;

    #[Locked]
    public int $refusingId = 0;

    #[Locked]
    public int $deletingId = 0;

    /** @var list<string> Avertissements du planning à confirmer avant d'écrire. */
    public array $warnings = [];

    /** Action en attente de confirmation : `approve` ou `direct`. */
    #[Locked]
    public string $pending = '';

    // Échange appliqué d'emblée.
    public string $directStore = '';

    public string $firstUser = '';

    public string $secondUser = '';

    public string $firstShift = '';

    public string $secondShift = '';

    public string $directReason = '';

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

    public function mount(): void
    {
        abort_unless($this->user()->can('swaps.view'), 403);
    }

    private function find(int $id): ShiftSwapRequest
    {
        $swap = ShiftSwapRequest::query()->find($id);
        abort_if($swap === null || ! $this->access()->canView($this->user(), $swap), 404);

        return $swap;
    }

    public function approve(int $id, SwapActions $actions): void
    {
        $this->runApproval($this->find($id), false, $actions);
    }

    private function runApproval(ShiftSwapRequest $swap, bool $force, SwapActions $actions): void
    {
        try {
            $actions->approve($this->user(), $swap, $force);
        } catch (ShiftWarnings $e) {
            $this->approvingId = $swap->id;
            $this->pending = 'approve';
            $this->warnings = $e->messages();
            Flux::modal('swap-warnings')->show();

            return;
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        }

        $this->clearWarnings();
        Flux::toast(variant: 'success', text: __('Swap approved and applied to the schedule.'));
    }

    /**
     * L'utilisateur confirme malgré les avertissements.
     */
    public function confirmWarnings(SwapActions $actions): void
    {
        if ($this->pending === 'approve') {
            $this->runApproval($this->find($this->approvingId), true, $actions);
        } elseif ($this->pending === 'direct') {
            $this->createDirect($actions, true);
        }
    }

    public function dismissWarnings(): void
    {
        $this->clearWarnings();
    }

    private function clearWarnings(): void
    {
        $this->reset('warnings', 'pending', 'approvingId');
        Flux::modal('swap-warnings')->close();
    }

    public function confirmRefusal(int $id): void
    {
        $swap = $this->find($id);
        abort_unless($this->access()->canApprove($this->user(), $swap), 403);

        $this->refusingId = $swap->id;
        Flux::modal('confirm-swap-refusal')->show();
    }

    public function refuse(SwapActions $actions): void
    {
        try {
            $actions->refuse($this->user(), $this->find($this->refusingId));
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        } finally {
            $this->reset('refusingId');
            Flux::modal('confirm-swap-refusal')->close();
        }

        Flux::toast(variant: 'success', text: __('Request refused.'));
    }

    public function confirmDeletion(int $id): void
    {
        $swap = $this->find($id);
        abort_unless($this->access()->canDelete($this->user(), $swap), 403);

        $this->deletingId = $swap->id;
        Flux::modal('confirm-swap-deletion')->show();
    }

    public function delete(SwapActions $actions): void
    {
        try {
            $actions->delete($this->user(), $this->find($this->deletingId));
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        } finally {
            $this->reset('deletingId');
            Flux::modal('confirm-swap-deletion')->close();
        }

        Flux::toast(variant: 'success', text: __('The swap request has been deleted.'));
    }

    public function updatedDirectStore(): void
    {
        $this->reset('firstUser', 'secondUser', 'firstShift', 'secondShift');
    }

    public function updatedFirstUser(): void
    {
        $this->reset('firstShift');
    }

    public function updatedSecondUser(): void
    {
        $this->reset('secondShift');
    }

    public function createDirect(SwapActions $actions, bool $force = false): void
    {
        $validated = $this->validate([
            'directStore' => ['required', 'integer'],
            'firstUser' => ['required', 'integer', 'different:secondUser'],
            'secondUser' => ['required', 'integer'],
            'firstShift' => ['required', 'integer'],
            'secondShift' => ['required', 'integer'],
            'directReason' => ['nullable', 'string', 'max:2000'],
        ]);

        // Les shifts choisis doivent être ceux des employés choisis, dans le magasin choisi.
        $shifts = Shift::query()->whereIn('id', [(int) $validated['firstShift'], (int) $validated['secondShift']])->get()->keyBy('id');
        $first = $shifts->get((int) $validated['firstShift']);
        $second = $shifts->get((int) $validated['secondShift']);

        if ($first?->user_id !== (int) $validated['firstUser'] || $second?->user_id !== (int) $validated['secondUser']
            || $first->store_id !== (int) $validated['directStore'] || $second->store_id !== (int) $validated['directStore']) {
            $this->addError('secondShift', __('The shifts do not match the selected employees.'));

            return;
        }

        try {
            $actions->createApplied($this->user(), $first->id, $second->id, $validated['directReason'] ?? null, $force);
        } catch (ShiftWarnings $e) {
            $this->pending = 'direct';
            $this->warnings = $e->messages();
            Flux::modal('swap-warnings')->show();

            return;
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        }

        $this->reset('directStore', 'firstUser', 'secondUser', 'firstShift', 'secondShift', 'directReason', 'warnings', 'pending');
        Flux::modal('swap-warnings')->close();
        Flux::modal('create-swap')->close();
        Flux::toast(variant: 'success', text: __('The swap has been applied.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();

        $requests = $access->managed($user)
            ->with(['requester:id,name', 'target:id,name', 'store:id,name', 'approver:id,name', 'requesterShift', 'targetShift'])
            ->when(in_array($this->status, ShiftSwapRequest::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->when(ctype_digit($this->store), fn ($q) => $q->where('store_id', (int) $this->store))
            ->orderBy('created_at', $this->sort === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', $this->sort === 'asc' ? 'asc' : 'desc')
            ->get();

        // Le gérant voit les demandes : les notifications « à décider » correspondantes sont lues.
        app(Notifier::class)->markRead($user, 'swap', $requests->modelKeys());

        $creatable = $access->storesWith($user, 'swaps.create');
        $directStore = ctype_digit($this->directStore) && isset($creatable[(int) $this->directStore]) ? (int) $this->directStore : 0;
        $employees = [];
        $firstShifts = [];
        $secondShifts = [];

        if ($directStore > 0) {
            $ids = StoreMembership::query()->where('store_id', $directStore)->where('is_active', true)->pluck('user_id');
            $employees = User::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();

            if (ctype_digit($this->firstUser) && isset($employees[(int) $this->firstUser])) {
                $firstShifts = $access->upcomingShifts((int) $this->firstUser, $directStore);
            }

            if (ctype_digit($this->secondUser) && isset($employees[(int) $this->secondUser])) {
                $secondShifts = $access->upcomingShifts((int) $this->secondUser, $directStore);
            }
        }

        return app('view')->make($this->viewName('manage'), [
            'requests' => $requests,
            'access' => $access,
            'user' => $user,
            'stores' => $access->storesWith($user, 'swaps.view'),
            'statuses' => ShiftSwapRequest::STATUSES,
            'creatable' => $creatable,
            'employees' => $employees,
            'firstShifts' => $firstShifts,
            'secondShifts' => $secondShifts,
        ]);
    }
}
