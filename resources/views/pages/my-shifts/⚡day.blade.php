<?php

use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My day')] class extends Component {
    /** "Y-m-d" */
    public string $date = '';

    public function mount(): void
    {
        $this->date = (string) request()->query('date', '') ?: now()->toDateString();
    }

    public function previousDay(): void
    {
        $this->date = $this->day()->subDay()->toDateString();
    }

    public function nextDay(): void
    {
        $this->date = $this->day()->addDay()->toDateString();
    }

    private function day(): CarbonImmutable
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->date, $p) === 1 && checkdate((int) $p[2], (int) $p[3], (int) $p[1])
            ? CarbonImmutable::parse($this->date)->startOfDay()
            : CarbonImmutable::today();
    }

    public function with(): array
    {
        $user = Auth::user();
        $day = $this->day();

        $mine = Shift::query()
            ->with(['store:id,name', 'shiftType'])
            ->where('user_id', $user->id)
            ->whereDate('shift_date', $day->toDateString())
            ->orderBy('starts_at')
            ->get();

        // Collègues : uniquement dans les magasins où l'on travaille ce jour-là ; nom et horaires, jamais de coût.
        $colleagues = $mine->isEmpty() ? collect() : Shift::query()
            ->with('user:id,name')
            ->whereIn('store_id', $mine->pluck('store_id')->unique()->all())
            ->whereDate('shift_date', $day->toDateString())
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $user->id)
            ->orderBy('starts_at')
            ->get()
            ->groupBy('store_id');

        return ['dayDate' => $day, 'mine' => $mine, 'colleagues' => $colleagues];
    }
}; ?>

<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('My day') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('my-shifts.index')" wire:navigate icon="list-bullet">{{ __('My shifts') }}</flux:button>
            <flux:button :href="route('my-shifts.week')" wire:navigate>{{ __('Week') }}</flux:button>
        </div>
    </div>

    <div class="flex items-center gap-1">
        <flux:button wire:click="previousDay" icon="chevron-left" variant="ghost" :aria-label="__('Previous day')" />
        <strong class="min-w-48 text-center" data-test="day-label">{{ $dayDate->translatedFormat('l d M Y') }}</strong>
        <flux:button wire:click="nextDay" icon="chevron-right" variant="ghost" :aria-label="__('Next day')" />
    </div>

    @forelse ($mine as $shift)
        <div class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="my-day-shift" wire:key="mine-{{ $shift->id }}">
            <div class="font-semibold">{{ $shift->localStartsAt->format('H:i') }} - {{ $shift->localEndsAt->format('H:i') }}{{ $shift->crossesMidnight ? ' (+1)' : '' }} - {{ $shift->store->name }}</div>
            <div class="text-sm">
                @if ($shift->shiftType)
                    <span class="me-1 inline-block size-3 rounded-full align-middle" style="background-color: {{ $shift->shiftType->color }}"></span>{{ $shift->shiftType->name }} -
                @endif
                {{ __('Break') }}: {{ $shift->pause_minutes }} min - {{ $shift->netMinutes }} min
            </div>
            @if ($shift->notes)
                <flux:text size="sm">{{ $shift->notes }}</flux:text>
            @endif
            <div class="text-sm" data-test="colleagues">
                <span class="font-semibold">{{ __('Colleagues') }}:</span>
                @forelse ($colleagues->get($shift->store_id, collect()) as $other)
                    <div data-test="colleague">{{ $other->user?->name }} ({{ $other->localStartsAt->format('H:i') }} - {{ $other->localEndsAt->format('H:i') }})</div>
                @empty
                    <span>{{ __('None.') }}</span>
                @endforelse
            </div>
        </div>
    @empty
        <flux:text data-test="day-off">{{ __('Day off') }}</flux:text>
    @endforelse
</section>
