<?php

use App\Actions\ShiftTypes\DeleteShiftType;
use App\Actions\ShiftTypes\SaveShiftType;
use App\Actions\ShiftTypes\ToggleShiftTypeStore;
use App\Authorization\Authorizer;
use App\Models\ShiftType;
use App\Models\Store;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shift types')] class extends Component {
    #[Locked]
    public int $deletionId = 0;

    public function mount(): void
    {
        abort_unless(Gate::allows('shifts.view'), 403);
    }

    public function toggleStore(int $shiftTypeId, int $storeId, ToggleShiftTypeStore $toggle): void
    {
        $this->resetErrorBag();

        $toggle->handle(Auth::user(), ShiftType::query()->findOrFail($shiftTypeId), Store::query()->findOrFail($storeId));
    }

    public function confirmDeletion(int $shiftTypeId): void
    {
        $this->resetErrorBag();
        $this->deletionId = $shiftTypeId;

        Flux::modal('confirm-shift-type-deletion')->show();
    }

    public function delete(DeleteShiftType $deleteShiftType): void
    {
        $this->resetErrorBag();

        $deleteShiftType->handle(Auth::user(), ShiftType::query()->findOrFail($this->deletionId));

        $this->deletionId = 0;
        Flux::modal('confirm-shift-type-deletion')->close();
        Flux::toast(variant: 'success', text: __('Shift type deleted.'));
    }

    public function with(): array
    {
        abort_unless(Gate::allows('shifts.view'), 403);

        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $viewable = $authorizer->storeIdsFor($actor, 'shifts.view');
        $manageable = $authorizer->storeIdsFor($actor, 'shifts.update');
        $canUpdate = $authorizer->allows($actor, 'shifts.update');
        $saveShiftType = app(SaveShiftType::class);

        $types = ShiftType::query()
            ->with('stores:id,name')
            ->when($viewable !== null, fn ($query) => $query->whereHas('stores', fn ($q) => $q->whereIn('stores.id', $viewable)))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'types' => $types,
            'canUpdate' => $canUpdate,
            // Magasins pour lesquels l'acteur peut activer ou désactiver un type.
            'toggleStores' => $canUpdate
                ? Store::query()->when($manageable !== null, fn ($query) => $query->whereIn('id', $manageable))->orderBy('name')->get(['id', 'name'])
                : collect(),
            'lockedIds' => $types->reject(fn (ShiftType $type): bool => $saveShiftType->canManageType($actor, $type))->modelKeys(),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Shift types') }}</flux:heading>
            <flux:subheading>{{ __('Hours and rates of the shifts offered in your stores') }}</flux:subheading>
        </div>
        @if ($canUpdate)
            <flux:button variant="primary" icon="plus" :href="route('shift-types.create')" wire:navigate data-test="new-shift-type-button">
                {{ __('New shift type') }}
            </flux:button>
        @endif
    </div>

    @error('store')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="store-toggle-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Hours') }}</flux:table.column>
            <flux:table.column>{{ __('Hourly rate') }}</flux:table.column>
            <flux:table.column>{{ __('Stores') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($types as $type)
                <flux:table.row :key="$type->id" data-test="shift-type-row">
                    <flux:table.cell>
                        <span class="me-2 inline-block size-3 rounded-full align-middle" style="background-color: {{ $type->color }}"></span>
                        {{ $type->code }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $type->name }}</flux:table.cell>
                    <flux:table.cell>{{ $type->starts_at }} – {{ $type->ends_at }}</flux:table.cell>
                    <flux:table.cell>{{ number_format((float) $type->hourly_rate, 2) }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate)
                            <div class="flex flex-wrap gap-1">
                                @foreach ($toggleStores as $toggleStore)
                                    @php($attached = $type->stores->contains('id', $toggleStore->id))
                                    <flux:button size="xs" :variant="$attached ? 'primary' : 'ghost'" wire:click="toggleStore({{ $type->id }}, {{ $toggleStore->id }})" wire:key="toggle-{{ $type->id }}-{{ $toggleStore->id }}" data-test="toggle-store-button">
                                        {{ $toggleStore->name }}
                                    </flux:button>
                                @endforeach
                            </div>
                        @else
                            {{ $type->stores->pluck('name')->join(', ') }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$type->is_active ? 'green' : 'zinc'" inset="top bottom">
                            {{ $type->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate && ! in_array($type->id, $lockedIds, true))
                            <flux:button size="sm" :href="route('shift-types.edit', $type)" wire:navigate data-test="edit-shift-type-button">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="confirmDeletion({{ $type->id }})" data-test="delete-shift-type-button">{{ __('Delete') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">{{ __('No shift type found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="confirm-shift-type-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this shift type?') }}</flux:heading>
                <flux:subheading>{{ __('A shift type used by shifts cannot be deleted: deactivate it instead.') }}</flux:subheading>
            </div>

            @error('delete') <flux:text class="text-red-500" data-test="delete-error">{{ $message }}</flux:text> @enderror

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-shift-type-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
