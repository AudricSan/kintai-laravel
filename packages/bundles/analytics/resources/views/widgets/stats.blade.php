@use('Kintai\Bundles\Analytics\Presenter')
<x-dashboard.card :title="__('Stats overview').' - '.__(':days days', ['days' => $period])" data-test="widget-analytics-stats">
    @if ($rows === [])
        <flux:text size="sm" data-test="widget-no-stats">{{ __('No data') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Store') }}</flux:table.column>
                <flux:table.column>{{ __('Net hours') }}</flux:table.column>
                <flux:table.column>{{ __('Total cost') }}</flux:table.column>
                <flux:table.column>{{ __('Stability') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row wire:key="stats-{{ $row['store_id'] }}" data-test="widget-stats-row">
                        <flux:table.cell><flux:link :href="route('analytics.stats', ['store' => $row['store_id']])" wire:navigate>{{ $row['store_name'] }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ number_format((float) $row['total_hours'], 1) }}{{ __('h') }}</flux:table.cell>
                        <flux:table.cell>{{ \App\Support\Money::format((float) $row['total_cost'], (string) $row['currency'], (string) $row['currency_symbol_style']) }}</flux:table.cell>
                        <flux:table.cell>{{ (int) $row['stability'] }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if (count($hoursByWeek) > 1)
            <div class="mt-3" data-test="widget-weekly-hours">
                <flux:heading size="sm" class="mb-2">{{ __('Weekly hours trend') }}</flux:heading>
                @include('analytics::partials.bars', ['data' => $hoursByWeek])
            </div>
        @endif
    @endif
</x-dashboard.card>
