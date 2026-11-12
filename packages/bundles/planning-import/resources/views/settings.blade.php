<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Schedule import settings') }}</flux:heading>
            <flux:subheading>{{ __('Grid of the Excel file of a store and automatic break') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('planning-import.index', ['store' => $store])" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="settings-form">
        @if (count($stores) > 1)
            <flux:select wire:model.live="store" :label="__('Store')">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Grid of the file') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="colStart" type="number" min="1" :label="__('First column')" :description="__('Number of the first time column (4 = column D).')" />
                <flux:input wire:model="colEnd" type="number" min="1" :label="__('Last column')" :description="__('Number of the last time column (52 = column AZ).')" />
                <flux:input wire:model="baseHour" type="number" min="0" max="23" :label="__('Starting hour')" :description="__('Hour of the first column (6 = 06:00).')" />
                <flux:input wire:model="minutesPerCol" type="number" min="1" :label="__('Minutes per column')" :description="__('Duration of one column (30 = half an hour).')" />
                <flux:input wire:model="blockSize" type="number" min="2" :label="__('Rows per day')" :description="__('Height of the block of one day, in rows.')" />
                <flux:input wire:model="shiftRows" type="number" min="1" :label="__('Shift rows per day')" :description="__('Number of rows of a block that hold shifts.')" />
            </div>
            <flux:input wire:model="sheetPattern" :label="__('Sheet name pattern')" :description="__('Regular expression of the sheets to read; when none matches, every sheet is read.')" />
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Automatic break') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="autoPauseAfter" type="number" min="0" :label="__('Break from (minutes)')" :description="__('Imported shifts at least this long get a break; 0 turns it off.')" />
                <flux:input wire:model="autoPause" type="number" min="0" :label="__('Break length (minutes)')" />
            </div>
        </div>

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="save-settings-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
