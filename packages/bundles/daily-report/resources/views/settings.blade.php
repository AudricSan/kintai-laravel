<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Daily report settings') }}</flux:heading>
            <flux:subheading>{{ __('Recipients, automatic validation, reminder and schedule columns of a store') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('daily-report.index', ['store' => $store])" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="settings-form">
        @if (count($stores) > 1)
            <flux:select wire:model.live="store" :label="__('Store')">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Email recipients') }}</flux:heading>
            <flux:text size="sm">{{ __('Tick the employees of the store who receive the validated report. Each one gets their own email, in their language, with the PDF attached.') }}</flux:text>
            <flux:checkbox.group wire:model="recipients" data-test="recipients">
                @foreach ($candidates as $id => $name)
                    <flux:checkbox :value="$id" :label="$name" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="recipients" />
            <flux:switch wire:model="autoSend" :label="__('Automatic sending')" :description="__('Send the email as soon as a report is validated.')" />
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Times') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="autoValidateTime" type="time" :label="__('Automatic validation')" :description="__('At this time, the report of the previous day is validated if nobody has validated it. Leave empty to turn it off.')" />
                <flux:input wire:model="reminderTime" type="time" :label="__('Reminder')" :description="__('From this time, a reminder is shown if the report of the day has not been created.')" />
                <flux:input wire:model="noReportTime" type="time" :label="__('“No report” time')" :description="__('From this time, the reminder disappears and a day without report shows as “No report”.')" />
            </div>
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Schedule columns') }}</flux:heading>
            <flux:text size="sm">{{ __('Columns shown in the preview of the next day shifts.') }}</flux:text>
            <flux:checkbox.group wire:model="columns" data-test="columns">
                @foreach ($allColumns as $column)
                    <flux:checkbox :value="$column" :label="match ($column) { 'employee' => __('Employee'), 'type' => __('Shift type'), 'schedule' => __('Schedule'), 'gross_hours' => __('Gross hours'), 'pause' => __('Break'), default => __('Net hours') }" />
                @endforeach
            </flux:checkbox.group>
        </div>

        <flux:select wire:model="mode" :label="__('Calculation mode')">
            @foreach ($modes as $option)
                <flux:select.option :value="$option">{{ match ($option) { 'cumulative_system' => __('Cumulative (automatic addition)'), 'cumulative_input' => __('Cumulative (values already cumulated)'), default => __('Per day (each report is a single day)') } }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="save-settings-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
