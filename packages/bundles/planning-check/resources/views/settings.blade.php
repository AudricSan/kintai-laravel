<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Schedule validation settings') }}</flux:heading>
            <flux:subheading>{{ __('Activation, times, days and recipients of a store') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('planning-check.index', ['store' => $store])" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="settings-form">
        @if (count($stores) > 1)
            <flux:select wire:model.live="store" :label="__('Store')">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:switch wire:model="enabled" :label="__('Activation')" :description="__('Ask every day to validate the schedule of the next day for this store.')" data-test="enabled-switch" />

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Times') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="windowStart" type="time" :label="__('Start of the validation')" :description="__('Local time from which the validation of the next day is expected.')" />
                <flux:input wire:model="deadline" type="time" :label="__('Deadline')" :description="__('Without a submission at this time, the validators and the Owners are notified (no PDF is sent).')" />
            </div>
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Days concerned') }}</flux:heading>
            <flux:text size="sm">{{ __('The target day is tomorrow: untick the days when the store is closed.') }}</flux:text>
            <flux:checkbox.group wire:model="weekdays" data-test="weekdays">
                @foreach ($weekdayNames as $number => $name)
                    <flux:checkbox :value="$number" :label="__($name)" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="weekdays" />
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('PDF recipients') }}</flux:heading>
            <flux:text size="sm">{{ __('Tick the employees of the store who receive the validated schedule (10 at most). Each one gets their own email, with the PDF attached.') }}</flux:text>
            <flux:checkbox.group wire:model="recipients" data-test="recipients">
                @foreach ($candidates as $id => $name)
                    <flux:checkbox :value="$id" :label="$name" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="recipients" />
            <flux:switch wire:model="includeOpen" :label="__('Include the unassigned open shifts in the PDF')" />
        </div>

        <div class="flex flex-wrap justify-between gap-2">
            <div>
                <flux:button variant="filled" icon="paper-airplane" wire:click="sendTest" data-test="send-test-button">{{ __('Email me a test PDF') }}</flux:button>
                <flux:text size="sm">{{ __('Sends tomorrow\'s schedule as it is now, to your address only.') }}</flux:text>
                <flux:error name="test" />
            </div>
            <flux:button variant="primary" type="submit" data-test="save-settings-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
