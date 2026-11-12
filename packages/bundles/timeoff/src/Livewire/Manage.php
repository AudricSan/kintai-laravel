<?php

namespace Kintai\Bundles\Timeoff\Livewire;

use App\Models\StoreMembership;
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
 * Demandes de congé des magasins du gérant : filtres, approbation, refus (avec une note), suppression, saisie d'un
 * congé pour un employé. Droits revérifiés à chaque action (voir `TimeoffAccess` et `TimeoffActions`).
 */
#[Title('Time off requests')]
class Manage extends Component
{
    use ResolvesViews;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $store = '';

    #[Url(except: 'desc')]
    public string $sort = 'desc';

    /** Demande désignée par une notification. */
    #[Url(except: 0)]
    public int $highlight = 0;

    #[Locked]
    public int $refusingId = 0;

    public string $note = '';

    #[Locked]
    public int $deletingId = 0;

    // Saisie d'un congé pour un employé.
    /** « identifiant de l'employé:identifiant du magasin » */
    public string $target = '';

    public string $newType = 'vacation';

    public string $newStart = '';

    public string $newEnd = '';

    public string $newReason = '';

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

    public function mount(): void
    {
        abort_unless($this->user()->can('timeoff.view'), 403);
    }

    private function find(int $id): TimeoffRequest
    {
        $request = TimeoffRequest::query()->find($id);
        abort_if($request === null || ! $this->access()->canView($this->user(), $request), 404);

        return $request;
    }

    public function approve(int $id, TimeoffActions $actions): void
    {
        $actions->decide($this->user(), $this->find($id), true);

        Flux::toast(variant: 'success', text: __('Request approved.'));
    }

    public function confirmRefusal(int $id): void
    {
        $this->refusingId = $this->find($id)->id;
        $this->note = '';
        Flux::modal('confirm-timeoff-refusal')->show();
    }

    public function refuse(TimeoffActions $actions): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $actions->decide($this->user(), $this->find($this->refusingId), false, $this->note);

        $this->reset('refusingId', 'note');
        Flux::modal('confirm-timeoff-refusal')->close();
        Flux::toast(variant: 'success', text: __('Request refused.'));
    }

    public function confirmDeletion(int $id): void
    {
        $request = $this->find($id);
        abort_unless($this->access()->canDelete($this->user(), $request), 403);

        $this->deletingId = $request->id;
        Flux::modal('confirm-timeoff-deletion')->show();
    }

    public function delete(TimeoffActions $actions): void
    {
        $actions->delete($this->user(), $this->find($this->deletingId));

        $this->reset('deletingId');
        Flux::modal('confirm-timeoff-deletion')->close();
        Flux::toast(variant: 'success', text: __('The leave has been deleted.'));
    }

    public function create(TimeoffActions $actions): void
    {
        $validated = $this->validate([
            'target' => ['required', 'regex:/^\d+:\d+$/'],
            'newType' => ['required', Rule::in(TimeoffRequest::TYPES)],
            'newStart' => ['required', 'date_format:Y-m-d'],
            'newEnd' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:newStart'],
            'newReason' => ['nullable', 'string', 'max:2000'],
        ]);

        [$userId, $storeId] = array_map('intval', explode(':', $validated['target']));
        $employee = User::query()->find($userId);
        abort_if($employee === null, 404);

        $actions->createFor(
            $this->user(), $employee, $storeId, $validated['newType'], $validated['newStart'],
            $validated['newEnd'] ?: $validated['newStart'], $validated['newReason'] ?? null,
        );

        $this->reset('target', 'newStart', 'newEnd', 'newReason');
        Flux::modal('create-timeoff')->close();
        Flux::toast(variant: 'success', text: __('The leave has been created and added to the schedule.'));
    }

    /**
     * Employés pour lesquels le gérant peut saisir un congé : « employé (magasin) ».
     *
     * @return array<string, string> « employé:magasin » => libellé
     */
    private function targets(): array
    {
        $stores = $this->access()->storesWith($this->user(), 'timeoff.create');
        $targets = [];

        $memberships = StoreMembership::query()->whereIn('store_id', array_keys($stores))->where('is_active', true)->get(['user_id', 'store_id']);
        $users = User::query()->whereIn('id', $memberships->pluck('user_id'))->where('is_active', true)->orderBy('name')->get(['id', 'name'])->keyBy('id');

        foreach ($users as $employee) {
            foreach ($memberships->where('user_id', $employee->id) as $membership) {
                $targets[$employee->id.':'.$membership->store_id] = $employee->name.' ('.$stores[$membership->store_id].')';
            }
        }

        return $targets;
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();

        $requests = $access->managed($user)
            ->with(['user:id,name', 'store:id,name', 'processor:id,name'])
            ->when(in_array($this->status, TimeoffRequest::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->type, TimeoffRequest::TYPES, true), fn ($q) => $q->where('type', $this->type))
            ->when(ctype_digit($this->store), fn ($q) => $q->where('store_id', (int) $this->store))
            ->orderBy('start_date', $this->sort === 'asc' ? 'asc' : 'desc')
            ->orderBy('id')
            ->get();

        // Le gérant voit les demandes : les notifications « à traiter » correspondantes sont lues.
        app(Notifier::class)->markRead($user, 'timeoff', $requests->modelKeys());

        return app('view')->make($this->viewName('manage'), [
            'requests' => $requests,
            'access' => $access,
            'user' => $user,
            'stores' => $access->storesWith($user, 'timeoff.view'),
            'types' => TimeoffRequest::TYPES,
            'statuses' => TimeoffRequest::STATUSES,
            'targets' => $this->targets(),
            'canCreate' => $this->targets() !== [],
        ]);
    }
}
