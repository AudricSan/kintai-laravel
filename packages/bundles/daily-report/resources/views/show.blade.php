<section class="w-full max-w-4xl space-y-6 p-4">
    @php($money = fn ($amount) => \App\Support\Money::forStore($amount, $report->store))
    @php($tz = config('kintai.timezone', 'UTC'))

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">
                {{ __('Daily report') }} - {{ $report->report_date->toDateString() }}
                <flux:badge size="sm" :color="match ($report->status) { 'validated' => 'green', 'submitted' => 'amber', default => 'zinc' }" data-test="report-status">{{ __(\Kintai\Bundles\DailyReport\Models\DailyReport::statusLabel($report->status)) }}</flux:badge>
            </flux:heading>
            <flux:subheading>{{ $report->store->name }}</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button variant="filled" :href="route('daily-report.index', ['store' => $report->store_id])" wire:navigate>{{ __('Back') }}</flux:button>
            <flux:button variant="filled" icon="document-text" :href="route('daily-report.pdf', $report->id)" target="_blank" data-test="pdf-link">{{ __('PDF') }}</flux:button>
            @if ($canEdit)
                <flux:button variant="filled" icon="pencil-square" :href="route('daily-report.edit', $report->id)" wire:navigate data-test="edit-report-button">{{ __('Edit') }}</flux:button>
            @endif
            @if ($canSubmit)
                <flux:button variant="primary" wire:click="confirm('submit')" data-test="submit-report-button">{{ __('Submit') }}</flux:button>
            @endif
            @if ($canValidate)
                <flux:button variant="primary" wire:click="confirm('validate')" data-test="validate-report-button">{{ __('Validate') }}</flux:button>
            @endif
            @if ($canSendMail)
                <flux:button variant="filled" icon="envelope" wire:click="confirm('mail')" data-test="send-mail-button">{{ __('Send by email') }}</flux:button>
            @endif
            @if ($canDelete)
                <flux:button variant="danger" wire:click="confirm('delete')" data-test="delete-report-button">{{ __('Delete') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($noRecipients)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="no-recipients-alert">
            <flux:callout.text>{{ __('No recipient selected: choose employees in the store settings.') }}
                @if ($canSettings)
                    <flux:link :href="route('daily-report.settings', ['store' => $report->store_id])" wire:navigate>{{ __('Settings') }}</flux:link>
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 sm:grid-cols-2">
        <flux:table>
            <flux:table.rows>
                <flux:table.row><flux:table.cell>{{ __('Sales') }}</flux:table.cell><flux:table.cell align="end"><strong data-test="sales-value">{{ $money($report->sales_total) }}</strong></flux:table.cell></flux:table.row>
                <flux:table.row><flux:table.cell>{{ __('Customers') }}</flux:table.cell><flux:table.cell align="end"><strong>{{ number_format($report->customer_count) }}</strong></flux:table.cell></flux:table.row>
                <flux:table.row><flux:table.cell>{{ __('Labor') }}</flux:table.cell><flux:table.cell align="end"><strong>{{ $money($report->labor_cost) }}</strong></flux:table.cell></flux:table.row>
                <flux:table.row><flux:table.cell>{{ __('Waste') }}</flux:table.cell><flux:table.cell align="end"><strong>{{ $money($report->waste_total) }}</strong></flux:table.cell></flux:table.row>
            </flux:table.rows>
        </flux:table>
        <flux:table>
            <flux:table.rows>
                <flux:table.row><flux:table.cell>{{ __('Author') }}</flux:table.cell><flux:table.cell>{{ $report->author?->name ?? '—' }}</flux:table.cell></flux:table.row>
                <flux:table.row><flux:table.cell>{{ __('Created on') }}</flux:table.cell><flux:table.cell>{{ $report->created_at?->timezone($tz)->format('Y-m-d H:i') }}</flux:table.cell></flux:table.row>
                @if ($report->submitted_at)
                    <flux:table.row><flux:table.cell>{{ __('Submitted on') }}</flux:table.cell><flux:table.cell>{{ $report->submitted_at->timezone($tz)->format('Y-m-d H:i') }}</flux:table.cell></flux:table.row>
                @endif
                @if ($report->validated_at)
                    <flux:table.row><flux:table.cell>{{ __('Validated by') }}</flux:table.cell><flux:table.cell data-test="validated-by">{{ $report->validator?->name ?? __('Automatic validation') }}</flux:table.cell></flux:table.row>
                    <flux:table.row><flux:table.cell>{{ __('Validated on') }}</flux:table.cell><flux:table.cell>{{ $report->validated_at->timezone($tz)->format('Y-m-d H:i') }}</flux:table.cell></flux:table.row>
                @endif
                @if ($report->mail_sent_at)
                    <flux:table.row><flux:table.cell>{{ __('Email sent on') }}</flux:table.cell><flux:table.cell data-test="mail-sent-at">{{ $report->mail_sent_at->timezone($tz)->format('Y-m-d H:i') }}</flux:table.cell></flux:table.row>
                @endif
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($report->notes)
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Notes') }}</flux:heading>
            <div class="whitespace-pre-line rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-test="report-notes">{{ $report->notes }}</div>
        </div>
    @endif

    @include('daily-report::partials.shifts', ['rows' => $rows, 'columns' => $setting->columns()])

    <flux:modal name="confirm-daily-report-submit" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Submit this report?') }}</flux:heading>
                <flux:subheading>{{ __('The validators of the store are notified. You cannot submit it again.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="submit" data-test="confirm-submit-button">{{ __('Submit') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-daily-report-validate" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Validate this report?') }}</flux:heading>
                <flux:subheading>{{ __('It can no longer be edited. The author is notified.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="validateReport" data-test="confirm-validate-button">{{ __('Validate') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-daily-report-mail" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Send this report by email?') }}</flux:heading>
                <flux:subheading>{{ __('Each recipient chosen in the store settings receives it with the PDF attached.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="sendMail" data-test="confirm-mail-button">{{ __('Send by email') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-daily-report-delete" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this report?') }}</flux:heading>
                <flux:subheading>{{ __('It is deleted for good and cannot be recovered.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
