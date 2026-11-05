<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('New photo upload') }}</flux:heading>
            <flux:subheading>{{ __('Photos sent on the same day for a store are added to the same report') }}</flux:subheading>
        </div>
        <flux:button variant="filled" :href="route('store-photos.index')" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="submit" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="upload-form">
        @if (count($stores) > 1)
            <flux:select wire:model="storeId" :label="__('Store')">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:field>
            <flux:label>{{ __('Select the photos') }}</flux:label>
            <input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/gif,image/webp" data-test="photos-input"
                   class="block w-full text-sm text-zinc-700 dark:text-zinc-300">
            <flux:description>{{ __('JPEG, PNG, GIF or WebP, 10 MB per photo, 20 photos and 40 MB at most.') }}</flux:description>
            <flux:error name="photos" />
            <flux:error name="photos.*" />
        </flux:field>

        @if ($photos !== [])
            <ul class="space-y-1 text-sm" data-test="selected-photos">
                @foreach ($photos as $index => $photo)
                    <li class="flex items-center justify-between gap-2" wire:key="photo-{{ $index }}">
                        <span class="truncate">{{ $photo->getClientOriginalName() }}</span>
                        <flux:button size="xs" variant="ghost" wire:click="removePhoto({{ $index }})">{{ __('Remove') }}</flux:button>
                    </li>
                @endforeach
            </ul>
        @endif

        <flux:textarea wire:model="notes" :label="__('Notes')" :placeholder="__('Optional notes about this upload...')" rows="3" />

        <div class="flex items-center justify-end gap-3">
            <flux:text wire:loading wire:target="photos,submit" size="sm">{{ __('Uploading...') }}</flux:text>
            <flux:button variant="primary" type="submit" data-test="send-photos-button">{{ __('Send the photos') }}</flux:button>
        </div>
    </form>
</section>
