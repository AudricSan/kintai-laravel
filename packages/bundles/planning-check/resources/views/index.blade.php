<section class="w-full max-w-5xl space-y-6 p-4">
    @php($statusColor = fn (string $status): string => match ($status) { 'validated' => 'green', 'submitted' => 'amber', 'rejected' => 'red', 'escalated' => 'orange', default => 'zinc' })
    @php($isOpen = $tomorrow === null || $tomorrow->isOpen())

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Validation of tomorrow\'s schedule') }}</flux:heading>
            <flux:subheading>{{ __('Confirm the shifts of the next day before the deadline') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            @if (count($stores) > 1)
                <flux:select wire:model.live="store" class="max-w-xs" data-test="store-select">
                    @foreach ($stores as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            @if ($canSettings)
                <flux:button variant="filled" icon="cog-6-tooth" :href="route('planning-check.settings', ['store' => $store])" wire:navigate data-test="settings-link">{{ __('Settings') }}</flux:button>
            @endif
        </div>
    </div>

    @if (! $setting->enabled)
        <flux:callout variant="secondary" icon="information-circle" data-test="disabled-hint">
            <flux:callout.text>{{ __('The validation is turned off for this store: turn it on in the settings.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="tomorrow-panel">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg">{{ __('Shifts of :date', ['date' => $date]) }}</flux:heading>
                <flux:badge size="sm" :color="$statusColor($tomorrow?->status ?? 'pending')" data-test="tomorrow-status">{{ __(\Kintai\Bundles\PlanningCheck\Models\PlanningCheck::statusLabel($tomorrow?->status ?? 'pending')) }}</flux:badge>
                @if ($late)
                    <flux:badge size="sm" color="red" data-test="late-badge">{{ __('late') }}</flux:badge>
                @endif
                <flux:text size="sm">{{ __('Deadline :time', ['time' => $setting->deadline_time]) }}</flux:text>
            </div>

            @if (! $working)
                <flux:text size="sm" data-test="not-working-day">{{ __('The store does not work on this day: no validation is expected.') }}</flux:text>
            @endif

            @if ($tomorrow?->status === 'rejected')
                <flux:callout variant="danger" icon="arrow-uturn-left" data-test="reject-notice">
                    <flux:callout.text>{{ __('Refused, to correct:') }} {{ $tomorrow->reject_reason }}</flux:callout.text>
                </flux:callout>
            @endif

            @include('planning-check::partials.day', ['day' => $day])

            @if ($shiftCount === 0)
                <flux:text size="sm" data-test="no-shifts">{{ __('No shift entered for tomorrow.') }}</flux:text>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                @if ($isOpen && ($canSubmit || $canApprove))
                    @if ($shiftCount === 0)
                        <flux:checkbox wire:model="confirmEmpty" :label="__('No shift tomorrow: I confirm the store is not working.')" data-test="confirm-empty" />
                    @endif
                @endif
                @if ($isOpen && $canSubmit)
                    <flux:button variant="primary" wire:click="submit" data-test="submit-check-button">{{ __('Yes, the schedule is correct') }}</flux:button>
                    @if ($canEditSchedule)
                        <flux:button variant="filled" :href="route('shifts.timeline')" data-test="edit-schedule-link">{{ __('No, edit the schedule') }}</flux:button>
                    @endif
                @endif
                @if ($tomorrow?->status === 'submitted' && $canApprove)
                    <flux:button variant="primary" wire:click="confirm('approve', {{ $tomorrow->id }})" data-test="approve-check-button">{{ __('Validate and send the PDF') }}</flux:button>
                    <flux:button variant="danger" wire:click="confirm('reject', {{ $tomorrow->id }})" data-test="reject-check-button">{{ __('Refuse') }}</flux:button>
                @endif
                @if ($canApprove)
                    <flux:button variant="filled" icon="paper-airplane" wire:click="confirm('send-now')" data-test="send-now-button">{{ $tomorrow?->status === 'validated' ? __('Regenerate and send again') : __('Send now') }}</flux:button>
                @endif
                @if (in_array($tomorrow?->status, ['submitted', 'validated'], true) && $canSubmit)
                    <flux:button variant="filled" wire:click="confirm('reopen', {{ $tomorrow->id }})" data-test="reopen-check-button">{{ __('Check tomorrow\'s schedule again') }}</flux:button>
                @endif
            </div>
            @error('check') <flux:text class="text-red-600" data-test="check-error">{{ $message }}</flux:text> @enderror
        </div>
    @endif

    <div class="space-y-2">
        <flux:heading size="lg">{{ __('Schedule validations') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Day') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Submitted') }}</flux:table.column>
                <flux:table.column>{{ __('Validated') }}</flux:table.column>
                <flux:table.column>{{ __('Sending') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($history as $check)
                    <flux:table.row :key="$check->id" data-test="check-row" class="{{ $check->id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                        <flux:table.cell>{{ $check->target_date->toDateString() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$statusColor($check->status)">{{ __(\Kintai\Bundles\PlanningCheck\Models\PlanningCheck::statusLabel($check->status)) }}</flux:badge>
                            @if ($check->reject_reason)
                                <flux:text size="sm">{{ $check->reject_reason }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($check->submitted_at)
                                {{ $check->submitter?->name ?? '—' }}
                                <flux:text size="sm">{{ $check->submitted_at->timezone($timezone)->format('Y-m-d H:i') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($check->validated_at)
                                {{ $check->validator?->name ?? '—' }}
                                <flux:text size="sm">{{ $check->validated_at->timezone($timezone)->format('Y-m-d H:i') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($check->send_status === 'sent')
                                <flux:badge size="sm" color="green">{{ $check->sent_at?->timezone($timezone)->format('H:i') }}</flux:badge>
                                @if ($resend[$check->id] ?? false)
                                    <flux:badge size="sm" color="amber" data-test="changed-badge">{{ __('changed since') }}</flux:badge>
                                @endif
                            @elseif ($check->send_status === 'failed')
                                <flux:badge size="sm" color="red" data-test="failed-badge">{{ __('Sending failed') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                <flux:button size="sm" variant="filled" :href="route('planning-check.pdf', $check->id)" target="_blank">{{ __('PDF') }}</flux:button>
                                @if ($canApprove && $check->status === 'validated' && ($resend[$check->id] ?? false))
                                    <flux:button size="sm" variant="primary" wire:click="confirm('resend', {{ $check->id }})" data-test="resend-check-button">{{ $check->send_status === 'failed' ? __('Try again') : __('Send an updated version') }}</flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">{{ __('No validation yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="confirm-planning-check-approve" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Validate this schedule?') }}</flux:heading>
                <flux:subheading>{{ __('The PDF is generated and sent by email to the recipients of the store.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="approve" data-test="confirm-approve-button">{{ __('Validate and send the PDF') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-planning-check-reject" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Refuse this schedule?') }}</flux:heading>
                <flux:subheading>{{ __('The submitter is notified and must correct the shifts, then submit again.') }}</flux:subheading>
            </div>
            <flux:textarea wire:model="reason" :label="__('Reason for the refusal')" rows="3" data-test="reject-reason" />
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="reject" data-test="confirm-reject-button">{{ __('Refuse') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-planning-check-send-now" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Send the schedule now?') }}</flux:heading>
                <flux:subheading>{{ $shiftCount === 0 ? __('No shift tomorrow. Send the PDF anyway?') : __('The PDF is generated from the current schedule and sent now to the recipients.') }}</flux:subheading>
            </div>
            @if ($shiftCount === 0)
                <flux:checkbox wire:model="confirmEmpty" :label="__('No shift tomorrow: I confirm the store is not working.')" />
            @endif
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="sendNow" data-test="confirm-send-now-button">{{ __('Send now') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-planning-check-resend" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Send the PDF again?') }}</flux:heading>
                <flux:subheading>{{ __('A new PDF is generated from the current schedule and sent to the recipients.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="resend" data-test="confirm-resend-button">{{ __('Send') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-planning-check-reopen" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Check tomorrow\'s schedule again?') }}</flux:heading>
                <flux:subheading>{{ __('The validation goes back to pending: read the shifts again and submit it anew. The history of sendings is kept.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="reopen" data-test="confirm-reopen-button">{{ __('Reopen') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
