<?php

use App\Dashboard\DashboardScope;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function with(): array
    {
        return [
            'shifts' => Shift::query()
                ->with(['store:id,name', 'shiftType'])
                ->where('user_id', Auth::id())
                ->where('shift_date', app(DashboardScope::class)->today()->toDateString())
                ->orderBy('starts_at')
                ->get(),
        ];
    }
}; ?>

<x-dashboard.card :title="__('My shifts today')" data-test="widget-my-shifts-today">
    @forelse ($shifts as $shift)
        <div class="mb-2 flex justify-between gap-2 text-sm" wire:key="my-today-{{ $shift->id }}" data-test="my-today-shift">
            <span>{{ $shift->store->name }}@if ($shift->shiftType) - {{ $shift->shiftType->name }}@endif</span>
            <span>{{ $shift->localStartsAt->format('H:i') }} - {{ $shift->localEndsAt->format('H:i') }}{{ $shift->crossesMidnight ? ' (+1)' : '' }}</span>
        </div>
    @empty
        <flux:text size="sm" data-test="no-shift-today">{{ __('No shift today.') }}</flux:text>
    @endforelse
</x-dashboard.card>
