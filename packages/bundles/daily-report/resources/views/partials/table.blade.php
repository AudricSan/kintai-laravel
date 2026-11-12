@php($money = fn ($report, $amount) => \App\Support\Money::forStore($amount, $report->store))
<flux:table>
    <flux:table.columns>
        <flux:table.column>{{ __('Date') }}</flux:table.column>
        @if ($showStore)
            <flux:table.column>{{ __('Store') }}</flux:table.column>
        @endif
        <flux:table.column>{{ __('Status') }}</flux:table.column>
        <flux:table.column align="end">{{ __('Sales') }}</flux:table.column>
        <flux:table.column align="end">{{ __('Customers') }}</flux:table.column>
        <flux:table.column align="end">{{ __('Labor') }}</flux:table.column>
        <flux:table.column align="end">{{ __('Waste') }}</flux:table.column>
        <flux:table.column>{{ __('Author') }}</flux:table.column>
        <flux:table.column></flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @forelse ($items as $item)
            @php($report = $item['report'])
            @if ($report === null)
                @if ($placeholders)
                    <flux:table.row :key="'gap-'.$item['date']" data-test="report-gap-row">
                        <flux:table.cell>{{ $item['date'] }}</flux:table.cell>
                        <flux:table.cell colspan="{{ $showStore ? 8 : 7 }}"><flux:text size="sm" class="italic">{{ __('No report') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endif
                @continue
            @endif
            @php($totals = $cumulative[$report->id] ?? null)
            <flux:table.row :key="$report->id" data-test="report-row">
                <flux:table.cell>{{ $report->report_date->toDateString() }}</flux:table.cell>
                @if ($showStore)
                    <flux:table.cell>{{ $report->store->name }}</flux:table.cell>
                @endif
                <flux:table.cell>
                    <flux:badge size="sm" :color="match ($report->status) { 'validated' => 'green', 'submitted' => 'amber', default => 'zinc' }" data-test="report-status">{{ __(\Kintai\Bundles\DailyReport\Models\DailyReport::statusLabel($report->status)) }}</flux:badge>
                </flux:table.cell>
                <flux:table.cell align="end">{{ $money($report, $totals['sales_total'] ?? $report->sales_total) }}</flux:table.cell>
                <flux:table.cell align="end">{{ number_format($totals['customer_count'] ?? $report->customer_count) }}</flux:table.cell>
                <flux:table.cell align="end">{{ $money($report, $totals['labor_cost'] ?? $report->labor_cost) }}</flux:table.cell>
                <flux:table.cell align="end">{{ $money($report, $totals['waste_total'] ?? $report->waste_total) }}</flux:table.cell>
                <flux:table.cell>{{ $report->author?->name ?? '—' }}</flux:table.cell>
                <flux:table.cell>
                    <flux:button size="sm" variant="filled" :href="route('daily-report.show', $report->id)" wire:navigate data-test="open-report-button">{{ __('Open') }}</flux:button>
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="{{ $showStore ? 9 : 8 }}">{{ __('No daily reports for this store.') }}</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
