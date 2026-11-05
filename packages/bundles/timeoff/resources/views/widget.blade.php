<x-dashboard.card :title="$title" data-test="widget-timeoff">
    @forelse ($requests as $request)
        <div class="mb-2 flex justify-between gap-2 text-sm" wire:key="timeoff-{{ $request->id }}" data-test="widget-timeoff-row">
            <span>{{ $request->user->name }} · {{ __(\Kintai\Bundles\Timeoff\Models\TimeoffRequest::typeLabel($request->type)) }}</span>
            <span>{{ $request->start_date->toDateString() }} → {{ $request->end_date->toDateString() }}</span>
        </div>
    @empty
        <flux:text size="sm" data-test="widget-no-timeoff">{{ __('No pending requests.') }}</flux:text>
    @endforelse

    @if ($total > $requests->count())
        <flux:text size="sm">{{ __(':count more', ['count' => $total - $requests->count()]) }}</flux:text>
    @endif

    <flux:link :href="route($link)" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
</x-dashboard.card>
