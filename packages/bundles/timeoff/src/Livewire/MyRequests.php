<?php

namespace Kintai\Bundles\Timeoff\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Kintai\Bundles\Timeoff\TimeoffAccess;
use Kintai\Bundles\Timeoff\TimeoffActions;
use Kintai\Contracts\Notifications\Notifier;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Mes congés » : l'employé demande un congé dans l'un de ses magasins et suit ou annule ses demandes en attente.
 */
#[Title('My time off')]
class MyRequests extends Component
{
    use ResolvesViews;

    /** Demande désignée par une notification. */
    #[Url(except: 0)]
    public int $highlight = 0;

    public string $storeId = '';

    public string $type = 'vacation';

    public string $startDate = '';

    public string $endDate = '';

    public string $reason = '';

    #[Locked]
    public int $cancellingId = 0;

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

    private function access(): TimeoffAccess
    {
        return app(TimeoffAccess::class);
    }

    public function submit(TimeoffActions $actions): void
    {
        $validated = $this->validate([
            'storeId' => ['required', 'integer'],
            'type' => ['required', Rule::in(TimeoffRequest::TYPES)],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $actions->submit(
            $this->user(), (int) $validated['storeId'], $validated['type'], $validated['startDate'],
            $validated['endDate'] ?: $validated['startDate'], $validated['reason'] ?? null,
        );

        $this->reset('startDate', 'endDate', 'reason');
        Flux::toast(variant: 'success', text: __('Time off request sent.'));
    }

    public function confirmCancellation(int $id): void
    {
        $request = TimeoffRequest::query()->where('user_id', $this->user()->id)->find($id);
        abort_if($request === null, 404);

        $this->cancellingId = $request->id;
        Flux::modal('confirm-timeoff-cancellation')->show();
    }

    public function cancel(TimeoffActions $actions): void
    {
        $request = TimeoffRequest::query()->where('user_id', $this->user()->id)->find($this->cancellingId);
        abort_if($request === null, 404);

        $actions->cancel($this->user(), $request);

        $this->reset('cancellingId');
        Flux::modal('confirm-timeoff-cancellation')->close();
        Flux::toast(variant: 'success', text: __('Request cancelled.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $stores = $this->access()->memberStores($user);
        abort_if($stores === [], 404);

        $requests = TimeoffRequest::query()->with('store:id,name')->where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        // L'employé voit ses demandes : les notifications de décision sont lues.
        app(Notifier::class)->markRead($user, 'timeoff', $requests->modelKeys());

        return app('view')->make($this->viewName('my-requests'), [
            'requests' => $requests,
            'stores' => $stores,
            'types' => TimeoffRequest::TYPES,
            'canManage' => $user->can('timeoff.view'),
        ]);
    }
}
