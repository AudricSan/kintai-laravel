<flux:select wire:model.live="nameMode" :label="__('Calendar name in Google')" data-test="google-calendar-name-mode">
    <flux:select.option value="instance">{{ __('Company name') }}</flux:select.option>
    @if ($stores !== [])
        <flux:select.option value="store">{{ __('A store\'s name') }}</flux:select.option>
    @endif
</flux:select>

@if ($stores !== [] && $nameMode === 'store')
    <flux:select wire:model="storeId" :label="__('Store')" data-test="google-calendar-store">
        @foreach ($stores as $id => $name)
            <flux:select.option :value="$id">{{ $name }}</flux:select.option>
        @endforeach
    </flux:select>
@endif
