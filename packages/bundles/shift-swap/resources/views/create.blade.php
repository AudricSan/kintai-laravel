<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Request a swap') }}</flux:heading>
            <flux:subheading>{{ __('Pick one of your upcoming shifts and the shift of a colleague you would like instead') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('shift-swap.index')" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="submit" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="swap-form">
        @if (count($stores) > 1)
            <flux:select wire:model.live="storeId" :label="__('Store')">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:select wire:model.live="colleagueId" :label="__('1. Choose a colleague')" :placeholder="__('Choose a colleague')" data-test="colleague-select">
            @foreach ($colleagues as $colleague)
                <flux:select.option :value="$colleague->id">{{ $colleague->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="myShiftId" :label="__('2. My shift (the one I give)')" :placeholder="__('Choose a shift')" data-test="my-shift-select">
            @foreach ($myShifts as $shift)
                <flux:select.option :value="$shift['id']">{{ $shift['label'] }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($myShifts === [])
            <flux:text size="sm" data-test="no-my-shift">{{ __('You have no upcoming shift.') }}</flux:text>
        @endif

        <flux:select wire:model="theirShiftId" :label="__('3. Their shift (the one I take)')" :placeholder="__('Choose a shift')" data-test="their-shift-select">
            @foreach ($theirShifts as $shift)
                <flux:select.option :value="$shift['id']">{{ $shift['label'] }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($colleagueId !== '' && $theirShifts === [])
            <flux:text size="sm" data-test="no-their-shift">{{ __('This colleague has no upcoming shift.') }}</flux:text>
        @endif

        <flux:textarea wire:model="reason" :label="__('Reason')" :description="__('optional')" :placeholder="__('Ex: medical appointment, family event...')" rows="2" />

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="send-swap-button">{{ __('Send request') }}</flux:button>
        </div>
    </form>
</section>
