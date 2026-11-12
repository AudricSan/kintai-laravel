<x-dashboard.card :title="$title" data-test="widget-swap">
    @forelse ($requests as $request)
        <div class="mb-2 text-sm" wire:key="swap-{{ $request->id }}" data-test="widget-swap-row">
            <div>{{ $request->requester->name }} &harr; {{ $request->target->name }}</div>
            <div class="text-zinc-500">{{ \Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::describe($request->requesterShift) }} &harr; {{ \Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest::describe($request->targetShift) }}</div>
        </div>
    @empty
        <flux:text size="sm" data-test="widget-no-swap">{{ __('No pending requests.') }}</flux:text>
    @endforelse

    @if ($total > $requests->count())
        <flux:text size="sm">{{ __(':count more', ['count' => $total - $requests->count()]) }}</flux:text>
    @endif

    <flux:link :href="route($link)" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
</x-dashboard.card>
