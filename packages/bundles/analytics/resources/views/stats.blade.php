@use('Kintai\Bundles\Analytics\Presenter')
@php
    $s = $stats;
    $n = $s['n'];
    $money = fn ($amount): string => Presenter::money($amount, $storeModel);
    $hours = fn ($value): string => number_format((float) $value, 1).__('h');
    $name = fn (int $id): string => Presenter::name($s['usersMap'], $id);
    $employeeLink = fn (int $id): ?string => in_array($id, $s['memberIds'], true) ? route('analytics.employee', ['user' => $id, 'store' => $storeModel->id, 'period' => $s['period']]) : null;
    $dow = $s['dowLabels'];
    $maxHours = $s['hoursByUser'] === [] ? 0 : max($s['hoursByUser']);
    $card = 'analytics::partials.card';
    $bars = 'analytics::partials.bars';
@endphp

<section class="w-full max-w-6xl space-y-6 p-4" data-test="store-stats">
    @include('analytics::partials.header', [
        'title' => __('Statistics').' - '.$storeModel->name,
        'subtitle' => __('from :from to :to', ['from' => $s['since'], 'to' => $s['today']]),
        'stores' => $stores, 'periods' => $periods, 'period' => $s['period'],
    ])

    <div class="flex flex-wrap gap-2">
        @if ($exportUrl)
            <flux:button size="sm" variant="filled" icon="arrow-down-tray" :href="$exportUrl" data-test="export-link">{{ __('Export CSV') }}</flux:button>
        @endif
        <flux:button size="sm" variant="filled" :href="route('analytics.employees', ['store' => $storeModel->id, 'period' => $s['period']])" wire:navigate data-test="employees-link">{{ __('Employee report') }}</flux:button>
        @if ($hasSales)
            <flux:button size="sm" variant="filled" :href="route('analytics.profitability', ['store' => $storeModel->id])" wire:navigate data-test="profitability-link">{{ __('Profitability') }}</flux:button>
        @endif
    </div>

    @if ($n === 0)
        <flux:callout variant="secondary" icon="information-circle" data-test="no-shifts">
            <flux:callout.text>{{ __('No shifts in this period.') }}</flux:callout.text>
        </flux:callout>
    @else
        {{-- Scores --}}
        <div class="space-y-3" data-test="section-scores">
            <flux:heading size="lg">{{ __('Performance scores') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @include($card, ['label' => __('Equity'), 'value' => $s['equityScore'], 'sub' => __('hours distribution between employees'), 'tone' => Presenter::tone($s['equityScore'])])
                @include($card, ['label' => __('Efficiency'), 'value' => $s['efficiencyScore'], 'sub' => __('net hours / gross hours'), 'tone' => Presenter::tone($s['efficiencyScore'])])
                @include($card, ['label' => __('Stability'), 'value' => $s['stabilityScore'], 'sub' => __('schedule rarely modified after creation'), 'tone' => Presenter::tone($s['stabilityScore'])])
                @include($card, ['label' => __('HR wellbeing'), 'value' => max(0, 100 - $s['burnoutRisk']), 'sub' => __('rest, consecutive days, max load'), 'tone' => Presenter::tone(max(0, 100 - $s['burnoutRisk']))])
            </div>
            @if ($s['delta']['n'] !== null || $s['delta']['totalNetHours'] !== null)
                <div class="flex flex-wrap items-center gap-3 text-sm" data-test="deltas">
                    <flux:text size="sm">{{ __('vs previous period') }} :</flux:text>
                    @if ($s['delta']['n'] !== null)
                        <flux:badge size="sm" :color="$s['delta']['n'] >= 0 ? 'green' : 'red'">{{ __('Shifts') }} {{ $s['delta']['n'] > 0 ? '+' : '' }}{{ $s['delta']['n'] }}%</flux:badge>
                    @endif
                    @if ($s['delta']['totalNetHours'] !== null)
                        <flux:badge size="sm" :color="$s['delta']['totalNetHours'] >= 0 ? 'green' : 'red'">{{ __('Net hours') }} {{ $s['delta']['totalNetHours'] > 0 ? '+' : '' }}{{ $s['delta']['totalNetHours'] }}%</flux:badge>
                    @endif
                    @if ($s['delta']['modRate'] !== null)
                        <flux:badge size="sm" :color="$s['delta']['modRate'] <= 0 ? 'green' : 'red'">{{ __('Modification rate') }} {{ $s['delta']['modRate'] > 0 ? '+' : '' }}{{ $s['delta']['modRate'] }}pt</flux:badge>
                    @endif
                </div>
            @endif
        </div>

        {{-- Planification --}}
        <div class="space-y-3" data-test="section-planning">
            <flux:heading size="lg">{{ __('Planning statistics') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @include($card, ['label' => __('Shifts analyzed'), 'value' => $n])
                @include($card, ['label' => __('Avg. shift duration'), 'value' => $hours($s['avgDuration'])])
                @include($card, ['label' => __('Avg. shifts / employee'), 'value' => $s['avgShiftsPerEmployee']])
                @include($card, ['label' => __('Avg. work days'), 'value' => $s['avgDaysPerEmployee'], 'sub' => __('per employee')])
                @include($card, ['label' => __('Opening shifts'), 'value' => $s['openingShifts'], 'sub' => Presenter::percent($s['openingShifts'], $n).' '.__('of shifts')])
                @include($card, ['label' => __('Closing shifts'), 'value' => $s['closingShifts'], 'sub' => Presenter::percent($s['closingShifts'], $n).' '.__('of shifts')])
                @include($card, ['label' => __('Avg. time between shifts'), 'value' => $s['avgTimeBetweenShifts'] !== null ? $hours($s['avgTimeBetweenShifts']) : '—', 'sub' => __('between 2 shifts of the same employee')])
                @if ($s['shortRateShifts'] !== null)
                    @include($card, ['label' => __('Short shifts'), 'value' => $s['shortRateShifts'], 'sub' => '< '.$storeModel->min_shift_minutes.' min ('.Presenter::percent($s['shortRateShifts'], $n).')', 'tone' => $s['shortRateShifts'] > 0 ? 'red' : 'green'])
                @endif
                @if ($s['longRateShifts'] !== null)
                    @include($card, ['label' => __('Long shifts'), 'value' => $s['longRateShifts'], 'sub' => '> '.$storeModel->max_shift_minutes.' min ('.Presenter::percent($s['longRateShifts'], $n).')', 'tone' => $s['longRateShifts'] > 0 ? 'amber' : 'green'])
                @endif
            </div>
            <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:heading size="sm" class="mb-2">{{ __('Duration distribution') }}</flux:heading>
                @include($bars, ['data' => ['< 4 h' => $s['distShort'], '4-8 h' => $s['distMedium'], '> 8 h' => $s['distLong']]])
            </div>
        </div>

        {{-- Performance opérationnelle --}}
        <div class="space-y-3" data-test="section-operational">
            <flux:heading size="lg">{{ __('Operational performance') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @include($card, ['label' => __('Net hours'), 'value' => $hours($s['totalNetHours'])])
                @include($card, ['label' => __('Gross hours'), 'value' => $hours($s['totalGrossHours'])])
                @include($card, ['label' => __('Active days'), 'value' => $s['activeDays']])
                @include($card, ['label' => __('Avg. staff/hour'), 'value' => $s['avgEmpPerHour']])
            </div>
            <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:heading size="sm" class="mb-2">{{ __('Hourly coverage (shifts per hour)') }}</flux:heading>
                @php
                    $slots = [];
                    foreach ($s['hoursBySlot'] as $hour => $count) {
                        $slots[sprintf('%02d', $hour)] = $count;
                    }
                @endphp
                @include($bars, ['data' => $slots])
            </div>
        </div>

        {{-- Charge de travail --}}
        <div class="space-y-3" data-test="section-workload">
            <flux:heading size="lg">{{ __('Workload') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @include($card, ['label' => __('Avg. hours / employee'), 'value' => $hours($s['meanHours'])])
                @include($card, ['label' => __('Std. deviation'), 'value' => $hours($s['stdDev'])])
                @include($card, ['label' => __('Gini coeff.'), 'value' => number_format($s['gini'], 3), 'sub' => __('0 = perfect, 1 = unequal'), 'tone' => $s['gini'] > 0.4 ? 'red' : ($s['gini'] > 0.2 ? 'amber' : 'green')])
                @include($card, ['label' => __('Top 20% hours'), 'value' => $s['top20ratio'].'%', 'sub' => __('of total hours')])
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Hours by employee') }}</flux:heading>
                    <flux:table data-test="hours-by-employee">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Employee') }}</flux:table.column>
                            <flux:table.column>{{ __('Hours') }}</flux:table.column>
                            <flux:table.column class="w-1/3"></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($s['hoursByUser'] as $id => $value)
                                <flux:table.row wire:key="hours-{{ $id }}">
                                    <flux:table.cell>
                                        @if ($employeeLink($id))
                                            <flux:link :href="$employeeLink($id)" wire:navigate>{{ $name($id) }}</flux:link>
                                        @else
                                            {{ $name($id) }}
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $hours($value) }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div class="h-2 w-full rounded bg-zinc-100 dark:bg-zinc-700"><div class="h-2 rounded bg-indigo-500" style="width: {{ Presenter::width($value, $maxHours) }}%"></div></div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Hours by shift type') }}</flux:heading>
                    @include($bars, ['data' => $s['hoursByType'], 'color' => 'bg-emerald-500'])
                </div>
            </div>
        </div>

        {{-- Stabilité --}}
        <div class="space-y-3" data-test="section-stability">
            <flux:heading size="lg">{{ __('Schedule stability') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @include($card, ['label' => __('Modification rate'), 'value' => $s['modRate'].'%', 'sub' => __('shifts were modified'), 'tone' => $s['modRate'] > 30 ? 'red' : ($s['modRate'] > 10 ? 'amber' : 'green')])
                @include($card, ['label' => __('Avg. mod. delay'), 'value' => $s['avgModDelay'] !== null ? $hours($s['avgModDelay']) : '—', 'sub' => __('between creation and modification')])
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Modifications by day of week') }}</flux:heading>
                    @include($bars, ['data' => array_combine($dow, array_values($s['modByDow'])), 'color' => 'bg-amber-500'])
                </div>
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Shifts created by manager') }}</flux:heading>
                    <flux:table data-test="by-manager">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Manager') }}</flux:table.column>
                            <flux:table.column>{{ __('Created') }}</flux:table.column>
                            <flux:table.column>{{ __('Modified') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($s['shiftsCreatedByManager'] as $id => $count)
                                <flux:table.row wire:key="manager-{{ $id }}">
                                    <flux:table.cell>{{ $id > 0 ? $name((int) $id) : __('Unknown') }}</flux:table.cell>
                                    <flux:table.cell>{{ $count }}</flux:table.cell>
                                    <flux:table.cell>{{ $s['modByManager'][$id] ?? 0 }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </div>

        {{-- RH : seuls les congés approuvés sont connus (voir le README) --}}
        <div class="space-y-3" data-test="section-hr">
            <flux:heading size="lg">{{ __('HR statistics') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @include($card, ['label' => __('Absence rate'), 'value' => $s['absRate'].'%', 'sub' => __('approved leave days / (headcount x period)')])
                @include($card, ['label' => __('Approved leave'), 'value' => $s['approvedDays'], 'sub' => __('cumulated days')])
            </div>
            @if ($s['timeoffsByUser'] !== [])
                @php
                    $leave = $s['timeoffsByUser'];
                    arsort($leave);
                @endphp
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Leave by employee') }}</flux:heading>
                    <flux:table data-test="leave-by-employee">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Employee') }}</flux:table.column>
                            <flux:table.column>{{ __('Requests') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($leave as $id => $count)
                                <flux:table.row wire:key="leave-{{ $id }}">
                                    <flux:table.cell>{{ $name((int) $id) }}</flux:table.cell>
                                    <flux:table.cell>{{ $count }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </div>

        {{-- Analyse financière --}}
        <div class="space-y-3" data-test="section-financial">
            <flux:heading size="lg">{{ __('Financial analysis') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @include($card, ['label' => __('Total cost'), 'value' => $money($s['totalCost']), 'sub' => __('over the period'), 'tone' => 'green'])
                @include($card, ['label' => __('Avg. cost / shift'), 'value' => $money($s['avgCostPerShift'])])
                @include($card, ['label' => __('Avg. cost / hour'), 'value' => $money($s['avgCostPerHour']).__('/h')])
            </div>
            @if ($s['totalCost'] == 0)
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="no-rate-hint">
                    <flux:callout.text>{{ __('Cost is 0: set an hourly rate on the shift types of the store or personal rates in the profile of each employee.') }}</flux:callout.text>
                </flux:callout>
            @else
                <flux:callout variant="secondary" icon="information-circle">
                    <flux:callout.text>{{ __('Rates used: personal rate of the employee for the type (priority), then rate of the shift type.') }}</flux:callout.text>
                </flux:callout>
            @endif
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Monthly trend') }} ({{ $s['totalCost'] > 0 ? __('payroll cost') : __('Hours') }})</flux:heading>
                    @include($bars, ['data' => $s['totalCost'] > 0 ? $s['costByMonth'] : $s['hoursByMonth'], 'color' => 'bg-emerald-500'])
                </div>
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Cost & hours by shift type') }}</flux:heading>
                    <flux:table data-test="cost-by-type">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Type') }}</flux:table.column>
                            <flux:table.column>{{ __('Hours') }}</flux:table.column>
                            @if ($s['totalCost'] > 0)
                                <flux:table.column>{{ __('Cost') }}</flux:table.column>
                            @endif
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($s['hoursByType'] as $label => $value)
                                <flux:table.row wire:key="type-{{ $loop->index }}">
                                    <flux:table.cell>{{ $label }}</flux:table.cell>
                                    <flux:table.cell>{{ $hours($value) }}</flux:table.cell>
                                    @if ($s['totalCost'] > 0)
                                        <flux:table.cell>{{ $money($s['costByType'][$label] ?? 0) }}</flux:table.cell>
                                    @endif
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
            @if ($s['totalCost'] > 0)
                @php
                    $costs = $s['costByUser'];
                    arsort($costs);
                @endphp
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Cost by employee') }}</flux:heading>
                    <flux:table data-test="cost-by-employee">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Employee') }}</flux:table.column>
                            <flux:table.column>{{ __('Cost') }}</flux:table.column>
                            <flux:table.column>{{ __('Hours') }}</flux:table.column>
                            <flux:table.column>{{ __('Avg. cost/h') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($costs as $id => $cost)
                                <flux:table.row wire:key="cost-{{ $id }}">
                                    <flux:table.cell>{{ $name((int) $id) }}</flux:table.cell>
                                    <flux:table.cell>{{ $money($cost) }}</flux:table.cell>
                                    <flux:table.cell>{{ $hours($s['hoursByUser'][$id] ?? 0) }}</flux:table.cell>
                                    <flux:table.cell>{{ ($s['hoursByUser'][$id] ?? 0) > 0 ? $money($cost / $s['hoursByUser'][$id]) : '—' }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </div>

        {{-- Analyse temporelle --}}
        <div class="space-y-3" data-test="section-temporal">
            <flux:heading size="lg">{{ __('Temporal analysis') }}</flux:heading>
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Hours by day of week') }}</flux:heading>
                    @include($bars, ['data' => array_combine($dow, array_values($s['hoursByDow']))])
                </div>
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-2">{{ __('Shifts by day of week') }}</flux:heading>
                    @include($bars, ['data' => array_combine($dow, array_values($s['shiftsByDow'])), 'color' => 'bg-violet-500'])
                </div>
            </div>
            @if (count($s['hoursByWeek']) > 1)
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-test="weekly-hours">
                    <flux:heading size="sm" class="mb-2">{{ __('Weekly hours trend') }}</flux:heading>
                    @include($bars, ['data' => $s['hoursByWeek']])
                </div>
            @endif
        </div>

        {{-- Qualité du planning --}}
        <div class="space-y-3" data-test="section-quality">
            <flux:heading size="lg">{{ __('Schedule quality') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @include($card, ['label' => __('Conflicts detected'), 'value' => $s['conflictsCount'], 'sub' => __('shift overlaps'), 'tone' => $s['conflictsCount'] > 0 ? 'red' : 'green'])
                @include($card, ['label' => __('Rest under 11 h'), 'value' => $s['shortRestCount'], 'sub' => __('short transitions between shifts'), 'tone' => $s['shortRestCount'] > 0 ? 'amber' : 'green'])
                @include($card, ['label' => __('Max consecutive days'), 'value' => $s['maxConsecDays'], 'sub' => __('across all employees'), 'tone' => $s['maxConsecDays'] > 6 ? 'red' : ($s['maxConsecDays'] > 4 ? 'amber' : 'green')])
                @include($card, ['label' => __('Avg. consecutive days'), 'value' => $s['avgConsecDays']])
            </div>
        </div>
    @endif
</section>
