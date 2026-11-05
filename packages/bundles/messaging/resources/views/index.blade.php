<section class="w-full max-w-5xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Messages') }}</flux:heading>
            <flux:subheading>{{ __('Private conversations with the members of your stores') }}</flux:subheading>
        </div>
        @if ($canCompose)
            <flux:button variant="primary" icon="plus" :href="route('messaging.compose')" wire:navigate data-test="new-message-link">{{ __('New message') }}</flux:button>
        @endif
    </div>

    <div class="flex gap-2" data-test="message-tabs">
        <flux:button size="sm" :variant="$tab === 'inbox' ? 'primary' : 'filled'" wire:click="$set('tab', 'inbox')" data-test="tab-inbox">{{ __('Inbox') }}</flux:button>
        <flux:button size="sm" :variant="$tab === 'sent' ? 'primary' : 'filled'" wire:click="$set('tab', 'sent')" data-test="tab-sent">{{ __('Sent') }}</flux:button>
        <flux:button size="sm" :variant="$tab === 'unread' ? 'primary' : 'filled'" wire:click="$set('tab', 'unread')" data-test="tab-unread">{{ __('Unread') }}</flux:button>
    </div>

    @if ($threads->isEmpty())
        <flux:text data-test="no-messages">{{ __('No messages.') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column></flux:table.column>
                <flux:table.column>{{ __('Subject') }}</flux:table.column>
                <flux:table.column>{{ $tab === 'sent' ? __('To') : __('From') }}</flux:table.column>
                <flux:table.column>{{ __('Store') }}</flux:table.column>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Messages') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($threads as $thread)
                    @php($mine = $thread->participants->firstWhere('user_id', $userId))
                    @php($unread = $mine !== null && ! $mine->is_read)
                    <flux:table.row :key="'t'.$thread->id" data-test="thread-row">
                        <flux:table.cell>
                            @if ($unread)
                                <flux:badge size="sm" color="blue" data-test="unread-badge">{{ __('New') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:link :href="route('messaging.thread', ['id' => $thread->id])" wire:navigate class="{{ $unread ? 'font-semibold' : '' }}">{{ $thread->subject }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($tab === 'sent')
                                {{ $thread->participants->where('user_id', '!=', $userId)->map(fn ($participant) => $participant->user?->name)->filter()->implode(', ') }}
                            @else
                                {{ $thread->latestMessage?->sender?->name ?? '—' }}
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $thread->store->name }}</flux:table.cell>
                        <flux:table.cell>{{ ($thread->last_message_at ?? $thread->created_at)?->timezone(config('kintai.timezone', 'UTC'))->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $thread->messages_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmHiding({{ $thread->id }})" :aria-label="__('Remove from my list')" data-test="hide-thread-button" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="confirm-thread-hiding" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Remove this conversation from your list?') }}</flux:heading>
                <flux:subheading>{{ __('The other participants keep it. It comes back if someone replies.') }}</flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="hide" data-test="confirm-hide-thread-button">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
