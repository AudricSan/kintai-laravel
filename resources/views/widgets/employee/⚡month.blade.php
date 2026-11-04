<?php

use App\Dashboard\DashboardScope;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function with(): array
    {
        $today = app(DashboardScope::class)->today();

        $shifts = Shift::query()
            ->where('user_id', Auth::id())
            ->between($today->startOfMonth(), $today->endOfMonth())
            ->get();

        $now = now();

        return [
            'month' => $today->translatedFormat('F Y'),
            'count' => $shifts->count(),
            'plannedMinutes' => $shifts->sum(fn (Shift $shift): int => $shift->netMinutes),
            'workedMinutes' => $shifts->filter(fn (Shift $shift): bool => $shift->ends_at->lessThanOrEqualTo($now))->sum(fn (Shift $shift): int => $shift->netMinutes),
        ];
    }
}; ?>

<x-dashboard.card :title="__('My month')" data-test="widget-my-month">
    <flux:text size="sm" class="mb-3">{{ $month }}</flux:text>
    <div class="grid grid-cols-3 gap-4">
        <div data-test="month-planned">
            <div class="text-2xl font-semibold">{{ number_format($plannedMinutes / 60, 1) }} h</div>
            <flux:text size="sm">{{ __('Planned hours') }}</flux:text>
        </div>
        <div data-test="month-worked">
            <div class="text-2xl font-semibold">{{ number_format($workedMinutes / 60, 1) }} h</div>
            <flux:text size="sm">{{ __('Completed hours') }}</flux:text>
        </div>
        <div data-test="month-count">
            <div class="text-2xl font-semibold">{{ $count }}</div>
            <flux:text size="sm">{{ __('Shifts') }}</flux:text>
        </div>
    </div>
</x-dashboard.card>
