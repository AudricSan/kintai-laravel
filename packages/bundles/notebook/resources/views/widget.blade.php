<x-dashboard.card :title="$title" data-test="widget-notebook">
    @forelse ($entries as $entry)
        <div class="mb-3 text-sm" wire:key="note-{{ $entry->id }}" data-test="widget-note">
            <div class="whitespace-pre-line">
                @if ($entry->pinned)
                    <flux:icon.bookmark variant="micro" class="inline" :title="__('Pinned')" />
                @endif
                {{ $entry->content }}
            </div>
            <flux:text size="sm">{{ $entry->author->name }} · {{ $entry->store?->name ?? __('Whole organization') }}</flux:text>
        </div>
    @empty
        <flux:text size="sm" data-test="widget-no-note">{{ __('No notes yet.') }}</flux:text>
    @endforelse

    <flux:link :href="route('notebook.index')" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
</x-dashboard.card>
