<section class="w-full space-y-6 p-4">
    <div>
        <flux:heading size="xl">{{ __('Team') }}</flux:heading>
        <flux:subheading>{{ __('Colleagues of your stores') }}</flux:subheading>
    </div>

    <div class="flex flex-wrap gap-3">
        <div class="min-w-64 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name')" clearable />
        </div>
        @if (count($stores) > 1)
            <flux:select wire:model.live="store" class="max-w-xs">
                <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
                @foreach ($stores as $storeId => $storeName)
                    <flux:select.option :value="$storeId">{{ $storeName }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($colleagues === [])
        <flux:text data-test="team-empty">{{ __('No colleagues to show yet.') }}</flux:text>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($colleagues as $colleague)
                <a href="{{ route('team-directory.show', $colleague->id) }}" wire:navigate wire:key="colleague-{{ $colleague->id }}"
                   class="flex flex-col items-center gap-2 rounded-lg border border-zinc-200 p-4 text-center hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                   data-test="team-card">
                    <flux:avatar size="xl" :src="$colleague->avatarUrl" :name="$colleague->name" :initials="$colleague->initials()" />
                    <div class="font-medium">{{ $colleague->name }}</div>
                    <flux:text size="sm">{{ implode(' · ', $colleague->stores) }}</flux:text>
                </a>
            @endforeach
        </div>
    @endif
</section>
