<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Daily reports') }}</flux:heading>
            <flux:subheading>{{ __('Sales, customers, labor cost and waste of each day') }}</flux:subheading>
        </div>
        <div class="flex gap-2">
            @if ($canSettings)
                <flux:button variant="filled" icon="cog-6-tooth" :href="route('daily-report.settings', ['store' => $single])" wire:navigate data-test="settings-link">{{ __('Settings') }}</flux:button>
            @endif
            @if ($canCreate)
                <flux:button variant="primary" icon="plus" :href="route('daily-report.create', ['store' => $single])" wire:navigate data-test="new-report-button">{{ __('New report') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($reminder)
        <flux:callout variant="warning" icon="clock" data-test="reminder-alert">
            <flux:callout.text>{{ __('The daily report for today has not been saved yet. Please enter it before closing time.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap gap-3">
        @if (count($stores) > 1)
            <flux:select wire:model.live="store" class="max-w-xs">
                <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach ($statuses as $option)
                <flux:select.option :value="$option">{{ __(\Kintai\Bundles\DailyReport\Models\DailyReport::statusLabel($option)) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="from" type="date" :label="__('From')" class="max-w-xs" />
        <flux:input wire:model.live="to" type="date" :label="__('To')" class="max-w-xs" />
        @if ($single === null)
            <flux:select wire:model.live="view" class="max-w-xs">
                <flux:select.option value="day">{{ __('By day') }}</flux:select.option>
                <flux:select.option value="store">{{ __('By store') }}</flux:select.option>
            </flux:select>
        @endif
    </div>

    @if ($single !== null && $setting !== null && $setting->cumulative_mode !== 'per_day')
        <div>
            <flux:badge size="sm" color="amber">{{ $setting->cumulative_mode === 'cumulative_system' ? __('Running total') : __('Running total (as entered)') }}</flux:badge>
        </div>
    @endif

    @php
        $tables = $groups ?? ($single !== null ? null : collect(['all' => $reports]));
    @endphp

    @if ($single !== null)
        {{-- Un seul magasin : les jours sans rapport apparaissent. --}}
        @include('daily-report::partials.table', ['items' => $rows, 'placeholders' => true, 'cumulative' => $cumulative, 'showStore' => false])
    @elseif ($reports->isEmpty())
        <flux:text data-test="no-reports">{{ __('No daily reports match the filters.') }}</flux:text>
    @else
        @foreach ($tables as $key => $group)
            @if ($groups !== null)
                <flux:heading size="lg">{{ $stores[$key] ?? '' }}</flux:heading>
            @endif
            @include('daily-report::partials.table', [
                'items' => $group->map(fn ($report) => ['date' => $report->report_date->toDateString(), 'report' => $report])->values()->all(),
                'placeholders' => false, 'cumulative' => [], 'showStore' => $groups === null,
            ])
        @endforeach
    @endif
</section>
