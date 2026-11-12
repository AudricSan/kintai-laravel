@if (auth()->user()?->must_change_password)
    @php($showModal = (bool) session()->pull('password_reminder', false))

    <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" data-test="password-reminder-banner">
        <flux:callout.heading>{{ __('Your password is too short') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Please choose a longer password to keep your account secure.') }}
            <flux:callout.link :href="route('security.edit')" wire:navigate>{{ __('Change password') }}</flux:callout.link>
        </flux:callout.text>
    </flux:callout>

    <flux:modal name="password-reminder" :show="$showModal" class="max-w-md" data-test="password-reminder-modal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Your password is too short') }}</flux:heading>
                <flux:text>{{ __('Please choose a longer password to keep your account secure.') }}</flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Later') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" :href="route('security.edit')" wire:navigate>
                    {{ __('Change password') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
@endif
