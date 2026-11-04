<?php

use App\Notifications\NotificationInbox;
use App\Notifications\NotificationPresenter;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Cloche du layout : compteur de non lues et dix dernières notifications, rafraîchis toutes les 15 secondes.
 */
new class extends Component {
    /** Côté d'ouverture du panneau : "bottom", "top"... */
    public string $position = 'bottom';

    public string $align = 'end';

    public function markAllRead(): void
    {
        app(NotificationInbox::class)->markAllRead(Auth::user());
    }

    public function with(): array
    {
        $user = Auth::user();
        $inbox = app(NotificationInbox::class);

        return [
            'count' => $inbox->unreadCount($user),
            'items' => $inbox->latest($user)->map(fn ($notification): array => [
                'id' => $notification->id,
                'created' => $notification->created_at,
            ] + NotificationPresenter::forRecord($notification)),
        ];
    }
}; ?>

<div wire:poll.15s data-test="notification-bell">
    <flux:dropdown :position="$position" :align="$align">
        <div class="relative">
            <flux:button variant="ghost" icon="bell" :aria-label="__('Notifications')" data-test="bell-button" />
            @if ($count > 0)
                <span class="pointer-events-none absolute -end-1 -top-1 min-w-5 rounded-full bg-red-600 px-1 text-center text-xs font-semibold text-white" data-test="bell-count">{{ $count > 99 ? '99+' : $count }}</span>
            @endif
        </div>

        <flux:menu class="w-80 max-w-[calc(100vw-2rem)]">
            <div class="flex items-center justify-between px-2 py-1">
                <flux:heading>{{ __('Notifications') }}</flux:heading>
                @if ($count > 0)
                    <flux:button size="xs" variant="ghost" wire:click="markAllRead" data-test="bell-mark-all-read">{{ __('Mark all as read') }}</flux:button>
                @endif
            </div>

            <flux:menu.separator />

            <div class="max-h-96 overflow-y-auto">
                @forelse ($items as $item)
                    <flux:menu.item
                        :href="route('notifications.open', ['notification' => $item['id']])"
                        :icon="$item['icon']"
                        wire:key="bell-{{ $item['id'] }}"
                        data-test="bell-item"
                        data-read="{{ $item['read'] ? '1' : '0' }}"
                    >
                        <span class="block">
                            <span @class(['block truncate', 'font-semibold' => ! $item['read']])>{{ $item['title'] }}</span>
                            <span class="block whitespace-normal text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $item['body'] }}</span>
                            <span class="block text-xs font-normal text-zinc-400">{{ $item['created']?->diffForHumans() }}</span>
                        </span>
                    </flux:menu.item>
                @empty
                    <flux:text class="p-2" data-test="bell-empty">{{ __('No notifications') }}</flux:text>
                @endforelse
            </div>

            <flux:menu.separator />

            <flux:menu.item :href="route('notifications.index')" wire:navigate icon="list-bullet">{{ __('See all notifications') }}</flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
