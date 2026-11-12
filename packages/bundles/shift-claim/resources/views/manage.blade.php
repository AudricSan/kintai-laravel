<section class="w-full space-y-8 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Shift applications') }}</flux:heading>
            <flux:subheading>{{ __('Open shifts and applications of the stores you manage') }}</flux:subheading>
        </div>
        @if ($canPublish)
            <flux:modal.trigger name="offer-shift">
                <flux:button variant="primary" icon="plus" data-test="offer-shift-button">{{ __('Offer a shift') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:select wire:model.live="store" class="max-w-xs">
            <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
            @foreach ($stores as $id => $name)
                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach ($statuses as $option)
                <flux:select.option :value="$option">{{ __(\Kintai\Bundles\ShiftClaim\Models\ShiftClaim::statusLabel($option)) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Available shifts') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Hours') }}</flux:table.column>
                <flux:table.column>{{ __('Store') }}</flux:table.column>
                <flux:table.column>{{ __('Holder') }}</flux:table.column>
                <flux:table.column>{{ __('Applications') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($shifts as $shift)
                    <flux:table.row :key="'s'.$shift->id" data-test="open-shift-row" class="{{ $shift->id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                        <flux:table.cell>{{ $shift->shift_date->toDateString() }}</flux:table.cell>
                        <flux:table.cell>{{ $shift->localStartsAt->format('H:i') }}-{{ $shift->localEndsAt->format('H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $shift->store->name }}</flux:table.cell>
                        <flux:table.cell>{{ $shift->user?->name ?? __('Open shift') }}</flux:table.cell>
                        <flux:table.cell>{{ $claimsByShift->get($shift->id, collect())->where('status', 'pending')->count() }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($offered->has($shift->id) && $access->canPublish($user, $shift->store_id))
                                <flux:button size="sm" variant="filled" wire:click="confirmUnoffer({{ $shift->id }})" data-test="unoffer-shift-button">{{ __('Withdraw from applications') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">{{ __('No open shift at the moment.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Applications') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Applicant') }}</flux:table.column>
                <flux:table.column>{{ __('Shift') }}</flux:table.column>
                <flux:table.column>{{ __('Store') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($claims as $claim)
                    <flux:table.row :key="'c'.$claim->id" data-test="claim-row" class="{{ $claim->shift_id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                        <flux:table.cell>
                            {{ $claim->user->name }}
                            @if ($claim->note)
                                <flux:text size="sm">{{ $claim->note }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ \Kintai\Bundles\ShiftClaim\Models\ShiftClaim::describe($claim->shift) }}</flux:table.cell>
                        <flux:table.cell>{{ $claim->store->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="\Kintai\Bundles\ShiftClaim\Models\ShiftClaim::statusColor($claim->status)" data-test="claim-status">{{ __(\Kintai\Bundles\ShiftClaim\Models\ShiftClaim::statusLabel($claim->status)) }}</flux:badge>
                            @if ($claim->resolver && $claim->resolved_at)
                                <flux:text size="sm">{{ __('Handled by :name on :date', ['name' => $claim->resolver->name, 'date' => $claim->resolved_at->timezone(config('kintai.timezone', 'UTC'))->format('Y-m-d')]) }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($claim->isPending() && $access->canApprove($user, $claim))
                                <div class="flex gap-2">
                                    <flux:button size="sm" variant="primary" wire:click="approve({{ $claim->id }})" data-test="approve-claim-button">{{ __('Approve') }}</flux:button>
                                    <flux:button size="sm" variant="filled" wire:click="confirmRejection({{ $claim->id }})" data-test="reject-claim-button">{{ __('Reject') }}</flux:button>
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">{{ __('No applications.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="claim-warnings" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Warnings') }}</flux:heading>
                <flux:subheading>{{ __('The planning raises these warnings. Continue anyway?') }}</flux:subheading>
            </div>
            <ul class="list-disc space-y-1 ps-5" data-test="claim-warnings">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="confirmWarnings" data-test="confirm-warnings-button">{{ __('Continue anyway') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-claim-rejection" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Reject this application?') }}</flux:heading>
                <flux:subheading>{{ __('The employee is notified.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="reject" data-test="confirm-reject-claim-button">{{ __('Reject') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-unoffer" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Withdraw this shift from applications?') }}</flux:heading>
                <flux:subheading>{{ __('Pending applications are withdrawn and the applicants are notified. The holder keeps the shift.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="unoffer" data-test="confirm-unoffer-button">{{ __('Withdraw from applications') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @if ($canPublish)
        <flux:modal name="offer-shift" class="max-w-lg">
            <form wire:submit="offer" class="space-y-4" data-test="offer-shift-form">
                <div>
                    <flux:heading size="lg">{{ __('Offer a shift') }}</flux:heading>
                    <flux:subheading>{{ __('The holder keeps the shift until you approve an application.') }}</flux:subheading>
                </div>
                <flux:select wire:model="offerShift" :label="__('Shift')" :placeholder="__('Choose a shift')">
                    @foreach ($assigned as $shift)
                        <flux:select.option :value="$shift->id">{{ $shift->shift_date->toDateString() }} {{ $shift->localStartsAt->format('H:i') }}-{{ $shift->localEndsAt->format('H:i') }} · {{ $shift->user?->name }} ({{ $shift->store->name }})</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($assigned->isEmpty())
                    <flux:text size="sm" data-test="no-offerable-shift">{{ __('No upcoming shift can be offered.') }}</flux:text>
                @endif
                <flux:textarea wire:model="offerNote" :label="__('Note')" :description="__('optional')" :placeholder="__('Ex: looking for someone available that day...')" rows="2" />
                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="save-offer-button">{{ __('Offer') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
