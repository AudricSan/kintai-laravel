<section class="w-full max-w-3xl space-y-4 p-4" wire:poll.5s>
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" data-test="thread-subject">{{ $thread->subject }}</flux:heading>
            <flux:subheading>
                {{ __('Store') }}: {{ $thread->store->name }} · {{ __('Participants') }}: {{ $participants->map(fn ($participant) => $participant->user?->name)->filter()->implode(', ') }}
            </flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button variant="filled" :href="route('messaging.index')" wire:navigate>{{ __('Back') }}</flux:button>
            <flux:button variant="danger" wire:click="confirmHiding" data-test="hide-thread-button">{{ __('Remove from my list') }}</flux:button>
        </div>
    </div>

    <div class="space-y-3" data-test="messages">
        @foreach ($messages as $message)
            @php($mine = $message->sender_id === $userId)
            <div wire:key="message-{{ $message->id }}" data-test="message"
                 class="max-w-[85%] rounded-xl border p-3 {{ $mine ? 'ml-auto border-blue-300 bg-blue-50 dark:border-blue-800 dark:bg-blue-950' : 'border-zinc-200 dark:border-zinc-700' }}">
                <div class="flex items-center justify-between gap-3 text-xs text-zinc-500">
                    <span>{{ $message->sender?->name }} · {{ $message->sent_at->timezone(config('kintai.timezone', 'UTC'))->format('Y-m-d H:i') }}</span>
                    @if ($mine)
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="confirmMessageDeletion({{ $message->id }})" :aria-label="__('Delete this message')" data-test="delete-message-button" />
                    @endif
                </div>
                @if ($message->isErased())
                    <p class="italic text-zinc-500" data-test="erased-message">{{ __('This message was removed.') }}</p>
                @else
                    <p class="whitespace-pre-line break-words">{{ $message->body }}</p>
                @endif
            </div>
        @endforeach
    </div>

    @if ($canReply)
        <form wire:submit="reply" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="reply-form">
            <flux:textarea wire:model="body" :label="__('Reply')" :placeholder="__('Your reply...')" rows="4" />
            <div class="flex justify-end">
                <flux:button variant="primary" type="submit" data-test="send-reply-button">{{ __('Send') }}</flux:button>
            </div>
        </form>
    @else
        <flux:text data-test="cannot-reply">{{ __('You can no longer write in this conversation.') }}</flux:text>
    @endif

    <flux:modal name="confirm-message-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this message?') }}</flux:heading>
                <flux:subheading>{{ __('The message is deleted for everyone and cannot be recovered.') }}</flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteMessage" data-test="confirm-delete-message-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

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
