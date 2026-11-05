<section class="w-full space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Swap requests') }}</flux:heading>
            <flux:subheading>{{ __('Shift swaps of the stores you manage') }}</flux:subheading>
        </div>
        @if ($creatable !== [])
            <flux:modal.trigger name="create-swap">
                <flux:button variant="primary" icon="plus" data-test="new-swap-button">{{ __('Apply a swap') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach ($statuses as $option)
                <flux:select.option :value="$option">{{ __(\Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::stageLabel($option)) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="store" class="max-w-xs">
            <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
            @foreach ($stores as $id => $name)
                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="sort" class="max-w-xs">
            <flux:select.option value="desc">{{ __('Latest first') }}</flux:select.option>
            <flux:select.option value="asc">{{ __('Earliest first') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Store') }}</flux:table.column>
            <flux:table.column>{{ __('Requester') }}</flux:table.column>
            <flux:table.column>{{ __('Colleague') }}</flux:table.column>
            <flux:table.column>{{ __('Requester shift') }}</flux:table.column>
            <flux:table.column>{{ __('Colleague shift') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($requests as $swap)
                <flux:table.row :key="$swap->id" data-test="swap-row" class="{{ $swap->id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                    <flux:table.cell>{{ $swap->store->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $swap->requester->name }}
                        @if ($swap->reason)
                            <flux:text size="sm">{{ $swap->reason }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $swap->target->name }}</flux:table.cell>
                    <flux:table.cell>{{ \Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::describe($swap->requesterShift) }}</flux:table.cell>
                    <flux:table.cell>{{ \Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::describe($swap->targetShift) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="\Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::stageColor($swap->stage())" data-test="swap-status">{{ __(\Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::stageLabel($swap->stage())) }}</flux:badge>
                        @if ($swap->approver && $swap->approved_at)
                            <flux:text size="sm">{{ __('Handled by :name on :date', ['name' => $swap->approver->name, 'date' => $swap->approved_at->timezone(config('kintai.timezone', 'UTC'))->format('Y-m-d')]) }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-2">
                            @if ($swap->isPending() && $access->canApprove($user, $swap))
                                @if ($swap->awaitsManager())
                                    <flux:button size="sm" variant="primary" wire:click="approve({{ $swap->id }})" data-test="approve-swap-button">{{ __('Approve') }}</flux:button>
                                @endif
                                <flux:button size="sm" variant="filled" wire:click="confirmRefusal({{ $swap->id }})" data-test="refuse-swap-button">{{ __('Refuse') }}</flux:button>
                            @endif
                            @if (! $swap->isPending() && $access->canDelete($user, $swap))
                                <flux:button size="sm" variant="danger" wire:click="confirmDeletion({{ $swap->id }})" data-test="delete-swap-button">{{ __('Delete') }}</flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">{{ __('No swap requests.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="swap-warnings" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Warnings') }}</flux:heading>
                <flux:subheading>{{ __('The planning raises these warnings. Continue anyway?') }}</flux:subheading>
            </div>
            <ul class="list-disc space-y-1 ps-5" data-test="swap-warnings">
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

    <flux:modal name="confirm-swap-refusal" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Refuse this swap?') }}</flux:heading>
                <flux:subheading>{{ __('The employees concerned are notified and the request is closed.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="refuse" data-test="confirm-refuse-swap-button">{{ __('Refuse') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-swap-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this swap?') }}</flux:heading>
                <flux:subheading>{{ __('If it was applied, the shifts are put back as they were, unless they changed since.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-swap-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @if ($creatable !== [])
        <flux:modal name="create-swap" class="max-w-lg">
            <form wire:submit="createDirect" class="space-y-4" data-test="create-swap-form">
                <div>
                    <flux:heading size="lg">{{ __('Apply a swap') }}</flux:heading>
                    <flux:subheading>{{ __('The swap is applied right away, without waiting for the employees.') }}</flux:subheading>
                </div>
                <flux:select wire:model.live="directStore" :label="__('Store')" :placeholder="__('Choose a store')">
                    @foreach ($creatable as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="firstUser" :label="__('1. First employee')" :placeholder="__('Choose an employee')">
                    @foreach ($employees as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="firstShift" :label="__('Shift of the first employee')" :placeholder="__('Choose a shift')">
                    @foreach ($firstShifts as $shift)
                        <flux:select.option :value="$shift['id']">{{ $shift['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="secondUser" :label="__('2. Second employee')" :placeholder="__('Choose an employee')">
                    @foreach ($employees as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="secondShift" :label="__('Shift of the second employee')" :placeholder="__('Choose a shift')">
                    @foreach ($secondShifts as $shift)
                        <flux:select.option :value="$shift['id']">{{ $shift['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model="directReason" :label="__('Reason')" :description="__('optional')" rows="2" />
                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="save-swap-button">{{ __('Apply') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
