@use('Kintai\Bundles\Analytics\Presenter')
@php
    $money = fn ($amount): string => Presenter::money($amount, $storeModel);
    $hours = fn ($value): string => number_format((float) $value, 1).__('h');
    $card = 'analytics::partials.card';
@endphp

<section class="w-full max-w-6xl space-y-6 p-4" data-test="employee-report">
    @include('analytics::partials.header', [
        'title' => __('Employee report').' - '.$storeModel->name,
        'subtitle' => __('from :from to :to', ['from' => $report['since'], 'to' => $report['today']]),
        'stores' => $stores, 'periods' => $periods, 'period' => $report['period'],
    ])

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5" data-test="report-totals">
        @include($card, ['label' => __('Active employees'), 'value' => $report['activeCount']])
        @include($card, ['label' => __('Shifts'), 'value' => $report['totalShifts']])
        @include($card, ['label' => __('Net hours'), 'value' => $hours($report['totalNetHours'])])
        @if ($report['anyHasRate'])
            @include($card, ['label' => __('Estimated cost'), 'value' => $money($report['totalCost'])])
        @endif
        @include($card, ['label' => __('Absence days'), 'value' => $report['totalAbsDays']])
    </div>

    @if ($report['employeeStats'] === [])
        <flux:callout variant="secondary" icon="information-circle" data-test="no-employees">
            <flux:callout.text>{{ __('No employee with shifts in this period.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:table data-test="report-table">
            <flux:table.columns>
                <flux:table.column>{{ __('Employee') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Shifts') }}</flux:table.column>
                <flux:table.column>{{ __('Gross h.') }}</flux:table.column>
                <flux:table.column>{{ __('Net h.') }}</flux:table.column>
                @if ($report['anyHasRate'])
                    <flux:table.column>{{ __('Est. cost') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('Work days') }}</flux:table.column>
                <flux:table.column>{{ __('Abs. days') }}</flux:table.column>
                <flux:table.column>{{ __('Max consec.') }}</flux:table.column>
                @if ($report['anySwaps'])
                    <flux:table.column>{{ __('Swaps') }}</flux:table.column>
                @endif
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($report['employeeStats'] as $id => $row)
                    <flux:table.row wire:key="employee-{{ $id }}" data-test="employee-row">
                        <flux:table.cell>
                            <flux:link :href="route('analytics.employee', ['user' => $id, 'store' => $storeModel->id, 'period' => $report['period']])" wire:navigate>{{ Presenter::name($report['usersMap'], $id) }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ $report['membersMap'][$id]['role_name'] ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $row['shifts'] }}</flux:table.cell>
                        <flux:table.cell>{{ $hours($row['gross_hours']) }}</flux:table.cell>
                        <flux:table.cell>{{ $hours($row['net_hours']) }}</flux:table.cell>
                        @if ($report['anyHasRate'])
                            <flux:table.cell>{{ $row['has_rate'] ? $money($row['cost']) : __('not configured') }}</flux:table.cell>
                        @endif
                        <flux:table.cell>{{ $row['work_days'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['abs_days'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['max_consec'] }}</flux:table.cell>
                        @if ($report['anySwaps'])
                            <flux:table.cell>{{ $row['swaps'] }}</flux:table.cell>
                        @endif
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:button size="sm" variant="filled" :href="route('analytics.stats', ['store' => $storeModel->id, 'period' => $report['period']])" wire:navigate>{{ __('Store statistics') }}</flux:button>
</section>
