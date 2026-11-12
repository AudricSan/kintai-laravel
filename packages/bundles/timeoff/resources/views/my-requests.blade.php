<section class="w-full max-w-4xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('My time off') }}</flux:heading>
            <flux:subheading>{{ __('Ask for a leave and follow its approval') }}</flux:subheading>
        </div>
        @if ($canManage)
            <flux:button variant="filled" :href="route('timeoff.manage')" wire:navigate data-test="manage-link">{{ __('Time off requests') }}</flux:button>
        @endif
    </div>

    <form wire:submit="submit" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="timeoff-form">
        <flux:heading size="lg">{{ __('New leave') }}</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2">
            @if (count($stores) > 1)
                <flux:select wire:model="storeId" :label="__('Store')">
                    @foreach ($stores as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:select wire:model="type" :label="__('Type')">
                @foreach ($types as $option)
                    <flux:select.option :value="$option">{{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::typeLabel($option)) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="startDate" type="date" :label="__('From')" />
            <flux:input wire:model="endDate" type="date" :label="__('To')" :description="__('optional')" />
        </div>
        <flux:textarea wire:model="reason" :label="__('Reason')" :placeholder="__('Ex: wedding, doctor...')" rows="2" />
        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="send-timeoff-button">{{ __('Send request') }}</flux:button>
        </div>
    </form>

    @if ($requests->isEmpty())
        <flux:text data-test="no-requests">{{ __('No time off requests.') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('From') }}</flux:table.column>
                <flux:table.column>{{ __('To') }}</flux:table.column>
                <flux:table.column>{{ __('Store') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($requests as $request)
                    <flux:table.row :key="$request->id" data-test="timeoff-row" class="{{ $request->id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                        <flux:table.cell>{{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::typeLabel($request->type)) }}</flux:table.cell>
                        <flux:table.cell>{{ $request->start_date->toDateString() }}</flux:table.cell>
                        <flux:table.cell>{{ $request->end_date->toDateString() }}</flux:table.cell>
                        <flux:table.cell>{{ $request->store->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="match ($request->status) { 'approved' => 'green', 'refused' => 'red', 'cancelled' => 'zinc', default => 'amber' }" data-test="timeoff-status">{{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::statusLabel($request->status)) }}</flux:badge>
                            @if ($request->admin_note)
                                <flux:text size="sm">{{ $request->admin_note }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($request->isPending())
                                <flux:button size="sm" variant="danger" wire:click="confirmCancellation({{ $request->id }})" data-test="cancel-timeoff-button">{{ __('Cancel') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="confirm-timeoff-cancellation" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Cancel this request?') }}</flux:heading>
                <flux:subheading>{{ __('Your manager will not see it anymore.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Keep it') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="cancel" data-test="confirm-cancel-timeoff-button">{{ __('Cancel the request') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
