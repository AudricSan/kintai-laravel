<x-dashboard.card :title="__('Active clock-ins')" data-test="widget-timeclock-active">
    @forelse ($active as $entry)
        <div class="mb-2 flex justify-between gap-2 text-sm" wire:key="clock-{{ $entry->id }}" data-test="widget-active-row">
            <span>{{ $entry->user->name }} · {{ $entry->store->name }}</span>
            <span>{{ __('since :time', ['time' => $entry->localIn()->format('H:i')]) }}</span>
        </div>
    @empty
        <flux:text size="sm" data-test="widget-no-active">{{ __('Nobody is clocked in right now.') }}</flux:text>
    @endforelse

    <flux:link :href="route('timeclock.manage')" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
</x-dashboard.card>
