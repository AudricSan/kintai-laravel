<?php

use Kintai\Contracts\Requests\PendingRequestRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pending requests')] class extends Component {
    public function with(): array
    {
        $registry = app(PendingRequestRegistry::class);

        return [
            'rows' => $registry->counts(Auth::user()),
            'empty' => $registry->isEmpty(),
        ];
    }
}; ?>

<section class="w-full max-w-3xl space-y-6 p-4">
    <flux:heading size="xl">{{ __('Pending requests') }}</flux:heading>

    @if ($empty)
        <flux:text data-test="no-requests">{{ __('No request source is available yet.') }}</flux:text>
    @else
        <div class="space-y-2">
            @foreach ($rows as $row)
                <a
                    href="{{ route($row['provider']->route) }}"
                    wire:navigate
                    wire:key="provider-{{ $row['provider']->key }}"
                    class="flex items-center justify-between rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    data-test="request-provider"
                >
                    <span class="font-medium">{{ __($row['provider']->label) }}</span>
                    <flux:badge :color="$row['count'] > 0 ? 'amber' : 'zinc'" data-test="request-count">{{ $row['count'] }}</flux:badge>
                </a>
            @endforeach
        </div>
    @endif
</section>
