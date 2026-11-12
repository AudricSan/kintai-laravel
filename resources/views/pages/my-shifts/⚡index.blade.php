<?php

use App\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My shifts')] class extends Component {
    /** Mois "Y-m" */
    public string $month = '';

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');
    }

    private function monthStart(): Carbon
    {
        return preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 && checkdate((int) substr($this->month, 5, 2), 1, (int) substr($this->month, 0, 4))
            ? Carbon::parse($this->month.'-01')
            : now()->startOfMonth();
    }

    public function with(): array
    {
        $month = $this->monthStart();

        // Uniquement les shifts de l'utilisateur connecté, sans coût.
        $shifts = Shift::query()
            ->with(['store:id,name', 'shiftType'])
            ->where('user_id', Auth::id())
            ->between($month->copy()->startOfMonth(), $month->copy()->endOfMonth())
            ->orderBy('starts_at')
            ->get();

        $now = now();

        return [
            'monthLabel' => $month->translatedFormat('F Y'),
            'upcoming' => $shifts->filter(fn (Shift $shift): bool => $shift->ends_at->greaterThan($now))->values(),
            'past' => $shifts->filter(fn (Shift $shift): bool => $shift->ends_at->lessThanOrEqualTo($now))->sortByDesc('starts_at')->values(),
        ];
    }
}; ?>

<section class="w-full max-w-4xl space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('My shifts') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('my-shifts.calendar')" wire:navigate icon="calendar-days">{{ __('Calendar') }}</flux:button>
            <flux:button :href="route('my-shifts.week')" wire:navigate>{{ __('Week') }}</flux:button>
            <flux:button :href="route('my-shifts.day')" wire:navigate>{{ __('Day') }}</flux:button>
        </div>
    </div>

    <div class="flex items-center gap-1">
        <flux:button wire:click="previousMonth" icon="chevron-left" variant="ghost" :aria-label="__('Previous month')" />
        <strong class="min-w-40 text-center" data-test="month-label">{{ $monthLabel }}</strong>
        <flux:button wire:click="nextMonth" icon="chevron-right" variant="ghost" :aria-label="__('Next month')" />
    </div>

    @foreach (['upcoming' => [__('Upcoming'), $upcoming], 'past' => [__('Past'), $past]] as $key => $section)
        <div class="space-y-2" data-test="{{ $key }}-section">
            <flux:heading size="lg">{{ $section[0] }}</flux:heading>
            @forelse ($section[1] as $shift)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-test="my-shift-row" wire:key="{{ $key }}-{{ $shift->id }}">
                    <div>
                        <div class="font-semibold">{{ $shift->shift_date->translatedFormat('l d M Y') }}</div>
                        <div class="text-sm">{{ $shift->localStartsAt->format('H:i') }} - {{ $shift->localEndsAt->format('H:i') }}{{ $shift->crossesMidnight ? ' (+1)' : '' }} - {{ $shift->store->name }}</div>
                    </div>
                    <div class="text-sm">
                        @if ($shift->shiftType)
                            <span class="me-1 inline-block size-3 rounded-full align-middle" style="background-color: {{ $shift->shiftType->color }}"></span>{{ $shift->shiftType->name }} -
                        @endif
                        {{ $shift->netMinutes }} min
                        <a href="{{ route('my-shifts.day', ['date' => $shift->shift_date->toDateString()]) }}" wire:navigate class="ms-2 underline">{{ __('Day') }}</a>
                    </div>
                </div>
            @empty
                <flux:text>{{ __('No shift found.') }}</flux:text>
            @endforelse
        </div>
    @endforeach
</section>
