<section class="w-full max-w-4xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Time clock') }}</flux:heading>
            <flux:subheading>{{ __('Clock in when you start and clock out when you finish') }}</flux:subheading>
        </div>
        @can('timeclock.view')
            <flux:button variant="filled" :href="route('timeclock.manage')" wire:navigate data-test="manage-link">{{ __('Time clock entries') }}</flux:button>
        @endcan
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="clock-panel">
        @if ($active)
            <div data-test="clock-status">
                <flux:badge color="green">{{ __('On duty') }}</flux:badge>
                <flux:text>{{ __('Clocked in since :time (:store)', ['time' => $active->localIn()->format('Y-m-d H:i'), 'store' => $active->store->name]) }}</flux:text>
            </div>
            <flux:button variant="danger" wire:click="clockOut" data-test="clock-out-button">{{ __('Clock out') }}</flux:button>
        @else
            <flux:text data-test="clock-status">{{ __('Not clocked in yet') }}</flux:text>
            @if (count($stores) > 1)
                <flux:select wire:model="storeId" :label="__('Store')" class="max-w-xs">
                    @foreach ($stores as $id => $name)
                        <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:button variant="primary" wire:click="clockIn" data-test="clock-in-button">{{ __('Clock in') }}</flux:button>
        @endif
        @error('clock') <flux:text class="text-red-500" data-test="clock-error">{{ $message }}</flux:text> @enderror
        @error('store_id') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
    </div>

    <div class="space-y-2">
        <flux:heading size="lg">{{ __('This week') }}</flux:heading>
        <flux:text data-test="week-total">{{ __('Total: :hours h :minutes min', ['hours' => intdiv($weekMinutes, 60), 'minutes' => $weekMinutes % 60]) }}</flux:text>

        @if ($week->isEmpty())
            <flux:text data-test="no-entries">{{ __('No time clock entries.') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Store') }}</flux:table.column>
                    <flux:table.column>{{ __('Clock-in') }}</flux:table.column>
                    <flux:table.column>{{ __('Clock-out') }}</flux:table.column>
                    <flux:table.column>{{ __('Duration (min)') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($week as $entry)
                        <flux:table.row :key="$entry->id" data-test="clock-row">
                            <flux:table.cell>{{ $entry->shift_date->toDateString() }}</flux:table.cell>
                            <flux:table.cell>{{ $entry->store->name }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $entry->localIn()->format('H:i') }}
                                @if ($late = $entry->lateMinutes($threshold))
                                    <flux:badge size="sm" color="amber" data-test="late-badge">{{ __('Late: :minutes min', ['minutes' => $late]) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $entry->localOut()?->format('H:i') ?? __('Running') }}</flux:table.cell>
                            <flux:table.cell>{{ $entry->duration_minutes ?? '' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
