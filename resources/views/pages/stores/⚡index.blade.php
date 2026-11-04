<?php

use App\Authorization\Authorizer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Stores')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'name')]
    public string $sortBy = 'name';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    public function mount(): void
    {
        abort_unless(Gate::allows('stores.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ['name', 'code', 'status'], true)) {
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

    public function with(): array
    {
        abort_unless(Gate::allows('stores.view'), 403);

        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $ids = $authorizer->storeIdsFor($actor, 'stores.view');

        $query = Store::query()
            ->withCount('members')
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('stores.id', $ids));

        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->whereRaw("name like ? escape '!'", [$like])->orWhereRaw("code like ? escape '!'", [$like]);
            });
        }

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';
        match ($this->sortBy) {
            'code' => $query->orderBy('code', $direction),
            'status' => $query->orderBy('is_active', $direction),
            default => $query->orderBy('name', $direction),
        };
        $query->orderBy('code')->orderBy('id');

        return [
            'stores' => $query->paginate(25),
            'canCreate' => $authorizer->hasUnrestricted($actor, 'stores.create'),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Stores') }}</flux:heading>
            <flux:subheading>{{ __('Stores you can access') }}</flux:subheading>
        </div>
        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('stores.create')" wire:navigate data-test="new-store-button">
                {{ __('New store') }}
            </flux:button>
        @endif
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or code')" clearable />

    <flux:table :paginate="$stores">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sortBy === 'code'" :direction="$sortDirection" wire:click="sort('code')">{{ __('Code') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Members') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">{{ __('Status') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($stores as $store)
                <flux:table.row :key="$store->id" data-test="store-row">
                    <flux:table.cell>{{ $store->code }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:link :href="route('stores.edit', $store)" wire:navigate>{{ $store->name }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $store->members_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$store->is_active ? 'green' : 'zinc'" inset="top bottom">
                            {{ $store->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">{{ __('No store found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
