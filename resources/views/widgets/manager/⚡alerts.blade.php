<?php

use App\Dashboard\DashboardScope;
use App\Models\Shift;
use App\Planning\ShiftConflictFinder;
use App\Planning\TimelineBuilder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    private const DAYS = 7;

    public function with(): array
    {
        $user = Auth::user();
        $scope = app(DashboardScope::class);
        $first = $scope->today();
        $stores = $scope->managedStores($user, 'shifts.view')->filter(fn ($store): bool => $store->hasFeature('shifts'));

        $shifts = Shift::query()
            ->with('store')
            ->whereIn('store_id', $stores->modelKeys())
            ->whereNull('replaced_by_id')
            ->between($first, $first->addDays(self::DAYS - 1))
            ->get();

        $finder = app(ShiftConflictFinder::class);
        $conflicts = count($finder->overlappingPairs($shifts)) + $finder->tooShort($shifts)->count() + $finder->tooLong($shifts)->count();

        // Jours en sous-effectif : même calcul que la frise.
        $understaffed = [];
        $builder = app(TimelineBuilder::class);
        foreach ($stores->filter(fn ($store): bool => $store->min_staff_per_day > 0) as $store) {
            foreach ($builder->build($store, $first, self::DAYS, false) as $day) {
                if ($day['understaffed']) {
                    $understaffed[] = ['store' => $store->name, 'date' => $day['date']];
                }
            }
        }

        return [
            'days' => self::DAYS,
            'conflicts' => $conflicts,
            'understaffed' => $understaffed,
        ];
    }
}; ?>

<x-dashboard.card :title="__('Alerts')" data-test="widget-alerts">
    <div class="space-y-3 text-sm">
        <div class="flex items-center justify-between gap-2" data-test="alert-conflicts">
            <span>{{ __('Shift conflicts in the next :days days', ['days' => $days]) }}</span>
            <a href="{{ route('shifts.conflicts') }}" wire:navigate>
                <flux:badge :color="$conflicts > 0 ? 'red' : 'green'" data-test="alert-conflicts-count">{{ $conflicts }}</flux:badge>
            </a>
        </div>

        <div data-test="alert-understaffed">
            <div class="flex items-center justify-between gap-2">
                <span>{{ __('Understaffed days in the next :days days', ['days' => $days]) }}</span>
                <flux:badge :color="count($understaffed) > 0 ? 'amber' : 'green'" data-test="alert-understaffed-count">{{ count($understaffed) }}</flux:badge>
            </div>
            @if ($understaffed !== [])
                <ul class="mt-2 space-y-1">
                    @foreach ($understaffed as $row)
                        <li wire:key="understaffed-{{ $loop->index }}">
                            <a href="{{ route('shifts.timeline') }}" wire:navigate class="underline">{{ $row['date']->translatedFormat('D d M') }} - {{ $row['store'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-dashboard.card>
