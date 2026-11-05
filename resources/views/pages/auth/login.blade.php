<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email or employee code and your password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- Message de l'Owner (annonce, maintenance) et contact de support : réglages > Organisation. --}}
        @if ($loginNotice = app(\App\Settings\AppSettings::class)->loginNotice())
            <flux:callout icon="information-circle" data-test="login-notice">
                <flux:callout.text class="whitespace-pre-line">{{ $loginNotice }}</flux:callout.text>
            </flux:callout>
        @endif

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email or employee code -->
            <flux:input
                name="login"
                :label="__('Email or employee code')"
                :value="old('login')"
                type="text"
                required
                autofocus
                autocomplete="username"
            />

            <!-- Store code (employees) -->
            <flux:input
                name="store_code"
                :label="__('Store code (employees)')"
                :value="old('store_code')"
                type="text"
                autocomplete="off"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        @if ($supportEmail = app(\App\Settings\AppSettings::class)->supportEmail())
            <flux:text class="text-center text-sm" data-test="login-support">
                {{ __('Need help?') }} <flux:link href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</flux:link>
            </flux:text>
        @endif
    </div>
</x-layouts::auth>
