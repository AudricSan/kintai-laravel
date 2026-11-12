<?php

use App\Models\Shift;
use App\Planning\WeekRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My calendar')] class extends Component {
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
        $user = Auth::user();
        $month = $this->monthStart();

        // Début de semaine du premier magasin de l'employé, comme l'ancienne version.
        $weekStart = $user->stores()->orderBy('stores.id')->value('stores.week_start_day') ?? 1;
        $weeks = WeekRange::monthGrid($month, (int) $weekStart);

        $shiftsByDate = Shift::query()
            ->with(['store:id,name', 'shiftType'])
            ->where('user_id', $user->id)
            ->between($weeks[0][0], $weeks[count($weeks) - 1][6])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Shift $shift): string => $shift->shift_date->toDateString())
            ->all();

        return [
            'weeks' => $weeks,
            'shiftsByDate' => $shiftsByDate,
            'monthLabel' => $month->translatedFormat('F Y'),
            'monthNumber' => $month->month,
            'today' => now()->toDateString(),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('My calendar') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('my-shifts.index')" wire:navigate icon="list-bullet">{{ __('My shifts') }}</flux:button>
            <flux:button :href="route('my-shifts.week')" wire:navigate>{{ __('Week') }}</flux:button>
        </div>
    </div>

    <div class="flex items-center gap-1">
        <flux:button wire:click="previousMonth" icon="chevron-left" variant="ghost" :aria-label="__('Previous month')" />
        <strong class="min-w-40 text-center" data-test="month-label">{{ $monthLabel }}</strong>
        <flux:button wire:click="nextMonth" icon="chevron-right" variant="ghost" :aria-label="__('Next month')" />
    </div>

    <div class="overflow-x-auto">
        <div class="grid min-w-[40rem] grid-cols-7 gap-px rounded-lg border border-zinc-200 bg-zinc-200 dark:border-zinc-700 dark:bg-zinc-700" data-test="calendar-grid">
            @foreach ($weeks[0] as $headerDay)
                <div class="bg-zinc-100 p-2 text-center text-sm font-semibold dark:bg-zinc-800" data-test="weekday-header">{{ $headerDay->translatedFormat('D') }}</div>
            @endforeach
            @foreach ($weeks as $week)
                @foreach ($week as $day)
                    @php($dateKey = $day->toDateString())
                    <div class="min-h-24 space-y-1 bg-white p-1.5 dark:bg-zinc-900 {{ $day->month !== $monthNumber ? 'opacity-50' : '' }}" data-test="calendar-day" data-date="{{ $dateKey }}" wire:key="day-{{ $dateKey }}">
                        <a href="{{ route('my-shifts.day', ['date' => $dateKey]) }}" wire:navigate class="text-xs {{ $dateKey === $today ? 'font-bold underline' : 'font-semibold' }}">{{ $day->day }}</a>
                        @foreach ($shiftsByDate[$dateKey] ?? [] as $shift)
                            <div class="truncate rounded px-1.5 py-0.5 text-xs text-white" style="background-color: {{ $shift->shiftType->color ?? '#71717a' }}" data-test="calendar-shift">{{ $shift->localStartsAt->format('H:i') }}-{{ $shift->localEndsAt->format('H:i') }} {{ $shift->store->name }}</div>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
</section>
