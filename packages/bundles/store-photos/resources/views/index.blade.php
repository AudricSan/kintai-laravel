<section class="w-full max-w-6xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Store photos') }}</flux:heading>
            <flux:subheading>{{ __('Photo reports of the sales floor, by store and by day') }}</flux:subheading>
        </div>
        <div class="flex gap-2">
            @if ($isOwner)
                <flux:button variant="filled" :href="route('store-photos.settings')" wire:navigate data-test="settings-link">{{ __('Photo settings') }}</flux:button>
            @endif
            @if ($canCreate)
                <flux:button variant="primary" icon="plus" :href="route('store-photos.create')" wire:navigate data-test="new-upload-link">{{ __('New photo upload') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="storeId" :label="__('Store')" class="max-w-xs" data-test="store-filter">
            <flux:select.option value="0">{{ __('All stores') }}</flux:select.option>
            @foreach ($stores as $id => $name)
                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="date" :label="__('Day')" class="max-w-xs" data-test="day-filter">
            <flux:select.option value="">{{ __('All days') }}</flux:select.option>
            @foreach ($days as $day => $count)
                <flux:select.option :value="$day">{{ $day }} ({{ $count }})</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button variant="ghost" wire:click="resetFilters">{{ __('Reset') }}</flux:button>
    </div>

    @forelse ($groups as $day => $submissions)
        <div class="space-y-3" wire:key="day-{{ $day }}">
            <flux:heading size="lg">{{ $day }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($submissions as $submission)
                    @php($preview = $submission->images->first())
                    <a href="{{ route('store-photos.show', ['id' => $submission->id]) }}" wire:navigate wire:key="submission-{{ $submission->id }}"
                       class="block overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700" data-test="submission-card">
                        @if ($preview)
                            <img src="{{ route('store-photos.image', ['image' => $preview->id, 'v' => $versions[$preview->id] ?? 0]) }}" alt="{{ $preview->filename }}"
                                 loading="lazy" class="h-48 w-full object-cover">
                        @else
                            <div class="flex h-48 items-center justify-center text-sm text-zinc-500">{{ __('No images in this report.') }}</div>
                        @endif
                        <div class="space-y-1 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <strong>{{ $submission->store->name }}</strong>
                                <flux:badge size="sm">{{ __(':count photos', ['count' => $submission->images->count()]) }}</flux:badge>
                            </div>
                            @if ($submission->notes)
                                <p class="text-xs text-zinc-500">{{ \Illuminate\Support\Str::limit($submission->notes, 80) }}</p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @empty
        <flux:text data-test="no-submission">{{ __('No photo reports yet.') }}</flux:text>
    @endforelse
</section>
