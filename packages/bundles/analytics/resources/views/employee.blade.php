@use('Kintai\Bundles\Analytics\Presenter')
@php
    $money = fn ($amount): string => Presenter::money($amount, $storeModel);
    $hours = fn ($value): string => number_format((float) $value, 1).__('h');
    $card = 'analytics::partials.card';
    $bars = 'analytics::partials.bars';
@endphp

<section class="w-full max-w-5xl space-y-6 p-4" data-test="employee-stats">
    @include('analytics::partials.header', [
        'title' => __('Statistics').' - '.$employeeModel->name,
        'subtitle' => $storeModel->name.' - '.__('from :from to :to', ['from' => $stats['since'], 'to' => $stats['today']]),
        'stores' => $stores, 'periods' => $periods, 'period' => $stats['period'],
    ])

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-test="employee-kpis">
        @include($card, ['label' => __('Shifts'), 'value' => $stats['totalShifts']])
        @include($card, ['label' => __('Net hours'), 'value' => $hours($stats['netHours'])])
        @include($card, ['label' => __('Gross hours'), 'value' => $hours($stats['grossHours'])])
        @include($card, ['label' => __('Estimated cost'), 'value' => $stats['anyRate'] ? $money($stats['cost']) : __('not configured')])
        @include($card, ['label' => __('Work days'), 'value' => $stats['workDays']])
        @include($card, ['label' => __('Absence days'), 'value' => $stats['absDays']])
        @include($card, ['label' => __('Max consecutive days'), 'value' => $stats['maxConsec']])
    </div>

    <div class="grid gap-3 lg:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <flux:heading size="sm" class="mb-2">{{ __('Monthly net hours trend (12 months)') }}</flux:heading>
            @include($bars, ['data' => $stats['monthlyHours']])
        </div>
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <flux:heading size="sm" class="mb-2">{{ __('Hours by day of week') }}</flux:heading>
            @include($bars, ['data' => $stats['dowChart'], 'color' => 'bg-violet-500'])
        </div>
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <flux:heading size="sm" class="mb-2">{{ __('Hours by shift type') }}</flux:heading>
            @include($bars, ['data' => $stats['typeHours'], 'color' => 'bg-emerald-500'])
        </div>
    </div>

    <div class="space-y-3" data-test="recent-shifts">
        <flux:heading size="lg">{{ __('Recent shifts') }}</flux:heading>
        @if ($stats['recentShifts'] === [])
            <flux:text size="sm">{{ __('No shifts in this period.') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Start') }}</flux:table.column>
                    <flux:table.column>{{ __('End') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($stats['recentShifts'] as $shift)
                        <flux:table.row wire:key="shift-{{ $shift->id }}">
                            <flux:table.cell>{{ $shift->shift_date->toDateString() }}</flux:table.cell>
                            <flux:table.cell>{{ $shift->localStartsAt->format("H:i") }}</flux:table.cell>
                            <flux:table.cell>{{ $shift->localEndsAt->format("H:i") }}</flux:table.cell>
                            <flux:table.cell>{{ $shift->shift_type_id !== null ? ($stats['storeTypesMap'][$shift->shift_type_id]->name ?? '—') : __('No type') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <flux:button size="sm" variant="filled" :href="route('analytics.employees', ['store' => $storeModel->id, 'period' => $stats['period']])" wire:navigate>{{ __('Back to report') }}</flux:button>
</section>
