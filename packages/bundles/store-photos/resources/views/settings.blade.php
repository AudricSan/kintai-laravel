<section class="w-full max-w-xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Photo settings') }}</flux:heading>
            <flux:subheading>{{ __('Retention and clean-up of the photo reports') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('store-photos.index')" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="settings-form">
        <flux:input wire:model="retentionDays" type="number" min="1" max="365" :label="__('Retention period (days)')" :description="__('Days before the report is archived and the Owners are notified.')" />
        <flux:input wire:model="cleanupDelay" type="number" min="1" max="365" :label="__('Clean-up delay (days)')" :description="__('Days after archiving before the photos are permanently deleted.')" />
        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="save-settings-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
