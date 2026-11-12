<?php

use App\Models\Shift;
use App\Planning\WeekRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My week')] class extends Component {
    /** Un jour de la semaine affichée ("Y-m-d") */
    public string $date = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function previousWeek(): void
    {
        $this->date = $this->anchor()->subDays(7)->toDateString();
    }

    public function nextWeek(): void
    {
        $this->date = $this->anchor()->addDays(7)->toDateString();
    }

    private function anchor(): CarbonImmutable
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->date, $p) === 1 && checkdate((int) $p[2], (int) $p[3], (int) $p[1])
            ? CarbonImmutable::parse($this->date)->startOfDay()
            : CarbonImmutable::today();
    }

    public function with(): array
    {
        $user = Auth::user();
        $weekStart = (int) ($user->stores()->orderBy('stores.id')->value('stores.week_start_day') ?? 1);
        $first = WeekRange::firstDay($this->anchor(), $weekStart);
        $days = array_map(fn (int $i): CarbonImmutable => $first->addDays($i), range(0, 6));

        $shiftsByDate = Shift::query()
            ->with(['store:id,name', 'shiftType'])
            ->where('user_id', $user->id)
            ->between($days[0], $days[6])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Shift $shift): string => $shift->shift_date->toDateString())
            ->all();

        return ['days' => $days, 'shiftsByDate' => $shiftsByDate, 'today' => now()->toDateString()];
    }
}; ?>

<section class="w-full max-w-4xl space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('My week') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('my-shifts.index')" wire:navigate icon="list-bullet">{{ __('My shifts') }}</flux:button>
            <flux:button :href="route('my-shifts.calendar')" wire:navigate icon="calendar-days">{{ __('Calendar') }}</flux:button>
        </div>
    </div>

    <div class="flex items-center gap-1">
        <flux:button wire:click="previousWeek" icon="chevron-left" variant="ghost" :aria-label="__('Previous week')" />
        <strong class="min-w-48 text-center" data-test="week-label">{{ $days[0]->translatedFormat('d M') }} - {{ $days[6]->translatedFormat('d M Y') }}</strong>
        <flux:button wire:click="nextWeek" icon="chevron-right" variant="ghost" :aria-label="__('Next week')" />
    </div>

    <div class="space-y-2">
        @foreach ($days as $day)
            @php($dateKey = $day->toDateString())
            <div class="flex flex-wrap gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 {{ $dateKey === $today ? 'ring-2 ring-zinc-400' : '' }}" data-test="week-day" data-date="{{ $dateKey }}" wire:key="week-{{ $dateKey }}">
                <a href="{{ route('my-shifts.day', ['date' => $dateKey]) }}" wire:navigate class="w-36 font-semibold underline">{{ $day->translatedFormat('l d M') }}</a>
                <div class="space-y-1 text-sm">
                    @forelse ($shiftsByDate[$dateKey] ?? [] as $shift)
                        <div data-test="week-shift">
                            <span class="me-1 inline-block size-3 rounded-full align-middle" style="background-color: {{ $shift->shiftType->color ?? '#71717a' }}"></span>
                            {{ $shift->localStartsAt->format('H:i') }} - {{ $shift->localEndsAt->format('H:i') }} - {{ $shift->store->name }}
                        </div>
                    @empty
                        <span class="text-zinc-500">{{ __('Day off') }}</span>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</section>
