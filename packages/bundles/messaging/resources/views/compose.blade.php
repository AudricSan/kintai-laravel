<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('New message') }}</flux:heading>
            <flux:subheading>{{ __('Write to one or more colleagues of a store') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('messaging.index')" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="send" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="compose-form">
        @if (count($stores) > 1)
            <flux:select wire:model.live="storeId" :label="__('Store')">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:checkbox.group wire:model="recipientIds" :label="__('Recipients')" data-test="recipients">
            @foreach ($recipients as $colleague)
                <flux:checkbox :value="$colleague->id" :label="$colleague->name" wire:key="recipient-{{ $colleague->id }}" />
            @endforeach
        </flux:checkbox.group>
        @error('recipientIds') <flux:text size="sm" class="text-red-600">{{ $message }}</flux:text> @enderror
        @if ($recipients === [])
            <flux:text size="sm" data-test="no-recipient">{{ __('This store has no other member to write to.') }}</flux:text>
        @endif

        <flux:input wire:model="subject" :label="__('Subject')" maxlength="255" />
        <flux:textarea wire:model="body" :label="__('Message')" rows="6" />

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="send-message-button">{{ __('Send') }}</flux:button>
        </div>
    </form>
</section>
