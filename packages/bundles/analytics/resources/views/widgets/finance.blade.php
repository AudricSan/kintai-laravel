@use('Kintai\Bundles\Analytics\Presenter')
<x-dashboard.card :title="__('Financial overview')" data-test="widget-analytics-finance">
    @forelse ($groups as $group)
        <div class="mb-4 space-y-2" wire:key="finance-{{ $group['currency'] }}" data-test="widget-finance-group">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <div class="text-2xl font-semibold" data-test="finance-current">{{ \App\Support\Money::format($group['current'], $group['currency'], $group['style']) }}</div>
                    <flux:text size="sm">
                        {{ __('Current month') }}
                        @if ($group['delta'] !== null)
                            <span class="{{ $group['delta'] > 0 ? 'text-red-600' : 'text-green-600' }}" data-test="finance-delta">({{ $group['delta'] > 0 ? '+' : '' }}{{ $group['delta'] }}% {{ __('vs previous month') }})</span>
                        @endif
                    </flux:text>
                </div>
                <div>
                    <div class="text-2xl font-semibold" data-test="finance-previous">{{ \App\Support\Money::format($group['previous'], $group['currency'], $group['style']) }}</div>
                    <flux:text size="sm">{{ __('Previous month') }}</flux:text>
                </div>
            </div>
            @if (count($group['trend']) > 1)
                <flux:heading size="sm">{{ __('Monthly trend') }}</flux:heading>
                @include('analytics::partials.bars', ['data' => $group['trend'], 'color' => 'bg-emerald-500'])
            @endif
        </div>
    @empty
        <flux:text size="sm" data-test="widget-no-finance">{{ __('No data') }}</flux:text>
    @endforelse
</x-dashboard.card>
