<section class="w-full space-y-6 p-4">
    @php($money = fn ($report, $amount) => \App\Support\Money::forStore($amount, $report->store))
    @php($exportQuery = array_filter(['store_id' => $single, 'year' => $year, 'month' => $month, 'employee_id' => ctype_digit($employee) && $employee !== '0' ? $employee : null]))

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Salary reports') }}</flux:heading>
            <flux:subheading>{{ __('Monthly salary of a store or of an employee, calculated from the shifts') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canSettings)
                <flux:button variant="filled" icon="cog-6-tooth" :href="route('salary-report.settings', ['store' => $single])" wire:navigate data-test="settings-link">{{ __('Deductions') }}</flux:button>
            @endif
            @if ($canExport)
                <flux:button variant="filled" icon="arrow-down-tray" :href="route('salary-report.export.json', $exportQuery)" data-test="export-json-link">{{ __('Export JSON') }}</flux:button>
                <flux:button variant="filled" icon="document-text" :href="route('salary-report.export.pdf', $exportQuery)" target="_blank" data-test="export-pdf-link">{{ __('Export PDF') }}</flux:button>
            @endif
            @if ($canGenerate)
                <flux:button variant="primary" icon="plus" :href="route('salary-report.create', ['store' => $single])" wire:navigate data-test="new-report-button">{{ __('New report') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap gap-3">
        @if (count($stores) > 1)
            <flux:select wire:model.live="store" class="max-w-xs" data-test="filter-store">
                <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:select wire:model.live="year" class="max-w-xs" data-test="filter-year">
            <flux:select.option value="">{{ __('All years') }}</flux:select.option>
            @foreach ($years as $option)
                <flux:select.option :value="$option">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="month" class="max-w-xs" data-test="filter-month">
            <flux:select.option value="">{{ __('All months') }}</flux:select.option>
            @foreach (range(1, 12) as $option)
                <flux:select.option :value="$option">{{ str_pad((string) $option, 2, '0', STR_PAD_LEFT) }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($single !== null)
            <flux:select wire:model.live="employee" class="max-w-xs" data-test="filter-employee">
                <flux:select.option value="">{{ __('All reports') }}</flux:select.option>
                <flux:select.option value="0">{{ __('Whole store') }}</flux:select.option>
                @foreach ($employees as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Month') }}</flux:table.column>
            <flux:table.column>{{ __('Store') }}</flux:table.column>
            <flux:table.column>{{ __('Employee') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Hours') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Gross pay') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Deductions') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Net pay') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($reports as $report)
                <flux:table.row :key="$report->id" data-test="report-row">
                    <flux:table.cell>{{ $report->target_month }}</flux:table.cell>
                    <flux:table.cell>{{ $report->store->name }}</flux:table.cell>
                    <flux:table.cell>{{ $report->employee?->name ?? __('Whole store') }}</flux:table.cell>
                    <flux:table.cell align="end">{{ rtrim(rtrim($report->staff_man_hours, '0'), '.') ?: '0' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $money($report, $report->staff_total_payment) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $money($report, $report->total_deductions) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $money($report, $report->net_payment) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" variant="filled" :href="route('salary-report.show', $report->id)" wire:navigate data-test="open-report-button">{{ __('Open') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" data-test="no-reports">{{ __('No salary reports match the filters.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
