<?php

use App\Actions\Shifts\DeleteShifts;
use App\Actions\Shifts\RestoreShift;
use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Planning\ShiftWageCalculator;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shifts')] class extends Component {
    /** Mois "Y-m" */
    public string $month = '';

    /** Vide = tous les magasins visibles */
    public string $store_id = '';

    /** Vide = tous, "open" = shifts ouverts, sinon identifiant d'employé */
    public string $user_id = '';

    /** Inclut les shifts supprimés (corbeille) */
    public bool $showDeleted = false;

    /** @var list<string> */
    public array $selected = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('shifts.view'), 403);

        $this->month = now()->format('Y-m');
        $this->store_id = (string) request()->query('store_id', '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['month', 'store_id', 'user_id', 'showDeleted'], true)) {
            $this->selected = [];
        }
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');
        $this->selected = [];
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');
        $this->selected = [];
    }

    public function selectAll(): void
    {
        $this->selected = $this->deletableShiftIds();
    }

    public function confirmDeletion(): void
    {
        $this->resetErrorBag();

        if ($this->selected === []) {
            return;
        }

        Flux::modal('confirm-shifts-deletion')->show();
    }

    public function deleteSelected(): void
    {
        $this->resetErrorBag();

        $ids = array_values(array_map('intval', $this->selected));
        $count = app(DeleteShifts::class)->handle(Auth::user(), $ids);

        $this->selected = [];
        Flux::modal('confirm-shifts-deletion')->close();
        Flux::toast(variant: 'success', text: __(':count shift(s) deleted.', ['count' => $count]));
    }

    public function restore(int $shiftId): void
    {
        $this->resetErrorBag();

        app(RestoreShift::class)->handle(Auth::user(), Shift::onlyTrashed()->findOrFail($shiftId));

        Flux::toast(variant: 'success', text: __('Shift restored.'));
    }

    private function monthStart(): Carbon
    {
        return preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 && checkdate((int) substr($this->month, 5, 2), 1, (int) substr($this->month, 0, 4))
            ? Carbon::parse($this->month.'-01')
            : now()->startOfMonth();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Store>
     */
    private function viewableStores(): \Illuminate\Support\Collection
    {
        $viewable = app(Authorizer::class)->storeIdsFor(Auth::user(), 'shifts.view');

        return Store::query()
            ->when($viewable !== null, fn ($query) => $query->whereIn('id', $viewable))
            ->orderBy('name')
            ->get();
    }

    /**
     * Shifts de la liste filtrée (corbeille incluse si demandé).
     *
     * @param  \Illuminate\Support\Collection<int, Store>  $stores
     * @return \Illuminate\Database\Eloquent\Collection<int, Shift>
     */
    private function shifts(\Illuminate\Support\Collection $stores): \Illuminate\Database\Eloquent\Collection
    {
        $month = $this->monthStart();
        $storeFilter = $this->store_id !== '' && $stores->contains('id', (int) $this->store_id) ? (int) $this->store_id : null;

        return Shift::query()
            ->with(['store', 'user', 'shiftType'])
            ->when($this->showDeleted, fn ($query) => $query->withTrashed())
            ->whereIn('store_id', $stores->pluck('id')->all())
            ->when($storeFilter !== null, fn ($query) => $query->where('store_id', $storeFilter))
            ->when($this->user_id === 'open', fn ($query) => $query->whereNull('user_id'))
            ->when(ctype_digit($this->user_id), fn ($query) => $query->where('user_id', (int) $this->user_id))
            ->between($month->copy()->startOfMonth(), $month->copy()->endOfMonth())
            ->orderBy('shift_date')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function deletableShiftIds(): array
    {
        $authorizer = app(Authorizer::class);
        $actor = Auth::user();

        return $this->shifts($this->viewableStores())
            ->filter(fn (Shift $shift): bool => ! $shift->trashed() && $authorizer->allows($actor, 'shifts.delete', $shift->store_id))
            ->map(fn (Shift $shift): string => (string) $shift->id)
            ->values()
            ->all();
    }

    public function with(): array
    {
        abort_unless(Gate::allows('shifts.view'), 403);

        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $calculator = app(ShiftWageCalculator::class);
        $stores = $this->viewableStores();
        $storeFilter = $this->store_id !== '' && $stores->contains('id', (int) $this->store_id) ? (int) $this->store_id : null;
        $shifts = $this->shifts($stores);

        $payrollStores = $stores->filter(fn (Store $store): bool => $authorizer->allows($actor, 'payroll.view', $store))->keyBy('id');
        $deletable = $stores->filter(fn (Store $store): bool => $authorizer->allows($actor, 'shifts.delete', $store))->pluck('id')->all();
        $editable = $stores->filter(fn (Store $store): bool => $authorizer->allows($actor, 'shifts.update', $store))->pluck('id')->all();

        $employees = User::query()
            ->whereHas('stores', fn ($query) => $query->whereIn('stores.id', $storeFilter !== null ? [$storeFilter] : $stores->pluck('id')->all()))
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'stores' => $stores,
            'employees' => $employees,
            'shifts' => $shifts,
            'showCost' => $payrollStores->isNotEmpty(),
            'costOf' => function (Shift $shift) use ($payrollStores, $calculator): ?string {
                $store = $payrollStores->get($shift->store_id);

                return $store === null ? null : Money::forStore($calculator->forShift($shift)->total, $store);
            },
            'canDelete' => fn (Shift $shift): bool => ! $shift->trashed() && in_array($shift->store_id, $deletable, true),
            'canRestore' => fn (Shift $shift): bool => $shift->trashed() && $shift->replaced_by_id === null && in_array($shift->store_id, $deletable, true),
            'canEdit' => fn (Shift $shift): bool => in_array($shift->store_id, $editable, true),
            'hasDeletable' => $shifts->contains(fn (Shift $shift): bool => ! $shift->trashed() && in_array($shift->store_id, $deletable, true)),
            'canCreate' => Gate::allows('shifts.create'),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Shifts') }}</flux:heading>
            <flux:subheading>{{ __('Planned shifts of your stores') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('shifts.calendar')" wire:navigate icon="calendar-days" data-test="calendar-link">{{ __('Calendar') }}</flux:button>
            <flux:button :href="route('shifts.timeline')" wire:navigate icon="chart-bar" data-test="timeline-link">{{ __('Timeline') }}</flux:button>
            @if ($canCreate)
                <flux:button variant="primary" icon="plus" :href="route('shifts.create')" wire:navigate data-test="new-shift-button">{{ __('New shift') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="flex items-end gap-1">
            <flux:button wire:click="previousMonth" icon="chevron-left" variant="ghost" :aria-label="__('Previous month')" />
            <div class="flex-1"><flux:input wire:model.live="month" :label="__('Month')" type="month" /></div>
            <flux:button wire:click="nextMonth" icon="chevron-right" variant="ghost" :aria-label="__('Next month')" />
        </div>
        <flux:select wire:model.live="store_id" :label="__('Store')">
            <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
            @foreach ($stores as $store)
                <flux:select.option value="{{ $store->id }}">{{ $store->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="user_id" :label="__('Employee')">
            <flux:select.option value="">{{ __('All employees') }}</flux:select.option>
            <flux:select.option value="open">{{ __('Open shifts') }}</flux:select.option>
            @foreach ($employees as $employee)
                <flux:select.option value="{{ $employee->id }}">{{ $employee->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="flex items-end pb-2">
            <flux:checkbox wire:model.live="showDeleted" :label="__('Show deleted shifts')" data-test="show-deleted-toggle" />
        </div>
    </div>

    @error('shift') <flux:text class="text-red-500" data-test="shift-error">{{ $message }}</flux:text> @enderror

    @if ($hasDeletable)
        <div class="flex items-center gap-3">
            <flux:button size="sm" wire:click="selectAll" data-test="select-all-button">{{ __('Select all') }}</flux:button>
            <flux:button size="sm" variant="danger" wire:click="confirmDeletion" :disabled="$selected === []" data-test="bulk-delete-button">
                {{ __('Delete the selection') }} ({{ count($selected) }})
            </flux:button>
        </div>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column></flux:table.column>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('Hours') }}</flux:table.column>
            <flux:table.column>{{ __('Employee') }}</flux:table.column>
            <flux:table.column>{{ __('Store') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Break') }}</flux:table.column>
            <flux:table.column>{{ __('Paid minutes') }}</flux:table.column>
            @if ($showCost)
                <flux:table.column>{{ __('Cost') }}</flux:table.column>
            @endif
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($shifts as $shift)
                <flux:table.row :key="$shift->id" data-test="shift-row">
                    <flux:table.cell>
                        @if ($canDelete($shift))
                            <flux:checkbox wire:model.live="selected" value="{{ $shift->id }}" data-test="select-shift" />
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $shift->shift_date->toDateString() }}</flux:table.cell>
                    <flux:table.cell data-test="shift-hours">
                        {{ $shift->localStartsAt->format('H:i') }} - {{ $shift->localEndsAt->format('H:i') }}{{ $shift->crossesMidnight ? ' (+1)' : '' }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $shift->user?->name ?? __('Open shift') }}</flux:table.cell>
                    <flux:table.cell>{{ $shift->store->name }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($shift->shiftType)
                            <span class="me-1 inline-block size-3 rounded-full align-middle" style="background-color: {{ $shift->shiftType->color }}"></span>{{ $shift->shiftType->name }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $shift->pause_minutes }} min</flux:table.cell>
                    <flux:table.cell>{{ $shift->netMinutes }} min</flux:table.cell>
                    @if ($showCost)
                        <flux:table.cell data-test="shift-cost">{{ $costOf($shift) }}</flux:table.cell>
                    @endif
                    <flux:table.cell>
                        @if ($shift->trashed())
                            <flux:badge size="sm" color="red" inset="top bottom">{{ __('Deleted') }}</flux:badge>
                            @if ($canRestore($shift))
                                <flux:button size="sm" wire:click="restore({{ $shift->id }})" data-test="restore-shift-button">{{ __('Restore') }}</flux:button>
                            @endif
                        @elseif ($canEdit($shift))
                            <flux:button size="sm" :href="route('shifts.edit', $shift)" wire:navigate data-test="edit-shift-button">{{ __('Edit') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="{{ $showCost ? 10 : 9 }}">{{ __('No shift found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="confirm-shifts-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete the selected shifts?') }}</flux:heading>
                <flux:subheading>{{ __('The shifts go to the trash and can be restored.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteSelected" data-test="confirm-bulk-delete-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
