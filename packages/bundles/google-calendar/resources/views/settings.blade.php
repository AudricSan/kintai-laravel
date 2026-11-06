@php
    $redirectHint = __('Copy as-is into the OAuth client\'s "Authorised redirect URIs" in the Google Cloud console.');
@endphp
<section class="w-full max-w-3xl space-y-6 p-4">
    <div>
        <flux:heading size="xl">{{ __('Google Calendar settings') }}</flux:heading>
        <flux:subheading>{{ __('Let your employees link their Google Calendar. Each instance creates its own project in the Google Cloud console (OAuth client of type "Web application", Google Calendar API enabled) and enters its client ID and secret here.') }}</flux:subheading>
    </div>

    @if ($check !== '')
        @php
            $checks = [
                'ok' => ['success', __('Google accepts the client ID and secret.')],
                'invalid_client' => ['danger', __('Google rejects the client ID or secret: check them in the Google Cloud console.')],
                'unreachable' => ['warning', __('Google cannot be reached from this server (network or firewall).')],
                'incomplete' => ['warning', __('Enter and save the client ID and secret before testing.')],
            ];
            [$variant, $message] = $checks[$check] ?? ['warning', ''];
        @endphp
        <flux:callout :variant="$variant" data-test="google-calendar-check">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="google-calendar-settings-form" autocomplete="off">
        <flux:heading size="lg">{{ __('Google Cloud project') }}</flux:heading>

        <flux:switch wire:model="enabled" :label="__('Allow Google Calendar linking')" :description="__('Once enabled, every user can link their calendar from their profile.')" />

        <flux:input wire:model="clientId" :label="__('OAuth client ID')" type="text" maxlength="255" spellcheck="false" />

        <flux:input wire:model="clientSecret" :label="__('OAuth client secret')" type="password" maxlength="255" autocomplete="new-password" spellcheck="false"
                    :placeholder="$hasSecret ? __('Secret saved (leave empty to keep it)') : ''"
                    :description="__('Encrypted in the database; it is never shown again nor written to the logs.')" />

        <flux:input :value="$redirectUri" :label="__('Authorised redirect URI')" type="text" readonly copyable data-test="google-calendar-redirect-uri"
                    :description="$redirectHint" />

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="google-calendar-save">{{ __('Save') }}</flux:button>
        </div>
    </form>

    <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Status and check') }}</flux:heading>
        <p>
            @if ($available)
                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
            @else
                <flux:badge color="amber" size="sm">{{ __('Unavailable') }}</flux:badge>
            @endif
            <span class="text-zinc-500">{{ __(':count linked user(s)', ['count' => $connections]) }}</span>
        </p>
        <flux:text class="text-sm">{{ __('Automatic updates are handled by the "google-calendar:sync" scheduled task, run every minute by the scheduler (see Scheduled tasks).') }}</flux:text>
        <flux:text class="text-sm">{{ __('While the Google project stays in "Testing" mode, tokens expire after 7 days and only declared testers can connect: publish the consent screen for lasting use.') }}</flux:text>
        <flux:button wire:click="test" data-test="google-calendar-test">{{ __('Test credentials') }}</flux:button>
    </div>
</section>
