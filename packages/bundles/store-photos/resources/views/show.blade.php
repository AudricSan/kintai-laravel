<section class="w-full max-w-6xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" data-test="report-title">{{ $submission->store->name }} — {{ $submission->created_at?->timezone(config('kintai.timezone', 'UTC'))->format('Y-m-d') }}</flux:heading>
            @if ($submission->author)
                <flux:subheading>{{ __('Sent by :name', ['name' => $submission->author->name]) }}</flux:subheading>
            @endif
        </div>
        <div class="flex gap-2">
            <flux:button variant="filled" :href="route('store-photos.index')" wire:navigate>{{ __('Back') }}</flux:button>
            @if ($canDelete)
                <flux:button variant="danger" wire:click="confirmDeletion" data-test="delete-report-button">{{ __('Delete') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($submission->notes)
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <strong>{{ __('Notes') }}</strong>
            <p class="mt-1 whitespace-pre-line text-sm" data-test="notes">{{ $submission->notes }}</p>
        </div>
    @endif

    @if ($images->isEmpty())
        <flux:text data-test="no-images">{{ __('No images in this report.') }}</flux:text>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($images as $index => $image)
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700" wire:key="image-{{ $image->id }}" data-test="image-card">
                    <button type="button" wire:click="open({{ $index }})" class="block w-full" data-test="open-image-button">
                        <img src="{{ route('store-photos.image', ['image' => $image->id, 'v' => $versions[$image->id] ?? 0]) }}" alt="{{ $image->filename }}" loading="lazy" class="h-56 w-full object-cover">
                    </button>
                    <div class="space-y-1 p-3 text-xs text-zinc-500">
                        <div class="truncate">{{ $image->filename }}</div>
                        <div>{{ number_format($image->filesize / 1024, 1) }} KB · {{ $image->width }}×{{ $image->height }}</div>
                        @if ($canRotate)
                            <div class="flex gap-1">
                                <flux:button size="xs" variant="ghost" wire:click="rotate({{ $image->id }}, true)" :aria-label="__('Rotate left')" data-test="rotate-left-button">⟲</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="rotate({{ $image->id }}, false)" :aria-label="__('Rotate right')" data-test="rotate-right-button">⟳</flux:button>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <flux:modal name="photo-viewer" class="max-w-5xl">
        @if ($viewed)
            <div class="space-y-3" data-test="photo-viewer">
                <img src="{{ route('store-photos.image', ['image' => $viewed->id, 'v' => $versions[$viewed->id] ?? 0]) }}" alt="{{ $viewed->filename }}" class="mx-auto max-h-[75vh] w-auto">
                <div class="flex items-center justify-between gap-2">
                    <flux:button variant="filled" wire:click="step(-1)" data-test="previous-image-button">&lsaquo;</flux:button>
                    <span class="truncate text-sm">{{ $viewed->filename }} ({{ $viewing + 1 }}/{{ $images->count() }})</span>
                    <flux:button variant="filled" wire:click="step(1)" data-test="next-image-button">&rsaquo;</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="confirm-photo-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this photo report?') }}</flux:heading>
                <flux:subheading>{{ __('The photos are deleted for good and cannot be recovered.') }}</flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-report-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
