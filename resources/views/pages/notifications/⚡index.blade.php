<?php

use App\Notifications\NotificationInbox;
use App\Notifications\NotificationPresenter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notifications')] class extends Component {
    use WithPagination;

    public function markAllRead(): void
    {
        app(NotificationInbox::class)->markAllRead(Auth::user());

        Flux::toast(variant: 'success', text: __('All notifications marked as read.'));
    }

    public function confirmDeleteAll(): void
    {
        Flux::modal('confirm-notifications-deletion')->show();
    }

    public function deleteAll(): void
    {
        app(NotificationInbox::class)->deleteAll(Auth::user());

        $this->resetPage();
        Flux::modal('confirm-notifications-deletion')->close();
        Flux::toast(variant: 'success', text: __('Notifications deleted.'));
    }

    public function with(): array
    {
        $user = Auth::user();
        $page = $user->notifications()->paginate(20);

        return [
            'page' => $page,
            'rows' => $page->getCollection()->map(fn ($notification): array => [
                'id' => $notification->id,
                'created' => $notification->created_at,
            ] + NotificationPresenter::forRecord($notification)),
            'unread' => app(NotificationInbox::class)->unreadCount($user),
        ];
    }
}; ?>

<section class="w-full max-w-3xl space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('Notifications') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="markAllRead" icon="check" :disabled="$unread === 0" data-test="mark-all-read">{{ __('Mark all as read') }}</flux:button>
            <flux:button wire:click="confirmDeleteAll" icon="trash" variant="danger" :disabled="$page->total() === 0" data-test="delete-all">{{ __('Delete all') }}</flux:button>
            <flux:button :href="route('notification-preferences.edit')" wire:navigate icon="cog">{{ __('Notification settings') }}</flux:button>
        </div>
    </div>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($rows as $row)
            <a
                href="{{ route('notifications.open', ['notification' => $row['id']]) }}"
                wire:key="notification-{{ $row['id'] }}"
                @class([
                    'flex gap-3 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800',
                    'font-semibold' => ! $row['read'],
                ])
                data-test="notification-row"
                data-read="{{ $row['read'] ? '1' : '0' }}"
            >
                <flux:icon :name="$row['icon']" class="mt-0.5 size-5 shrink-0" />
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $row['title'] }}</span>
                    <span class="block text-sm font-normal text-zinc-600 dark:text-zinc-300">{{ $row['body'] }}</span>
                </span>
                <span class="shrink-0 text-xs font-normal text-zinc-500">{{ $row['created']?->diffForHumans() }}</span>
            </a>
        @empty
            <flux:text class="p-4" data-test="no-notifications">{{ __('No notifications') }}</flux:text>
        @endforelse
    </div>

    {{ $page->links() }}

    <flux:modal name="confirm-notifications-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete all notifications?') }}</flux:heading>
                <flux:subheading>{{ __('This cannot be undone.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteAll" data-test="confirm-delete-all">{{ __('Delete all') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
