<?php

use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\Store;
use App\Planning\WeekRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Calendar')] class extends Component {
    /** Mois "Y-m" */
    public string $month = '';

    public string $store_id = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('shifts.view'), 403);

        $this->month = now()->format('Y-m');
        $this->store_id = (string) request()->query('store_id', '');
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
        abort_unless(Gate::allows('shifts.view'), 403);

        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $viewable = $authorizer->storeIdsFor($actor, 'shifts.view');
        $stores = Store::query()
            ->when($viewable !== null, fn ($query) => $query->whereIn('id', $viewable))
            ->orderBy('name')
            ->get();

        $store = $stores->firstWhere('id', (int) $this->store_id) ?? $stores->first();
        $month = $this->monthStart();
        $weeks = $store === null ? [] : WeekRange::monthGrid($month, $store->week_start_day);

        $shiftsByDate = [];
        if ($store !== null && $weeks !== []) {
            $first = $weeks[0][0];
            $last = $weeks[count($weeks) - 1][6];

            $shiftsByDate = Shift::query()
                ->with(['user:id,name', 'shiftType'])
                ->forStore($store)
                ->between($first, $last)
                ->orderBy('starts_at')
                ->get()
                ->groupBy(fn (Shift $shift): string => $shift->shift_date->toDateString())
                ->all();
        }

        return [
            'stores' => $stores,
            'store' => $store,
            'weeks' => $weeks,
            'shiftsByDate' => $shiftsByDate,
            'monthLabel' => $month->translatedFormat('F Y'),
            'monthNumber' => $month->month,
            'today' => now()->toDateString(),
            'canCreate' => $store !== null && $authorizer->allows($actor, 'shifts.create', $store),
            'canUpdate' => $store !== null && $authorizer->allows($actor, 'shifts.update', $store),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('Calendar') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('shifts.index', ['store_id' => $store?->id])" wire:navigate icon="list-bullet">{{ __('Shifts') }}</flux:button>
            <flux:button :href="route('shifts.timeline', ['store_id' => $store?->id])" wire:navigate icon="chart-bar">{{ __('Timeline') }}</flux:button>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="store_id" :label="__('Store')" class="max-w-xs">
            @foreach ($stores as $option)
                <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="flex items-center gap-1">
            <flux:button wire:click="previousMonth" icon="chevron-left" variant="ghost" :aria-label="__('Previous month')" data-test="previous-month" />
            <strong class="min-w-40 text-center" data-test="month-label">{{ $monthLabel }}</strong>
            <flux:button wire:click="nextMonth" icon="chevron-right" variant="ghost" :aria-label="__('Next month')" data-test="next-month" />
        </div>
    </div>

    @if ($store === null)
        <flux:text>{{ __('No store found.') }}</flux:text>
    @else
        <div class="overflow-x-auto">
            <div class="grid min-w-[56rem] grid-cols-7 gap-px rounded-lg border border-zinc-200 bg-zinc-200 dark:border-zinc-700 dark:bg-zinc-700" data-test="calendar-grid">
                @foreach ($weeks[0] as $headerDay)
                    <div class="bg-zinc-100 p-2 text-center text-sm font-semibold dark:bg-zinc-800" data-test="weekday-header">{{ $headerDay->translatedFormat('D') }}</div>
                @endforeach

                @foreach ($weeks as $week)
                    @foreach ($week as $day)
                        @php($dateKey = $day->toDateString())
                        <div class="min-h-28 space-y-1 bg-white p-1.5 dark:bg-zinc-900 {{ $day->month !== $monthNumber ? 'opacity-50' : '' }}" data-test="calendar-day" data-date="{{ $dateKey }}" wire:key="day-{{ $dateKey }}">
                            <div class="flex items-center justify-between text-xs">
                                <span class="{{ $dateKey === $today ? 'rounded-full bg-zinc-800 px-1.5 py-0.5 font-bold text-white dark:bg-white dark:text-zinc-900' : 'font-semibold' }}">{{ $day->day }}</span>
                                @if ($canCreate)
                                    <a href="{{ route('shifts.create', ['date' => $dateKey, 'store_id' => $store->id]) }}" wire:navigate class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white" title="{{ __('New shift') }}" data-test="create-shift-link">+</a>
                                @endif
                            </div>
                            @foreach ($shiftsByDate[$dateKey] ?? [] as $shift)
                                @php($label = $shift->localStartsAt->format('H:i').'-'.$shift->localEndsAt->format('H:i').' '.($shift->user?->name ?? __('Open shift')))
                                @if ($canUpdate)
                                    <a href="{{ route('shifts.edit', $shift) }}" wire:navigate class="block truncate rounded px-1.5 py-0.5 text-xs text-white" style="background-color: {{ $shift->shiftType->color ?? '#71717a' }}" title="{{ $label }}" data-test="calendar-shift">{{ $label }}</a>
                                @else
                                    <div class="truncate rounded px-1.5 py-0.5 text-xs text-white" style="background-color: {{ $shift->shiftType->color ?? '#71717a' }}" title="{{ $label }}" data-test="calendar-shift">{{ $label }}</div>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    @endif
</section>
