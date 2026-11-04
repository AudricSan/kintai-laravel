<?php

use App\Requests\PendingRequestRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function with(): array
    {
        $registry = app(PendingRequestRegistry::class);
        $rows = $registry->counts(Auth::user());

        return [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'count')),
            'empty' => $registry->isEmpty(),
        ];
    }
}; ?>

<x-dashboard.card :title="__('Pending requests')" data-test="widget-pending-requests">
    @if ($empty)
        <flux:text size="sm">{{ __('No request source is available yet.') }}</flux:text>
    @else
        <a href="{{ route('requests.index') }}" wire:navigate class="text-3xl font-semibold" data-test="pending-total">{{ $total }}</a>
        <ul class="mt-2 space-y-1 text-sm">
            @foreach ($rows as $row)
                <li class="flex justify-between gap-2" wire:key="pending-{{ $row['provider']->key }}">
                    <a href="{{ route($row['provider']->route) }}" wire:navigate class="underline">{{ __($row['provider']->label) }}</a>
                    <span>{{ $row['count'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</x-dashboard.card>
