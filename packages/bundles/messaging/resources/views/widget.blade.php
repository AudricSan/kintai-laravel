<x-dashboard.card :title="__('Unread messages')" data-test="widget-messages">
    @if ($unread > 0)
        <flux:text data-test="widget-unread">{{ __(':count unread conversations', ['count' => $unread]) }}</flux:text>
    @else
        <flux:text size="sm" data-test="widget-no-unread">{{ __('No unread messages.') }}</flux:text>
    @endif

    <flux:link :href="route('messaging.index')" wire:navigate class="text-sm">{{ __('Open messages') }}</flux:link>
</x-dashboard.card>
