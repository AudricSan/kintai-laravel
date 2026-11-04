<x-layouts::auth :title="__('Unsubscribe from an e-mail type')">
    @if ($state === 'invalid')
        <div class="space-y-4 text-center" data-test="unsubscribe-invalid">
            <flux:heading size="lg">{{ __('Unsubscribe from an e-mail type') }}</flux:heading>
            <flux:text>{{ __('This unsubscribe link is not valid. Sign in to adjust your notifications in Profile > Notifications.') }}</flux:text>
            <flux:button :href="route('notification-preferences.edit')" variant="primary">{{ __('Notification settings') }}</flux:button>
        </div>
    @elseif ($state === 'done')
        <div class="space-y-4 text-center" data-test="unsubscribe-done">
            <flux:heading size="lg">{{ __('Unsubscribed: no more e-mail for ":type".', ['type' => $type]) }}</flux:heading>
            <flux:text>{{ __('You can turn this e-mail type back on at any time in Profile > Notifications.') }}</flux:text>
        </div>
    @else
        <form method="POST" action="{{ $action }}" class="space-y-4 text-center" data-test="unsubscribe-confirm">
            @csrf
            <flux:heading size="lg">{{ __('Unsubscribe from an e-mail type') }}</flux:heading>
            <flux:text>{{ __('You will no longer receive e-mails for ":type" notifications. Notifications stay in the Kintai bell.', ['type' => $type]) }}</flux:text>
            <flux:button type="submit" variant="primary" data-test="unsubscribe-button">{{ __('Unsubscribe me') }}</flux:button>
        </form>
    @endif
</x-layouts::auth>
