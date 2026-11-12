<x-dashboard.card :title="__('Reports to validate')" data-test="widget-daily-report">
    @forelse ($reports as $report)
        <div class="mb-2 flex justify-between gap-2 text-sm" wire:key="daily-report-{{ $report->id }}" data-test="widget-report-row">
            <flux:link :href="route('daily-report.show', $report->id)" wire:navigate>{{ $report->store->name }}</flux:link>
            <span>{{ $report->report_date->toDateString() }}</span>
        </div>
    @empty
        <flux:text size="sm" data-test="widget-no-reports">{{ __('No report waiting for validation.') }}</flux:text>
    @endforelse

    @if ($total > $reports->count())
        <flux:text size="sm">{{ __(':count more', ['count' => $total - $reports->count()]) }}</flux:text>
    @endif

    <flux:link :href="route('daily-report.index', ['status' => 'submitted'])" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
</x-dashboard.card>
