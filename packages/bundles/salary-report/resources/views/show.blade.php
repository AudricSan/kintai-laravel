<section class="w-full max-w-4xl space-y-6 p-4">
    @php($money = fn ($amount) => \App\Support\Money::forStore($amount, $report->store))
    @php($tz = config('kintai.timezone', 'UTC'))
    @php($lines = [
        __('Total payments') => $money($report->total_payment),
        __('Staff hours') => rtrim(rtrim($report->staff_man_hours, '0'), '.') ?: '0',
        __('Staff gross pay') => $money($report->staff_total_payment),
        __('Average hourly wage') => $money($report->staff_avg_hourly_wage),
        __('Active employees') => number_format($report->active_employees),
        __('Income tax base') => $money($report->income_tax_base),
        __('Withholding tax') => $money($report->withholding_tax),
        __('Residence tax') => $money($report->residence_tax),
        __('Other deductions') => $money($report->other_deductions),
        __('Total deductions') => $money($report->total_deductions),
        __('Net payment') => $money($report->net_payment),
        __('Salary paid in cash') => $money($report->hand_delivered_salary),
        __('Salary paid by bank transfer') => $money($report->bank_transfer_salary),
        __('New hires') => number_format($report->new_hires),
        __('Departures') => number_format($report->resigned_staff),
    ])

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Salary report') }} - {{ $report->target_month }}</flux:heading>
            <flux:subheading>{{ $report->store->name }} - {{ $report->employee?->name ?? __('Whole store') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button variant="filled" :href="route('salary-report.index', ['store' => $report->store_id])" wire:navigate>{{ __('Back') }}</flux:button>
            @if ($canExport)
                <flux:button variant="filled" icon="document-text" :href="route('salary-report.pdf', $report->id)" target="_blank" data-test="pdf-link">{{ __('PDF') }}</flux:button>
            @endif
            @if ($canEdit)
                <flux:button variant="filled" icon="pencil-square" :href="route('salary-report.edit', $report->id)" wire:navigate data-test="edit-report-button">{{ __('Edit') }}</flux:button>
            @endif
            @if ($canDelete)
                <flux:button variant="danger" wire:click="confirmDelete" data-test="delete-report-button">{{ __('Delete') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 sm:grid-cols-2">
        <flux:table>
            <flux:table.rows>
                @foreach ($lines as $label => $value)
                    <flux:table.row><flux:table.cell>{{ $label }}</flux:table.cell><flux:table.cell align="end"><strong>{{ $value }}</strong></flux:table.cell></flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        <flux:table>
            <flux:table.rows>
                <flux:table.row><flux:table.cell>{{ __('Person in charge') }}</flux:table.cell><flux:table.cell>{{ $report->personInCharge?->name ?? '—' }}</flux:table.cell></flux:table.row>
                <flux:table.row><flux:table.cell>{{ __('Created on') }}</flux:table.cell><flux:table.cell>{{ $report->created_at?->timezone($tz)->format('Y-m-d H:i') }}</flux:table.cell></flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    @if (($report->employee_hours ?? []) !== [])
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Employee hours') }}</flux:heading>
            <ul class="text-sm" data-test="employee-hours">
                @foreach ($report->employee_hours as $row)
                    <li>{{ $names[$row['user_id']] ?? '#'.$row['user_id'] }}: {{ round($row['minutes'] / 60, 1) }} h</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($report->hire_registrations)
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Hiring records') }}</flux:heading>
            <div class="whitespace-pre-line rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">{{ $report->hire_registrations }}</div>
        </div>
    @endif

    @if ($report->remarks)
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Remarks') }}</flux:heading>
            <div class="whitespace-pre-line rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-test="report-remarks">{{ $report->remarks }}</div>
        </div>
    @endif

    @if ($detail !== null)
        <div data-test="detail">
            @include('salary-report::partials.detail', ['detail' => $detail, 'store' => $report->store])
        </div>
    @endif

    <flux:modal name="confirm-salary-report-delete" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this salary report?') }}</flux:heading>
                <flux:subheading>{{ __('It is deleted for good and cannot be recovered.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
