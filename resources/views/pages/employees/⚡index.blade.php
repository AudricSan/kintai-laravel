<?php

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Kintai\Contracts\Employees\EmployeeActionRegistry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Employees')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** Identifiant de magasin, `none` (sans magasin) ou vide (tous). */
    #[Url(except: '')]
    public string $store = '';

    #[Url(except: 'name')]
    public string $sortBy = 'name';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    public function mount(): void
    {
        abort_unless(Gate::allows('employees.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStore(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ['name', 'email', 'status'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Magasins de l'acteur pour employees.view ; null = tous.
     *
     * @return list<int>|null
     */
    private function actorStoreIds(): ?array
    {
        return app(Authorizer::class)->storeIdsFor(Auth::user(), 'employees.view');
    }

    /**
     * Magasins proposés dans le filtre.
     *
     * @return \Illuminate\Support\Collection<int, Store>
     */
    #[Computed]
    public function stores()
    {
        $ids = $this->actorStoreIds();

        return Store::query()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get();
    }

    public function with(): array
    {
        abort_unless(Gate::allows('employees.view'), 403);

        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $ids = $this->actorStoreIds();

        $query = User::query()->with(['stores:id,name']);

        // Sans restriction : tous les comptes (y compris sans magasin) ; sinon, les membres des magasins de l'acteur.
        if ($ids !== null) {
            $query->whereHas('stores', fn (Builder $q) => $q->whereIn('stores.id', $ids));
        }

        if ($this->store === 'none' && $ids === null) {
            $query->whereDoesntHave('stores');
        } elseif (ctype_digit($this->store) && $this->stores->contains('id', (int) $this->store)) {
            $query->whereHas('stores', fn (Builder $q) => $q->where('stores.id', (int) $this->store));
        }

        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $q) use ($like): void {
                foreach (['name', 'first_name', 'last_name', 'furigana_first_name', 'furigana_last_name', 'email', 'employee_code'] as $column) {
                    $q->orWhereRaw("{$column} like ? escape '!'", [$like]);
                }
            });
        }

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';
        match ($this->sortBy) {
            'email' => $query->orderBy('email', $direction),
            'status' => $query->orderBy('is_active', $direction),
            default => $query->orderBy('name', $direction),
        };
        $query->orderBy('id');

        $employees = $query->paginate(25);

        $ownerIds = $authorizer->ownerIds();

        return [
            'employees' => $employees,
            'ownerIds' => $ownerIds,
            'canCreate' => $authorizer->allows($actor, 'employees.create'),
            'unrestricted' => $ids === null,
            'actions' => $employees->getCollection()
                ->mapWithKeys(fn (User $employee): array => [$employee->id => app(EmployeeActionRegistry::class)->actionsFor($actor, $employee->id)])->all(),
            'editableIds' => $employees->getCollection()
                ->filter(fn (User $employee): bool => $authorizer->canActOnUser($actor, 'employees.view', $employee))
                ->pluck('id')->all(),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Employees') }}</flux:heading>
            <flux:subheading>{{ __('Accounts of the stores you manage') }}</flux:subheading>
        </div>
        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('employees.create')" wire:navigate data-test="new-employee-button">
                {{ __('New employee') }}
            </flux:button>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        <div class="min-w-64 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name, email or employee code')" clearable />
        </div>
        <flux:select wire:model.live="store" class="max-w-xs">
            <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
            @if ($unrestricted)
                <flux:select.option value="none">{{ __('No store') }}</flux:select.option>
            @endif
            @foreach ($this->stores as $storeOption)
                <flux:select.option :value="$storeOption->id">{{ $storeOption->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$employees">
        <flux:table.columns>
            <flux:table.column></flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Employee code') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'email'" :direction="$sortDirection" wire:click="sort('email')">{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Stores') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($employees as $employee)
                <flux:table.row :key="$employee->id" data-test="employee-row">
                    <flux:table.cell><x-user-avatar :user="$employee" size="sm" /></flux:table.cell>
                    <flux:table.cell>
                        @if (in_array($employee->id, $editableIds, true))
                            <flux:link :href="route('employees.edit', $employee)" wire:navigate>{{ $employee->name }}</flux:link>
                        @else
                            {{ $employee->name }}
                        @endif
                        @if (in_array($employee->id, $ownerIds, true))
                            <flux:badge size="sm" color="red" inset="top bottom">{{ __('Owner') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $employee->employee_code }}</flux:table.cell>
                    <flux:table.cell>{{ $employee->email }}</flux:table.cell>
                    <flux:table.cell>{{ $employee->stores->pluck('name')->join(', ') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$employee->is_active ? 'green' : 'zinc'" inset="top bottom">
                            {{ $employee->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @foreach ($actions[$employee->id] ?? [] as $action)
                            <flux:button size="sm" variant="filled" :icon="$action->icon" :href="$action->url($employee->id)" wire:navigate data-test="employee-action-{{ $action->key }}">{{ __($action->label) }}</flux:button>
                        @endforeach
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">{{ __('No employee found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
