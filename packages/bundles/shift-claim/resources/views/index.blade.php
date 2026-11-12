<section class="w-full max-w-5xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Open shifts') }}</flux:heading>
            <flux:subheading>{{ __('Shifts you can apply for in your stores') }}</flux:subheading>
        </div>
        @if ($canManage)
            <flux:button variant="filled" :href="route('shift-claim.manage')" wire:navigate data-test="manage-link">{{ __('Shift applications') }}</flux:button>
        @endif
    </div>

    @if ($shifts->isEmpty())
        <flux:text data-test="no-open-shift">{{ __('No open shift at the moment.') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Hours') }}</flux:table.column>
                <flux:table.column>{{ __('Store') }}</flux:table.column>
                <flux:table.column>{{ __('Note') }}</flux:table.column>
                <flux:table.column>{{ __('My application') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($shifts as $shift)
                    @php($mine = $claims->get($shift->id))
                    <flux:table.row :key="$shift->id" data-test="open-shift-row" class="{{ $shift->id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                        <flux:table.cell>{{ $shift->shift_date->toDateString() }}</flux:table.cell>
                        <flux:table.cell>{{ $shift->localStartsAt->format('H:i') }}-{{ $shift->localEndsAt->format('H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $shift->store->name }}</flux:table.cell>
                        <flux:table.cell>{{ $offered->get($shift->id) ?: $shift->notes }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($mine)
                                <flux:badge size="sm" :color="\Kintai\Bundles\ShiftClaim\Models\ShiftClaim::statusColor($mine->status)" data-test="claim-status">{{ __(\Kintai\Bundles\ShiftClaim\Models\ShiftClaim::statusLabel($mine->status)) }}</flux:badge>
                            @elseif ($shift->user_id === $user->id)
                                <flux:badge size="sm" color="zinc" data-test="own-shift">{{ __('Your shift, on offer') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($mine?->isPending())
                                <flux:button size="sm" variant="filled" wire:click="confirmWithdrawal({{ $mine->id }})" data-test="withdraw-claim-button">{{ __('Withdraw') }}</flux:button>
                            @elseif ($shift->user_id !== $user->id && ! in_array($mine?->status, ['approved'], true))
                                <flux:button size="sm" variant="primary" wire:click="confirmClaim({{ $shift->id }})" data-test="claim-shift-button">{{ __('Apply') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @if ($history->isNotEmpty())
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('My earlier applications') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Shift') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($history as $claim)
                        <flux:table.row :key="'h'.$claim->id" data-test="history-row">
                            <flux:table.cell>{{ \Kintai\Bundles\ShiftClaim\Models\ShiftClaim::describe($claim->shift) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="\Kintai\Bundles\ShiftClaim\Models\ShiftClaim::statusColor($claim->status)">{{ __(\Kintai\Bundles\ShiftClaim\Models\ShiftClaim::statusLabel($claim->status)) }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="confirm-shift-claim" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Apply for this shift?') }}</flux:heading>
                <flux:subheading>{{ __('A manager picks one application. You are notified of the decision.') }}</flux:subheading>
            </div>
            <flux:textarea wire:model="note" :label="__('Note')" :description="__('optional')" rows="2" />
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="claim" data-test="confirm-claim-button">{{ __('Apply') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-claim-withdrawal" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Withdraw this application?') }}</flux:heading>
                <flux:subheading>{{ __('You can apply again as long as the shift is open.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Keep it') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="withdraw" data-test="confirm-withdraw-claim-button">{{ __('Withdraw') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
