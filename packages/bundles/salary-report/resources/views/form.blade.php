<section class="w-full max-w-4xl space-y-6 p-4">
    @php($labels = [
        'total_payment' => __('Total payments'),
        'staff_man_hours' => __('Staff hours'),
        'staff_total_payment' => __('Staff gross pay'),
        'staff_avg_hourly_wage' => __('Average hourly wage'),
        'active_employees' => __('Active employees'),
        'income_tax_base' => __('Income tax base'),
        'withholding_tax' => __('Withholding tax'),
        'residence_tax' => __('Residence tax'),
        'other_deductions' => __('Other deductions'),
        'total_deductions' => __('Total deductions'),
        'net_payment' => __('Net payment'),
        'hand_delivered_salary' => __('Salary paid in cash'),
        'bank_transfer_salary' => __('Salary paid by bank transfer'),
        'new_hires' => __('New hires'),
        'resigned_staff' => __('Departures'),
    ])
    @php($sections = [
        __('Summary') => ['total_payment', 'staff_man_hours', 'staff_total_payment', 'staff_avg_hourly_wage', 'active_employees'],
        __('Deductions') => ['income_tax_base', 'withholding_tax', 'residence_tax', 'other_deductions', 'total_deductions'],
        __('Payout') => ['net_payment', 'hand_delivered_salary', 'bank_transfer_salary'],
        __('Staff movements') => ['new_hires', 'resigned_staff'],
    ])

    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $editing ? __('Edit the salary report') : __('New salary report') }}</flux:heading>
            @if ($store)
                <flux:subheading>{{ $store->name }}@if ($report) - {{ $report->target_month }} - {{ $report->employee?->name ?? __('Whole store') }}@endif</flux:subheading>
            @endif
        </div>
        <flux:button variant="filled" :href="$editing ? route('salary-report.show', $report->id) : route('salary-report.index', ['store' => $storeId ?: null])" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6" data-test="salary-form">
        @unless ($editing)
            <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('Scope and period') }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="storeId" :label="__('Store')" data-test="store-select">
                        @foreach ($stores as $id => $name)
                            <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="employeeId" :label="__('Report for')" data-test="employee-select">
                        <flux:select.option value="">{{ __('Whole store') }}</flux:select.option>
                        @foreach ($members as $id => $name)
                            <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="periodMode" :label="__('Period')">
                        <flux:select.option value="month">{{ __('Month') }}</flux:select.option>
                        <flux:select.option value="custom">{{ __('Custom range') }}</flux:select.option>
                    </flux:select>
                    @if ($periodMode === 'custom')
                        <div class="grid grid-cols-2 gap-2">
                            <flux:input wire:model.live="from" type="date" :label="__('From')" />
                            <flux:input wire:model.live="to" type="date" :label="__('To')" />
                        </div>
                        <flux:error name="from" />
                    @else
                        <flux:input wire:model.live="month" type="month" :label="__('Month')" data-test="month-input" />
                    @endif
                </div>
                <flux:error name="month" />
                <flux:error name="employeeId" />
            </div>
        @endunless

        <flux:callout variant="secondary" icon="information-circle">
            <flux:callout.text>
                {{ $report?->user_id || $employeeId !== '' ? __('This report only covers this employee: hours and pay come from their own shifts.') : __('This report covers the whole store: hours and pay come from the shifts of all its employees.') }}
                {{ __('Total payments are not calculated: enter them by hand.') }}
            </flux:callout.text>
        </flux:callout>

        <div class="flex flex-wrap gap-2">
            <flux:modal.trigger name="confirm-salary-report-recalculate">
                <flux:button variant="filled" icon="arrow-path" data-test="recalculate-button">{{ __('Recalculate') }}</flux:button>
            </flux:modal.trigger>
            @if ($detail !== [])
                <flux:modal.trigger name="salary-report-detail">
                    <flux:button variant="filled" icon="calculator" data-test="detail-button">{{ __('See the calculation details') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Basic information') }}</flux:heading>
            <flux:select wire:model="personInChargeId" :label="__('Person in charge')" data-test="person-select">
                <flux:select.option value="">—</flux:select.option>
                @foreach ($managers as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="personInChargeId" />
        </div>

        @foreach ($sections as $title => $fields)
            <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="lg">{{ $title }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ($fields as $field)
                        <flux:input wire:model="values.{{ $field }}" type="number" step="0.01" min="0" :label="$labels[$field]" data-test="field-{{ $field }}" />
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($employeeHours !== [])
            <div class="space-y-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('Employee hours') }}</flux:heading>
                <ul class="text-sm">
                    @foreach ($employeeHours as $row)
                        <li>{{ $names[$row['user_id']] ?? '#'.$row['user_id'] }}: {{ round($row['minutes'] / 60, 1) }} h</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:textarea wire:model="hireRegistrations" :label="__('Hiring records')" rows="3" />
            <flux:textarea wire:model="remarks" :label="__('Remarks')" rows="3" />
            <flux:error name="values.*" />
        </div>

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="save-report-button">{{ $editing ? __('Save') : __('Create the report') }}</flux:button>
        </div>
    </form>

    <flux:modal name="confirm-salary-report-recalculate" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Recalculate?') }}</flux:heading>
                <flux:subheading>{{ __('The values below will be replaced by a new calculation from the shifts.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:modal.close><flux:button variant="primary" wire:click="calculate" data-test="confirm-recalculate-button">{{ __('Recalculate') }}</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    @if ($detail !== [] && $store)
        <flux:modal name="salary-report-detail" class="max-w-3xl">
            <flux:heading size="lg">{{ __('Calculation details') }}</flux:heading>
            <flux:subheading>{{ $detail['from'] }} - {{ $detail['to'] }}</flux:subheading>
            <div data-test="detail">
                @include('salary-report::partials.detail', ['detail' => $detail, 'store' => $store])
            </div>
        </flux:modal>
    @endif
</section>
