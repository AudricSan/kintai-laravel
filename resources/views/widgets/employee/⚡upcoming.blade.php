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
                ->where('shift_date', '>', app(DashboardScope::class)->today()->toDateString())
                ->orderBy('starts_at')
                ->limit(5)
                ->get(),
        ];
    }
}; ?>

<x-dashboard.card :title="__('My upcoming shifts')" data-test="widget-my-upcoming">
    @forelse ($shifts as $shift)
        <div class="mb-2 flex justify-between gap-2 text-sm" wire:key="my-upcoming-{{ $shift->id }}" data-test="my-upcoming-shift">
            <span>{{ $shift->shift_date->translatedFormat('D d M') }} - {{ $shift->store->name }}</span>
            <span>{{ $shift->localStartsAt->format('H:i') }} - {{ $shift->localEndsAt->format('H:i') }}{{ $shift->crossesMidnight ? ' (+1)' : '' }}</span>
        </div>
    @empty
        <flux:text size="sm" data-test="no-upcoming">{{ __('No upcoming shift.') }}</flux:text>
    @endforelse

    <flux:button :href="route('my-shifts.index')" wire:navigate size="sm" variant="ghost" class="mt-2">{{ __('All my shifts') }}</flux:button>
</x-dashboard.card>
