<x-dashboard.card :title="__('Time clock')" data-test="widget-timeclock">
    @if ($active)
        <flux:text size="sm" data-test="widget-clock-status">{{ __('Clocked in since :time (:store)', ['time' => $active->localIn()->format('H:i'), 'store' => $active->store->name]) }}</flux:text>
        <flux:button class="mt-2" size="sm" variant="danger" wire:click="clockOut" data-test="widget-clock-out-button">{{ __('Clock out') }}</flux:button>
    @else
        <flux:text size="sm" data-test="widget-clock-status">{{ __('Not clocked in yet') }}</flux:text>
        @if (count($stores) > 1)
            <flux:select wire:model="storeId" size="sm" class="mt-2">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:button class="mt-2" size="sm" variant="primary" wire:click="clockIn" data-test="widget-clock-in-button">{{ __('Clock in') }}</flux:button>
    @endif
    @error('clock') <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text> @enderror
</x-dashboard.card>
