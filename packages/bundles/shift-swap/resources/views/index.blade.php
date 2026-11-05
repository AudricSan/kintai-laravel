<section class="w-full max-w-5xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Shift swaps') }}</flux:heading>
            <flux:subheading>{{ __('Swap a shift with a colleague and follow its approval') }}</flux:subheading>
        </div>
        <div class="flex gap-2">
            @if ($canManage)
                <flux:button variant="filled" :href="route('shift-swap.manage')" wire:navigate data-test="manage-link">{{ __('Swap requests') }}</flux:button>
            @endif
            @if ($canRequest)
                <flux:button variant="primary" icon="plus" :href="route('shift-swap.create')" wire:navigate data-test="new-swap-link">{{ __('Request a swap') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Requests received') }}</flux:heading>
        @if ($received->isEmpty())
            <flux:text data-test="no-received">{{ __('No swap requests.') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('From') }}</flux:table.column>
                    <flux:table.column>{{ __('Their shift') }}</flux:table.column>
                    <flux:table.column>{{ __('My shift') }}</flux:table.column>
                    <flux:table.column>{{ __('Reason') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($received as $swap)
                        <flux:table.row :key="'r'.$swap->id" data-test="received-row" class="{{ $swap->id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                            <flux:table.cell>{{ $swap->requester->name }}</flux:table.cell>
                            <flux:table.cell>{{ \Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::describe($swap->requesterShift) }}</flux:table.cell>
                            <flux:table.cell>{{ \Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::describe($swap->targetShift) }}</flux:table.cell>
                            <flux:table.cell>{{ $swap->reason }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="\Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::stageColor($swap->stage())" data-test="swap-status">{{ __(\Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::stageLabel($swap->stage())) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($swap->awaitsColleague())
                                    <div class="flex gap-2">
                                        <flux:button size="sm" variant="primary" wire:click="accept({{ $swap->id }})" data-test="accept-swap-button">{{ __('Accept') }}</flux:button>
                                        <flux:button size="sm" variant="filled" wire:click="confirmRefusal({{ $swap->id }})" data-test="refuse-swap-button">{{ __('Decline') }}</flux:button>
                                    </div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Requests sent') }}</flux:heading>
        @if ($sent->isEmpty())
            <flux:text data-test="no-sent">{{ __('No swap requests.') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('With') }}</flux:table.column>
                    <flux:table.column>{{ __('My shift') }}</flux:table.column>
                    <flux:table.column>{{ __('Their shift') }}</flux:table.column>
                    <flux:table.column>{{ __('Reason') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($sent as $swap)
                        <flux:table.row :key="'s'.$swap->id" data-test="sent-row" class="{{ $swap->id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                            <flux:table.cell>{{ $swap->target->name }}</flux:table.cell>
                            <flux:table.cell>{{ \Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::describe($swap->requesterShift) }}</flux:table.cell>
                            <flux:table.cell>{{ \Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::describe($swap->targetShift) }}</flux:table.cell>
                            <flux:table.cell>{{ $swap->reason }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="\Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::stageColor($swap->stage())" data-test="swap-status">{{ __(\Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::stageLabel($swap->stage())) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($swap->isPending())
                                    <flux:button size="sm" variant="danger" wire:click="confirmCancellation({{ $swap->id }})" data-test="cancel-swap-button">{{ __('Cancel') }}</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <flux:modal name="confirm-swap-refusal" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Decline this swap?') }}</flux:heading>
                <flux:subheading>{{ __('The colleague who asked is notified and the request is closed.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Keep it') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="refuse" data-test="confirm-refuse-swap-button">{{ __('Decline') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-swap-cancellation" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Cancel this request?') }}</flux:heading>
                <flux:subheading>{{ __('Your colleague and the managers will not see it anymore.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Keep it') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="cancel" data-test="confirm-cancel-swap-button">{{ __('Cancel the request') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
