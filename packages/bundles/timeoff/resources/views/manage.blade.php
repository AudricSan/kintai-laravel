<section class="w-full space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Time off requests') }}</flux:heading>
            <flux:subheading>{{ __('Requests and leaves of the stores you manage') }}</flux:subheading>
        </div>
        @if ($canCreate)
            <flux:modal.trigger name="create-timeoff">
                <flux:button variant="primary" icon="plus" data-test="new-leave-button">{{ __('Create a leave') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach ($statuses as $option)
                <flux:select.option :value="$option">{{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::statusLabel($option)) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="type" class="max-w-xs">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach ($types as $option)
                <flux:select.option :value="$option">{{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::typeLabel($option)) }}</flux:select.option>
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
            <flux:table.column>{{ __('Employee') }}</flux:table.column>
            <flux:table.column>{{ __('Store') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('From') }}</flux:table.column>
            <flux:table.column>{{ __('To') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($requests as $request)
                <flux:table.row :key="$request->id" data-test="timeoff-row" class="{{ $request->id === $highlight ? 'bg-blue-50 dark:bg-blue-950' : '' }}">
                    <flux:table.cell>
                        {{ $request->user->name }}
                        @if ($request->reason)
                            <flux:text size="sm">{{ $request->reason }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $request->store->name }}</flux:table.cell>
                    <flux:table.cell>{{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::typeLabel($request->type)) }}</flux:table.cell>
                    <flux:table.cell>{{ $request->start_date->toDateString() }}</flux:table.cell>
                    <flux:table.cell>{{ $request->end_date->toDateString() }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="match ($request->status) { 'approved' => 'green', 'refused' => 'red', 'cancelled' => 'zinc', default => 'amber' }" data-test="timeoff-status">{{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::statusLabel($request->status)) }}</flux:badge>
                        @if ($request->processor && $request->processed_at)
                            <flux:text size="sm">{{ __('Handled by :name on :date', ['name' => $request->processor->name, 'date' => $request->processed_at->timezone(config('kintai.timezone', 'UTC'))->format('Y-m-d')]) }}</flux:text>
                        @endif
                        @if ($request->admin_note)
                            <flux:text size="sm">{{ $request->admin_note }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-2">
                            @if ($request->isPending() && $access->canApprove($user, $request))
                                <flux:button size="sm" variant="primary" wire:click="approve({{ $request->id }})" data-test="approve-timeoff-button">{{ __('Approve') }}</flux:button>
                                <flux:button size="sm" variant="filled" wire:click="confirmRefusal({{ $request->id }})" data-test="refuse-timeoff-button">{{ __('Refuse') }}</flux:button>
                            @endif
                            @if ($access->canDelete($user, $request))
                                <flux:button size="sm" variant="danger" wire:click="confirmDeletion({{ $request->id }})" data-test="delete-timeoff-button">{{ __('Delete') }}</flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">{{ __('No time off requests.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="confirm-timeoff-refusal" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Refuse this request?') }}</flux:heading>
                <flux:subheading>{{ __('The employee is notified. You can explain your decision.') }}</flux:subheading>
            </div>
            <flux:textarea wire:model="note" :label="__('Note for the employee')" :description="__('optional')" rows="2" />
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="refuse" data-test="confirm-refuse-timeoff-button">{{ __('Refuse') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-timeoff-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this leave?') }}</flux:heading>
                <flux:subheading>{{ __('It disappears from the schedule and cannot be recovered, even if it was approved.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-timeoff-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @if ($canCreate)
        <flux:modal name="create-timeoff" class="max-w-lg">
            <form wire:submit="create" class="space-y-4" data-test="create-timeoff-form">
                <div>
                    <flux:heading size="lg">{{ __('Create a leave') }}</flux:heading>
                    <flux:subheading>{{ __('The leave is approved right away and blocks shifts on these days.') }}</flux:subheading>
                </div>
                <flux:select wire:model="target" :label="__('Employee')" :placeholder="__('Choose an employee')">
                    @foreach ($targets as $key => $label)
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="newType" :label="__('Type')">
                    @foreach ($types as $option)
                        <flux:select.option :value="$option">{{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::typeLabel($option)) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="newStart" type="date" :label="__('From')" />
                    <flux:input wire:model="newEnd" type="date" :label="__('To')" :description="__('optional')" />
                </div>
                <flux:textarea wire:model="newReason" :label="__('Reason')" rows="2" />
                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="save-leave-button">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
