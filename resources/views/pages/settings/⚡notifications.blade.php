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
        return ['grid' => app(NotificationTypeRegistry::class)->grid(Auth::user())];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Choose how you are notified. The bell always receives every notification.')">
        {{-- Web Push de CET appareil : piloté par resources/js/push.js (wire:ignore : l'état affiché ne doit pas être réinitialisé par Livewire). --}}
        <div wire:ignore data-push-panel class="mb-8 space-y-3" data-test="push-panel">
            <flux:heading>{{ __('Notifications on this device') }}</flux:heading>

            <div data-push-state="unconfigured" hidden><flux:text size="sm">{{ __('Push notifications are not enabled on this instance.') }}</flux:text></div>
            <div data-push-state="unsupported" hidden><flux:text size="sm">{{ __('This browser does not support push notifications.') }}</flux:text></div>
            <div data-push-state="ios" hidden><flux:text size="sm">{{ __('On iPhone and iPad, add this application to your home screen to receive push notifications.') }}</flux:text></div>
            <div data-push-state="denied" hidden><flux:text size="sm">{{ __('Notifications are blocked for this site. Allow them in your browser settings, then reload the page.') }}</flux:text></div>
            <div data-push-state="error" hidden><flux:text size="sm" class="text-red-600">{{ __('Push notifications could not be set up on this device. Try again.') }}</flux:text></div>
            <div data-push-state="disabled" hidden class="flex flex-wrap items-center gap-3">
                <flux:text size="sm">{{ __('Push notifications are off on this device.') }}</flux:text>
                <flux:button type="button" size="sm" data-push-enable data-test="push-enable">{{ __('Enable notifications on this device') }}</flux:button>
            </div>
            <div data-push-state="enabled" hidden class="flex flex-wrap items-center gap-3">
                <flux:text size="sm">{{ __('Push notifications are on for this device.') }}</flux:text>
                <flux:button type="button" size="sm" data-push-disable data-test="push-disable">{{ __('Disable notifications on this device') }}</flux:button>
            </div>
        </div>

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
