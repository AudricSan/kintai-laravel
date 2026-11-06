<?php

namespace Kintai\Bundles\Timeclock\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Bundles\Timeclock\OvertimeTracker;
use Kintai\Bundles\Timeclock\TimeclockAccess;
use Kintai\Bundles\Timeclock\TimeclockActions;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pointages des magasins du gérant : filtres par jour et magasin, correction des heures, suppression (après confirmation).
 * Droits revérifiés à chaque action (voir `TimeclockAccess` et `TimeclockActions`).
 */
#[Title('Time clock entries')]
class Manage extends Component
{
    use ResolvesViews;
    use WithPagination;

    /** Jour « AAAA-MM-JJ » ; vide = tous. */
    #[Url(except: '')]
    public string $date = '';

    #[Url(except: '')]
    public string $store = '';

    #[Locked]
    public int $editingId = 0;

    /** Heures en `AAAA-MM-JJTHH:MM`, fuseau de l'instance. */
    public string $editIn = '';

    public string $editOut = '';

    public string $editNotes = '';

    #[Locked]
    public int $deletingId = 0;

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): TimeclockAccess
    {
        return app(TimeclockAccess::class);
    }

    public function mount(): void
    {
        abort_unless($this->user()->can('timeclock.view'), 403);
    }

    public function updatedDate(): void
    {
        $this->resetPage();
    }

    public function updatedStore(): void
    {
        $this->resetPage();
    }

    private function find(int $id): Timeclock
    {
        $entry = Timeclock::query()->find($id);
        abort_if($entry === null || ! $this->access()->canView($this->user(), $entry), 404);

        return $entry;
    }

    public function startEditing(int $id): void
    {
        $entry = $this->find($id);
        abort_unless($this->access()->canUpdate($this->user(), $entry), 403);

        $this->editingId = $entry->id;
        $this->editIn = $entry->localIn()->format('Y-m-d\TH:i');
        $this->editOut = $entry->localOut()?->format('Y-m-d\TH:i') ?? '';
        $this->editNotes = (string) $entry->notes;
        Flux::modal('edit-timeclock')->show();
    }

    public function saveEditing(TimeclockActions $actions): void
    {
        $validated = $this->validate([
            'editIn' => ['required', 'date_format:Y-m-d\TH:i'],
            'editOut' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'editNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $tz = Timeclock::timezone();
        $actions->update(
            $this->user(), $this->find($this->editingId),
            Carbon::parse($validated['editIn'], $tz)->utc(),
            $validated['editOut'] ? Carbon::parse($validated['editOut'], $tz)->utc() : null,
            $validated['editNotes'] ?? null,
        );

        $this->reset('editingId', 'editIn', 'editOut', 'editNotes');
        Flux::modal('edit-timeclock')->close();
        Flux::toast(variant: 'success', text: __('Time clock entry updated.'));
    }

    public function confirmDeletion(int $id): void
    {
        $entry = $this->find($id);
        abort_unless($this->access()->canDelete($this->user(), $entry), 403);

        $this->deletingId = $entry->id;
        Flux::modal('confirm-timeclock-deletion')->show();
    }

    public function delete(TimeclockActions $actions): void
    {
        $actions->delete($this->user(), $this->find($this->deletingId));

        $this->reset('deletingId');
        Flux::modal('confirm-timeclock-deletion')->close();
        Flux::toast(variant: 'success', text: __('Time clock entry deleted.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $access = $this->access();

        $entries = $access->managed($user)
            ->with(['user:id,name', 'store:id,name', 'shift'])
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1, fn ($q) => $q->whereDate('shift_date', $this->date))
            ->when(ctype_digit($this->store), fn ($q) => $q->where('store_id', (int) $this->store))
            ->orderByDesc('clock_in_at')
            ->orderByDesc('id')
            ->paginate(20);

        return app('view')->make($this->viewName('manage'), [
            'entries' => $entries,
            'access' => $access,
            'user' => $user,
            'stores' => $access->storesWith($user, 'timeclock.view'),
            'threshold' => $access->lateThreshold(),
            'overtime' => app(OvertimeTracker::class),
            'isOwner' => $access->isOwner($user),
        ]);
    }
}
