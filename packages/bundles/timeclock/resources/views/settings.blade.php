<section class="w-full max-w-xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Overtime settings') }}</flux:heading>
            <flux:subheading>{{ __('Hours worked beyond these thresholds are counted as overtime. Set them according to the rules that apply to your business.') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('timeclock.manage')" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="settings-form">
        <flux:switch wire:model="enabled" :label="__('Track overtime')" :description="__('Shows overtime to employees and managers, and notifies them when a threshold is reached.')" />
        <flux:input wire:model="dailyHours" type="number" min="0" max="24" :label="__('Daily threshold (hours)')" :description="__('0 for no daily threshold.')" />
        <flux:input wire:model="weeklyHours" type="number" min="0" max="168" :label="__('Weekly threshold (hours)')" :description="__('0 for no weekly threshold. Hours already counted as daily overtime are not counted twice.')" />
        <flux:select wire:model="weekStart" :label="__('First day of the week')">
            @foreach ($days as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="save-settings-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
