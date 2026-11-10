<x-dashboard.card :title="__('HR and absenteeism').' - '.__(':days days', ['days' => $period])" data-test="widget-analytics-absence">
    <div class="grid grid-cols-2 gap-3">
        <div>
            <div class="text-2xl font-semibold" data-test="absence-rate">{{ number_format($figures['absenceRate'], 1) }}%</div>
            <flux:text size="sm">{{ __('Absence rate') }}</flux:text>
        </div>
        <div>
            <div class="text-2xl font-semibold" data-test="absence-leave">{{ $figures['approved'] }}</div>
            <flux:text size="sm">{{ __('Approved leave') }}</flux:text>
        </div>
    </div>
</x-dashboard.card>
