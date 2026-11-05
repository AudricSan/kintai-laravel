<section class="w-full space-y-6 p-4">
    <div>
        <flux:heading size="xl">{{ __('Time clock entries') }}</flux:heading>
        <flux:subheading>{{ __('Clock-ins and clock-outs of the stores you manage') }}</flux:subheading>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live="date" type="date" class="max-w-xs" :label="__('Date')" />
        <flux:select wire:model.live="store" class="max-w-xs" :label="__('Store')">
            <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
            @foreach ($stores as $id => $name)
                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$entries">
        <flux:table.columns>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('Employee') }}</flux:table.column>
            <flux:table.column>{{ __('Store') }}</flux:table.column>
            <flux:table.column>{{ __('Clock-in') }}</flux:table.column>
            <flux:table.column>{{ __('Clock-out') }}</flux:table.column>
            <flux:table.column>{{ __('Duration (min)') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($entries as $entry)
                <flux:table.row :key="$entry->id" data-test="clock-row">
                    <flux:table.cell>{{ $entry->shift_date->toDateString() }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $entry->user->name }}
                        @if ($entry->notes)
                            <flux:text size="sm">{{ $entry->notes }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $entry->store->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $entry->localIn()->format('H:i') }}
                        @if ($late = $entry->lateMinutes($threshold))
                            <flux:badge size="sm" color="amber" data-test="late-badge">{{ __('Late: :minutes min', ['minutes' => $late]) }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($entry->isActive())
                            <flux:badge size="sm" color="green">{{ __('Running') }}</flux:badge>
                        @else
                            {{ $entry->localOut()?->format('H:i') }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $entry->duration_minutes ?? '' }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-2">
                            @if ($access->canUpdate($user, $entry))
                                <flux:button size="sm" variant="filled" wire:click="startEditing({{ $entry->id }})" data-test="edit-clock-button">{{ __('Edit') }}</flux:button>
                            @endif
                            @if ($access->canDelete($user, $entry))
                                <flux:button size="sm" variant="danger" wire:click="confirmDeletion({{ $entry->id }})" data-test="delete-clock-button">{{ __('Delete') }}</flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">{{ __('No time clock entries.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="edit-timeclock" class="max-w-lg">
        <form wire:submit="saveEditing" class="space-y-4" data-test="edit-clock-form">
            <flux:heading size="lg">{{ __('Edit time clock entry') }}</flux:heading>
            <flux:input wire:model="editIn" type="datetime-local" :label="__('Clock-in')" />
            <flux:input wire:model="editOut" type="datetime-local" :label="__('Clock-out')" :description="__('Leave empty if still running')" />
            <flux:textarea wire:model="editNotes" :label="__('Notes')" rows="2" />
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-clock-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-timeclock-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this time clock entry?') }}</flux:heading>
                <flux:subheading>{{ __('It cannot be recovered.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-clock-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
