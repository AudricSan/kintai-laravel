<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Google Calendar') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Google Calendar')" :subheading="__('Your shifts and approved time off in a calendar of your own')">
        <div class="my-6 w-full space-y-6">
            @if ($flash)
                <flux:callout :variant="$flash['variant']" data-test="google-calendar-flash">
                    <flux:callout.text>{{ $flash['text'] }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:text>{{ __('Link your Google account so that your shifts and approved leave appear in a dedicated calendar and update themselves within moments whenever the schedule changes.') }}</flux:text>
            <flux:text class="text-sm">{{ __('Kintai only accesses the calendar it creates itself: your other calendars are neither read nor changed. Sync goes from Kintai to Google only; editing an event in Google does not change the schedule.') }}</flux:text>

            @if ($connection === null)
                <form wire:submit="connect" class="space-y-4" data-test="google-calendar-connect-form">
                    @include('google-calendar::partials.name-choice')
                    <flux:button variant="primary" type="submit" data-test="google-calendar-connect">{{ __('Connect Google Calendar') }}</flux:button>
                </form>
            @else
                @if (! $connection->isActive())
                    <flux:callout variant="danger" icon="exclamation-triangle" data-test="google-calendar-revoked">
                        <flux:callout.text>{{ __('Kintai no longer has access to your Google Calendar (access revoked, token expired). Reconnect your account to resume syncing.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm" data-test="google-calendar-details">
                    <dt class="font-medium">{{ __('Status') }}</dt>
                    <dd>
                        @if ($connection->isActive())
                            <flux:badge color="green" size="sm">{{ __('Connected') }}</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">{{ __('Access lost') }}</flux:badge>
                        @endif
                    </dd>
                    @if ($connection->account_email)
                        <dt class="font-medium">{{ __('Google account') }}</dt>
                        <dd>{{ $connection->account_email }}</dd>
                    @endif
                    @if ($connection->calendar_name)
                        <dt class="font-medium">{{ __('Calendar') }}</dt>
                        <dd>{{ $connection->calendar_name }}</dd>
                    @endif
                    <dt class="font-medium">{{ __('Last sync') }}</dt>
                    <dd>{{ $connection->last_sync_at ? $connection->last_sync_at->timezone(config('kintai.timezone'))->format('Y-m-d H:i') : __('Waiting for the first sync') }}</dd>
                    @if ($connection->last_error && $connection->isActive())
                        <dt class="font-medium">{{ __('Last issue') }}</dt>
                        <dd class="text-zinc-500">{{ $connection->last_error }}</dd>
                    @endif
                </dl>

                @if ($connection->isActive())
                    <form wire:submit="saveOptions" class="space-y-4" data-test="google-calendar-options-form">
                        @include('google-calendar::partials.name-choice')
                        <flux:button type="submit" size="sm" data-test="google-calendar-save-options">{{ __('Save name') }}</flux:button>
                    </form>
                    <flux:button wire:click="resync" size="sm" data-test="google-calendar-resync">{{ __('Resync now') }}</flux:button>
                @elseif ($available)
                    <form wire:submit="connect" class="space-y-4" data-test="google-calendar-reconnect-form">
                        @include('google-calendar::partials.name-choice')
                        <flux:button variant="primary" type="submit" data-test="google-calendar-reconnect">{{ __('Reconnect Google Calendar') }}</flux:button>
                    </form>
                @endif

                <form wire:submit="disconnect" wire:confirm="{{ __('Disconnect Google Calendar? The schedule will no longer be synced.') }}" class="space-y-3" data-test="google-calendar-disconnect-form">
                    <flux:checkbox wire:model="deleteEvents" :label="__('Also delete Kintai events from my calendar')" />
                    <flux:button variant="danger" type="submit" size="sm" data-test="google-calendar-disconnect">{{ __('Disconnect') }}</flux:button>
                </form>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
