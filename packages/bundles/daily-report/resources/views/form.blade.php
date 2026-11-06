<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $editing ? __('Edit the report') : __('New report') }}</flux:heading>
            <flux:subheading>{{ __('Enter the totals of the day for the four key figures.') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="$editing ? route('daily-report.show', $this->reportId) : route('daily-report.index')" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="report-form">
        <div class="grid gap-4 sm:grid-cols-2">
            @if (! $editing && count($stores) > 1)
                <flux:select wire:model.live="storeId" :label="__('Store')">
                    @foreach ($stores as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:input :value="$stores[$storeId] ?? ''" :label="__('Store')" readonly />
            @endif
            @if ($editing)
                <flux:input wire:model="reportDate" type="date" :label="__('Date')" readonly />
            @else
                <flux:input wire:model.live="reportDate" type="date" :label="__('Date')" :min="$owner ? null : $today" />
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="salesTotal" type="number" min="0" step="0.01" inputmode="decimal" :label="__('Sales')" data-test="sales-input" />
            <flux:input wire:model="customerCount" type="number" min="0" step="1" inputmode="numeric" :label="__('Customers')" data-test="customers-input" />
            <flux:input wire:model="laborCost" type="number" min="0" step="0.01" inputmode="decimal" :label="__('Labor')" data-test="labor-input" />
            <flux:input wire:model="wasteTotal" type="number" min="0" step="0.01" inputmode="decimal" :label="__('Waste')" data-test="waste-input" />
        </div>

        <flux:textarea wire:model="notes" :label="__('Notes')" :placeholder="__('Information to pass on to the team...')" rows="4" />

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="save-report-button">{{ $editing ? __('Save') : __('Create the report') }}</flux:button>
        </div>
    </form>

    @include('daily-report::partials.shifts', ['rows' => $rows, 'columns' => $columns])
</section>
