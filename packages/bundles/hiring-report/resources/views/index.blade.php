<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Hiring reports') }}</flux:heading>
            <flux:subheading>{{ __('Hiring report of each employee, by store') }}</flux:subheading>
        </div>
        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('hiring-report.create', ['store' => $single])" wire:navigate data-test="new-report-button">{{ __('New report') }}</flux:button>
        @endif
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
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Hire date') }}</flux:table.column>
            <flux:table.column>{{ __('Store') }}</flux:table.column>
            <flux:table.column>{{ __('Employee number') }}</flux:table.column>
            <flux:table.column>{{ __('Employee name') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($reports as $report)
                <flux:table.row :key="$report->id" data-test="report-row">
                    <flux:table.cell>{{ $report->hire_date?->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>{{ $report->store->name }}</flux:table.cell>
                    <flux:table.cell>{{ $report->employee_number }}</flux:table.cell>
                    <flux:table.cell>{{ $report->employee_name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" variant="filled" :href="route('hiring-report.show', $report->id)" wire:navigate data-test="open-report-button">{{ __('Open') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" data-test="no-reports">{{ __('No hiring reports match the filters.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
