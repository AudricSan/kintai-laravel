<x-dashboard.card :title="__('Tomorrow\'s schedule')" data-test="widget-planning-check">
    @forelse ($lines as $line)
        <div class="mb-2 flex items-center justify-between gap-2 text-sm" wire:key="planning-check-{{ $line['storeId'] }}" data-test="widget-check-row">
            <flux:link :href="route('planning-check.index', ['store' => $line['storeId']])" wire:navigate>{{ $line['store'] }}</flux:link>
            <span class="flex items-center gap-1">
                <flux:badge size="sm" :color="match ($line['status']) { 'validated' => 'green', 'submitted' => 'amber', 'rejected' => 'red', 'escalated' => 'orange', default => 'zinc' }">{{ __(\Kintai\Bundles\PlanningCheck\Models\PlanningCheck::statusLabel($line['status'])) }}</flux:badge>
                @if ($line['late'])
                    <flux:badge size="sm" color="red">{{ __('late') }}</flux:badge>
                @endif
            </span>
        </div>
    @empty
        <flux:text size="sm" data-test="widget-no-checks">{{ __('No schedule to validate for tomorrow.') }}</flux:text>
    @endforelse

    <flux:link :href="route('planning-check.index')" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
</x-dashboard.card>
