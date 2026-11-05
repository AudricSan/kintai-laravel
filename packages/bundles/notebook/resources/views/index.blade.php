<section class="w-full max-w-4xl space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Notebook') }}</flux:heading>
            <flux:subheading>{{ __('Notes for your team, a store or the whole organization') }}</flux:subheading>
        </div>
        <flux:switch wire:model.live="showExpired" :label="__('Show expired')" data-test="show-expired-switch" />
    </div>

    @if ($canCreate)
        <form wire:submit="publish" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="new-note-form">
            <flux:heading size="lg">{{ __('New note') }}</flux:heading>
            <flux:textarea wire:model="content" :label="__('Message')" rows="3" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="storeId" :label="__('Store')">
                    @if ($canOrganizationWide)
                        <flux:select.option value="">{{ __('Whole organization') }}</flux:select.option>
                    @endif
                    @foreach ($createStores as $createStoreId => $createStoreName)
                        <flux:select.option :value="$createStoreId">{{ $createStoreName }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="expiresOn" type="date" :label="__('Expires on')" />
            </div>
            <div class="flex justify-end">
                <flux:button variant="primary" type="submit" data-test="publish-note-button">{{ __('Publish') }}</flux:button>
            </div>
        </form>
    @endif

    @if ($entries->isEmpty())
        <flux:text data-test="no-notes">{{ __('No notes yet.') }}</flux:text>
    @else
        <ul class="space-y-3">
            @foreach ($entries as $entry)
                <li wire:key="note-{{ $entry->id }}" data-test="note"
                    class="rounded-xl border p-4 {{ $entry->id === $highlight ? 'border-blue-400 bg-blue-50 dark:bg-blue-950' : 'border-zinc-200 dark:border-zinc-700' }}">
                    @if ($editingId === $entry->id)
                        <form wire:submit="saveEditing" class="space-y-3">
                            <flux:textarea wire:model="editContent" rows="3" />
                            <flux:input wire:model="editExpiresOn" type="date" :label="__('Expires on')" />
                            <div class="flex justify-end gap-2">
                                <flux:button variant="filled" wire:click="cancelEditing">{{ __('Cancel') }}</flux:button>
                                <flux:button variant="primary" type="submit" data-test="save-note-button">{{ __('Save') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <div class="flex items-start justify-between gap-4">
                            <p class="whitespace-pre-line">{{ $entry->content }}</p>
                            @if ($entry->pinned)
                                <flux:badge size="sm" color="amber" data-test="pinned-badge">{{ __('Pinned') }}</flux:badge>
                            @endif
                        </div>
                        <flux:text size="sm" class="mt-2">
                            {{ $entry->author->name }} · {{ $entry->store?->name ?? __('Whole organization') }} · {{ $entry->created_at?->timezone(config('kintai.timezone', 'UTC'))->format('Y-m-d H:i') }}
                            @if ($entry->expires_on)
                                · <flux:badge size="sm" :color="$entry->isExpired() ? 'zinc' : 'blue'">{{ __('Expires on') }} {{ $entry->expires_on->toDateString() }}</flux:badge>
                            @endif
                        </flux:text>

                        @php($canEdit = $access->canEdit($user, $entry))
                        @php($canManage = $access->canManage($user, $entry))
                        @if ($canEdit || $canManage)
                            <div class="mt-3 flex gap-2">
                                @if ($canEdit)
                                    <flux:button size="sm" variant="filled" wire:click="startEditing({{ $entry->id }})" data-test="edit-note-button">{{ __('Edit') }}</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="confirmDeletion({{ $entry->id }})" data-test="delete-note-button">{{ __('Delete') }}</flux:button>
                                @endif
                                @if ($canManage)
                                    <flux:button size="sm" variant="ghost" wire:click="togglePin({{ $entry->id }})" data-test="pin-note-button">{{ $entry->pinned ? __('Unpin') : __('Pin') }}</flux:button>
                                @endif
                            </div>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <flux:modal name="confirm-note-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this note?') }}</flux:heading>
                <flux:subheading>{{ __('The note is deleted for everyone and cannot be recovered.') }}</flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-note-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
