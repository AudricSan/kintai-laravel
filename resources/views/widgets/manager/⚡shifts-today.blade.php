<?php

use App\Dashboard\DashboardScope;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function with(): array
    {
        $scope = app(DashboardScope::class);
        $stores = $scope->managedStores(Auth::user(), 'shifts.view')->filter(fn ($store): bool => $store->hasFeature('shifts'));

        $shifts = Shift::query()
            ->with(['user:id,name', 'shiftType'])
            ->whereIn('store_id', $stores->modelKeys())
            ->whereNull('replaced_by_id')
            ->where('shift_date', $scope->today()->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->groupBy('store_id');

        return ['stores' => $stores->filter(fn ($store): bool => $shifts->has($store->id))->values(), 'shifts' => $shifts];
    }
}; ?>

<x-dashboard.card :title="__('Today\'s shifts')" data-test="widget-shifts-today">
    @forelse ($stores as $store)
        <div class="mb-3" wire:key="today-store-{{ $store->id }}" data-test="today-store">
            <div class="mb-1 text-sm font-semibold">{{ $store->name }}</div>
            <ul class="space-y-1 text-sm">
                @foreach ($shifts[$store->id] as $shift)
                    <li class="flex justify-between gap-2" wire:key="today-shift-{{ $shift->id }}" data-test="today-shift">
                        <span>{{ $shift->user?->name ?? __('Open shift') }}</span>
                        <span>{{ $shift->localStartsAt->format('H:i') }} - {{ $shift->localEndsAt->format('H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <flux:text size="sm" data-test="no-shift-today">{{ __('No shift today.') }}</flux:text>
    @endforelse
</x-dashboard.card>
