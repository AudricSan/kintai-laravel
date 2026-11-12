<?php

namespace Kintai\Bundles\ShiftSwap\Livewire;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\ShiftSwap\SwapAccess;
use Kintai\Bundles\ShiftSwap\SwapActions;
use Kintai\Contracts\Stores\ColleagueDirectory;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * « Demander un échange » : l'employé choisit un de ses shifts à venir, un collègue du magasin et l'un de ses shifts à
 * venir, et laisse un motif. La demande est ensuite soumise au collègue puis à un gérant.
 */
#[Title('Request a swap')]
class Create extends Component
{
    use ResolvesViews;

    public string $storeId = '';

    public string $myShiftId = '';

    public string $colleagueId = '';

    public string $theirShiftId = '';

    public string $reason = '';

    public function mount(): void
    {
        $stores = $this->access()->memberStores($this->user());
        abort_if($stores === [], 404);

        $this->storeId = (string) array_key_first($stores);
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

    public function updatedStoreId(): void
    {
        $this->reset('myShiftId', 'colleagueId', 'theirShiftId');
    }

    public function updatedColleagueId(): void
    {
        $this->reset('theirShiftId');
    }

    public function submit(SwapActions $actions): void
    {
        $validated = $this->validate([
            'myShiftId' => ['required', 'integer'],
            'colleagueId' => ['required', 'integer'],
            'theirShiftId' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // Le shift visé doit être celui du collègue choisi.
        if (Shift::query()->whereKey((int) $validated['theirShiftId'])->value('user_id') !== (int) $validated['colleagueId']) {
            $this->addError('theirShiftId', __('Choose a shift of the selected colleague.'));

            return;
        }

        try {
            $swap = $actions->request($this->user(), (int) $validated['myShiftId'], (int) $validated['theirShiftId'], $validated['reason'] ?? null);
        } catch (ValidationException $e) {
            $this->toastFailure($e);

            return;
        }

        $this->redirectRoute('shift-swap.index', ['highlight' => $swap->id], navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $stores = $this->access()->memberStores($user);
        $storeId = ctype_digit($this->storeId) && isset($stores[(int) $this->storeId]) ? (int) $this->storeId : 0;

        $colleagues = $storeId > 0 ? app(ColleagueDirectory::class)->colleagues($user, [$storeId]) : [];
        $colleagueId = ctype_digit($this->colleagueId) ? (int) $this->colleagueId : 0;

        return app('view')->make($this->viewName('create'), [
            'stores' => $stores,
            'colleagues' => $colleagues,
            'myShifts' => $storeId > 0 ? $this->access()->upcomingShifts($user->id, $storeId) : [],
            'theirShifts' => $storeId > 0 && $colleagueId > 0 ? $this->access()->upcomingShifts($colleagueId, $storeId) : [],
        ]);
    }
}
