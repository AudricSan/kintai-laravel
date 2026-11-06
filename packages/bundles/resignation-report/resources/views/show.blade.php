<section class="w-full max-w-4xl space-y-6 p-4">
    @php($tz = config('kintai.timezone', 'UTC'))
    @php($sections = [
        __('Employee') => [
            __('Employee number') => $report->employee_number,
            __('Employee name') => $report->employee_name,
        ],
        __('Resignation') => [
            __('Resignation date') => $report->resignation_date?->format('Y-m-d'),
            __('Reason') => $report->reason,
            __('Resignation notice') => $report->resignation_notice,
            __('Notes') => $report->notes,
            __('Person in charge') => $report->person_in_charge,
        ],
    ])

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Resignation report') }}</flux:heading>
            <flux:subheading>{{ $report->store->name }} - {{ $report->employee_name }}</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button variant="filled" :href="route('resignation-report.index', ['store' => $report->store_id])" wire:navigate>{{ __('Back') }}</flux:button>
            <flux:button variant="filled" icon="document-text" :href="route('resignation-report.pdf', $report->id)" target="_blank" data-test="pdf-link">{{ __('PDF') }}</flux:button>
            @if ($canUpdate)
                <flux:button variant="filled" icon="pencil-square" :href="route('resignation-report.edit', $report->id)" wire:navigate data-test="edit-report-button">{{ __('Edit') }}</flux:button>
            @endif
            @if ($canDelete)
                <flux:button variant="danger" wire:click="confirmDelete" data-test="delete-report-button">{{ __('Delete') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 sm:grid-cols-2">
        @foreach ($sections as $title => $lines)
            <div class="space-y-1">
                <flux:heading size="lg">{{ $title }}</flux:heading>
                <flux:table>
                    <flux:table.rows>
                        @foreach ($lines as $label => $value)
                            <flux:table.row>
                                <flux:table.cell>{{ $label }}</flux:table.cell>
                                <flux:table.cell class="whitespace-pre-line"><strong>{{ filled($value) ? $value : '—' }}</strong></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endforeach
    </div>

    <flux:text>{{ __('Created on') }} {{ $report->created_at?->timezone($tz)->format('Y-m-d H:i') }}@if ($report->creator) - {{ __('by') }} {{ $report->creator->name }}@endif</flux:text>

    <flux:modal name="confirm-resignation-report-delete" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this resignation report?') }}</flux:heading>
                <flux:subheading>{{ __('It is deleted for good and cannot be recovered.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
