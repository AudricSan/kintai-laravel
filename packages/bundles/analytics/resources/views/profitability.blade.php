@use('Kintai\Bundles\Analytics\Presenter')
@php
    $money = fn ($amount): string => Presenter::money($amount, $storeModel);
    $card = 'analytics::partials.card';
    $bars = 'analytics::partials.bars';
    $status = fn (string $value): string => match ($value) { 'draft' => __('Draft'), 'submitted' => __('Submitted'), 'validated' => __('Validated'), default => $value };
@endphp

<section class="w-full max-w-6xl space-y-6 p-4" data-test="profitability">
    @include('analytics::partials.header', [
        'title' => __('Profitability').' - '.$storeModel->name,
        'subtitle' => __('Sales and labor cost of the daily reports against the cost calculated from the shifts'),
        'stores' => $stores, 'periods' => $periods,
    ])

    @if (! $data['available'])
        <flux:callout variant="secondary" icon="information-circle" data-test="no-sales-provider">
            <flux:callout.text>{{ __('Profitability needs the daily sales: turn on the daily reports bundle to see it.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <div class="flex flex-wrap items-end gap-3">
                <flux:input type="date" wire:model.live="from" :label="__('From')" class="max-w-44" data-test="from" />
                <flux:input type="date" wire:model.live="to" :label="__('To')" class="max-w-44" data-test="to" />
                <div class="flex flex-wrap gap-1" data-test="presets">
                    @foreach ($periods as $days)
                        <flux:button size="sm" variant="filled" wire:click="setPreset({{ $days }})" data-test="preset-{{ $days }}">
                            {{ match ($days) { 7 => __('7 days'), 30 => __('30 days'), 90 => __('3 months'), 180 => __('6 months'), default => __('1 year') } }}
                        </flux:button>
                    @endforeach
                </div>
            </div>
            <flux:text size="sm">{{ __('from :from to :to', ['from' => $from, 'to' => $to]) }}</flux:text>
        </div>

        @if ($data['rows'] === [])
            <flux:callout variant="secondary" icon="information-circle" data-test="no-daily-reports">
                <flux:callout.text>{{ __('No daily reports found for this period.') }}</flux:callout.text>
                @if ($dailyReportsUrl)
                    <flux:callout.link :href="$dailyReportsUrl" wire:navigate>{{ __('Daily reports') }}</flux:callout.link>
                @endif
            </flux:callout>
        @else
            <div class="space-y-3" data-test="profitability-kpis">
                <flux:heading size="lg">{{ __('Key indicators') }}</flux:heading>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @include($card, ['label' => __('Revenue'), 'value' => $money($data['totalSales']), 'sub' => $data['reportCount'].' '.__('days with report')])
                    @include($card, ['label' => __('Total customers'), 'value' => number_format($data['totalCustomers'])])
                    @include($card, ['label' => __('Revenue / customer'), 'value' => $data['revPerCust'] !== null ? $money($data['revPerCust']) : '—'])
                    @include($card, ['label' => __('Labor Cost %'), 'value' => $data['avgLaborPct'] !== null ? $data['avgLaborPct'].'%' : '—', 'sub' => __('labor cost / revenue')])
                    @include($card, ['label' => __('Labor cost (entered)'), 'value' => $money($data['totalLaborMnl']), 'sub' => __('source: daily reports')])
                    @include($card, ['label' => __('Calculated cost (shifts)'), 'value' => $money($data['totalLaborCalc']), 'sub' => __('source: hours x hourly rate')])
                    @include($card, ['label' => __('Variance'), 'value' => $money($data['variance']), 'sub' => __('close to 0 = consistent data')])
                </div>
            </div>

            <div class="space-y-3" data-test="profitability-trends">
                <flux:heading size="lg">{{ __('Monthly trends') }}</flux:heading>
                <div class="grid gap-3 lg:grid-cols-2">
                    <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-2">{{ __('Monthly revenue') }}</flux:heading>
                        @include($bars, ['data' => $data['salesByMonth'], 'color' => 'bg-emerald-500'])
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-2">{{ __('Monthly Labor Cost %') }}</flux:heading>
                        @include($bars, ['data' => $data['lcPctByMonth'], 'color' => 'bg-amber-500'])
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-2">{{ __('Monthly labor cost') }}</flux:heading>
                        @include($bars, ['data' => $data['laborMnlByMonth'], 'color' => 'bg-indigo-500'])
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-2">{{ __('Customers per month') }}</flux:heading>
                        @include($bars, ['data' => $data['customersByMonth'], 'color' => 'bg-violet-500'])
                    </div>
                </div>
            </div>

            <div class="space-y-3" data-test="profitability-days">
                <flux:heading size="lg">{{ __('Daily breakdown') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Revenue') }}</flux:table.column>
                        <flux:table.column>{{ __('Customers') }}</flux:table.column>
                        <flux:table.column>{{ __('Rev/customer') }}</flux:table.column>
                        <flux:table.column>{{ __('Labor cost (entered)') }}</flux:table.column>
                        <flux:table.column>{{ __('Calculated cost') }}</flux:table.column>
                        <flux:table.column>{{ __('LC%') }}</flux:table.column>
                        <flux:table.column>{{ __('Variance') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($data['rows'] as $row)
                            <flux:table.row wire:key="day-{{ $row['date'] }}" data-test="day-row">
                                <flux:table.cell class="whitespace-nowrap">{{ $row['date'] }}</flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" :color="$row['status'] === 'validated' ? 'green' : ($row['status'] === 'submitted' ? 'amber' : 'zinc')">{{ $status($row['status']) }}</flux:badge></flux:table.cell>
                                <flux:table.cell>{{ $money($row['sales']) }}</flux:table.cell>
                                <flux:table.cell>{{ $row['customers'] }}</flux:table.cell>
                                <flux:table.cell>{{ $row['rev_per_cust'] !== null ? $money($row['rev_per_cust']) : '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $money($row['labor_mnl']) }}</flux:table.cell>
                                <flux:table.cell>{{ $money($row['labor_calc']) }}</flux:table.cell>
                                <flux:table.cell>{{ $row['lc_pct'] !== null ? $row['lc_pct'].'%' : '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $money($row['variance']) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                        <flux:table.row class="font-semibold" data-test="total-row">
                            <flux:table.cell>{{ __('TOTAL') }}</flux:table.cell>
                            <flux:table.cell></flux:table.cell>
                            <flux:table.cell>{{ $money($data['totalSales']) }}</flux:table.cell>
                            <flux:table.cell>{{ $data['totalCustomers'] }}</flux:table.cell>
                            <flux:table.cell>{{ $data['revPerCust'] !== null ? $money($data['revPerCust']) : '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $money($data['totalLaborMnl']) }}</flux:table.cell>
                            <flux:table.cell>{{ $money($data['totalLaborCalc']) }}</flux:table.cell>
                            <flux:table.cell>{{ $data['avgLaborPct'] !== null ? $data['avgLaborPct'].'%' : '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $money($data['variance']) }}</flux:table.cell>
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    @endif
</section>
