<?php

use App\Actions\Notifications\SaveNotificationPreferences;
use App\Actions\Notifications\SendTestNotification;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationType;
use App\Notifications\NotificationTypeRegistry;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    /** @var array<string, list<string>> type => canaux cochés */
    public array $channels = [];

    public bool $quietEnabled = false;

    public string $quietStart = NotificationPreferences::DEFAULT_QUIET_START;

    public string $quietEnd = NotificationPreferences::DEFAULT_QUIET_END;

    public function mount(): void
    {
        $this->load();
    }

    public function save(): void
    {
        $this->resetErrorBag();

        app(SaveNotificationPreferences::class)->handle(Auth::user(), $this->channels, $this->quietEnabled, $this->quietStart, $this->quietEnd);
        $this->load();

        Flux::toast(variant: 'success', text: __('Notification preferences saved.'));
    }

    public function sendTest(): void
    {
        app(SendTestNotification::class)->handle(Auth::user());

        Flux::toast(variant: 'success', text: __('Test notification sent.'));
    }

    private function load(): void
    {
        $user = Auth::user()->refresh();
        $preferences = app(NotificationPreferences::class);

        $this->channels = [];
        foreach ($preferences->matrix($user) as $type => $row) {
            $this->channels[$type] = array_keys(array_filter($row));
        }

        $quiet = $preferences->quietHours($user);
        $this->quietEnabled = $quiet['enabled'];
        $this->quietStart = $quiet['start'];
        $this->quietEnd = $quiet['end'];
    }

    public function with(): array
    {
        return ['grid' => app(NotificationTypeRegistry::class)->grid()];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Choose how you are notified. The bell always receives every notification.')">
        <form wire:submit="save" class="space-y-8">
            @foreach ($grid as $category => $types)
                <div class="space-y-2" wire:key="category-{{ $category }}" data-test="category-{{ $category }}">
                    <flux:heading>{{ __(NotificationType::CATEGORY_LABELS[$category]) }}</flux:heading>

                    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        <div class="grid grid-cols-[1fr_4rem_4rem] items-center gap-2 px-3 py-2 text-xs text-zinc-500">
                            <span></span>
                            <span class="text-center">{{ __('Push') }}</span>
                            <span class="text-center">{{ __('E-mail') }}</span>
                        </div>
                        @foreach ($types as $type)
                            <div class="grid grid-cols-[1fr_4rem_4rem] items-center gap-2 px-3 py-2" wire:key="type-{{ $type->key }}">
                                <span class="text-sm">{{ __($type->label) }}</span>
                                @foreach (NotificationType::CHANNELS as $channel)
                                    <span class="flex justify-center">
                                        <flux:checkbox wire:model="channels.{{ $type->key }}" value="{{ $channel }}" :aria-label="__($type->label).' - '.$channel" data-test="pref-{{ $type->key }}-{{ $channel }}" />
                                    </span>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="space-y-3">
                <flux:heading>{{ __('Quiet hours') }}</flux:heading>
                <flux:switch wire:model.live="quietEnabled" :label="__('Silence push notifications during these hours')" data-test="quiet-enabled" />
                @if ($quietEnabled)
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input type="time" wire:model="quietStart" :label="__('From')" data-test="quiet-start" />
                        <flux:input type="time" wire:model="quietEnd" :label="__('To')" data-test="quiet-end" />
                    </div>
                    <flux:text size="sm">{{ __('E-mails are never held back during quiet hours.') }}</flux:text>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="save-notifications">{{ __('Save') }}</flux:button>
                <flux:button type="button" wire:click="sendTest" data-test="send-test">{{ __('Send me a test notification') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
