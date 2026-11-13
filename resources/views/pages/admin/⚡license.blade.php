<?php

use App\Actions\License\ActivateLicense;
use App\Actions\License\DeactivateLicense;
use App\Actions\License\RequestFreeLicense;
use App\Actions\License\ValidateLicense;
use App\License\LicenseClient;
use App\License\LicenseResult;
use App\License\LicenseState;
use App\License\LicenseStatus;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('License')] class extends Component {
    /** Saisie de la clé : vidée après chaque action, jamais réaffichée. */
    public string $licenseKey = '';

    /** Adresse de la demande de licence gratuite : vidée quand la demande est partie. */
    public string $freeEmail = '';

    /** Message d'une opération qui a échoué. */
    public string $error = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);
    }

    public function activate(ActivateLicense $activate): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->error = '';

        try {
            $result = $activate->handle(Auth::user(), $this->licenseKey);
        } finally {
            $this->reset('licenseKey');
        }

        if ($result->isValid()) {
            Flux::toast(variant: 'success', text: __('The license is activated.'));
        } else {
            $this->error = $this->message($result);
        }
    }

    public function requestFree(RequestFreeLicense $request): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->error = '';
        $result = $request->handle(Auth::user(), $this->freeEmail);

        if ($result->isAccepted()) {
            $this->reset('freeEmail');
            Flux::toast(variant: 'success', text: __('Check your mailbox: a confirmation email is on its way.'));
        } else {
            $this->error = $result->httpStatus === 422 ? __('The license server refused this email address.') : $this->message($result);
        }
    }

    public function check(ValidateLicense $validate): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->error = '';
        $result = $validate->handle(Auth::user());

        if ($result === null) {
            $this->error = __('No license key is set.');
        } elseif ($result->isValid()) {
            Flux::toast(variant: 'success', text: __('The license is valid.'));
        } else {
            $this->error = $this->message($result);
        }
    }

    public function deactivate(DeactivateLicense $deactivate): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->error = '';
        $result = $deactivate->handle(Auth::user());

        if ($result === null) {
            return;
        }

        if ($result->isAnswer()) {
            Flux::toast(variant: 'success', text: __('The license is deactivated. The seat is free again.'));
        } else {
            $this->error = $this->message($result);
        }
    }

    private function message(LicenseResult $result): string
    {
        if ($result->isRefused()) {
            return match ($result->error) {
                'license_not_found' => __('This license key is unknown.'),
                'license_expired' => __('This license has expired.'),
                'license_suspended' => __('This license is suspended.'),
                'license_revoked' => __('This license was revoked.'),
                'max_activations_reached' => __('All the seats of this license are used (:max). Deactivate it on another instance first.', ['max' => (int) $result->maxActivations]),
                'not_activated' => __('This license is not activated on this instance.'),
                default => __('The license server refused this license.'),
            };
        }

        return match (true) {
            $result->error === 'invalid_token' => __('The license server answered with a proof that could not be verified.'),
            $result->isProductKeyRejected() => __('The product key is refused by the license server.'),
            $result->isRateLimited() => __('Too many requests. Try again later.'),
            $result->error === 'not_configured' => __('The license server is not configured.'),
            $result->kind === LicenseResult::REJECTED => __('The license server rejected the request. Check the configuration.'),
            default => __('The license server could not be reached. Try again later.'),
        };
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $state = app(LicenseState::class);

        return [
            'status' => $state->status(),
            'hasKey' => $state->hasKey(),
            'configured' => app(LicenseClient::class)->isConfigured(),
            'buyUrl' => $state->buyUrl(),
            'timezone' => (string) config('kintai.timezone'),
        ];
    }
}; ?>

<section class="w-full space-y-8 p-4">
    <div>
        <flux:heading size="xl">{{ __('License') }}</flux:heading>
        <flux:subheading>{{ __('Activate a license to lift the limits of the free tier. Only the Owner can do it.') }}</flux:subheading>
    </div>

    @if ($error !== '')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="license-error">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($status->productKeyRejected())
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="license-product-key-rejected">
            <flux:callout.text>{{ __('The product key is refused by the license server.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (! $configured)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="license-not-configured">
            <flux:callout.text>{{ __('The license server is not configured.') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- État --}}
    <div class="max-w-2xl space-y-3" data-test="license-status">
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="lg">{{ __('Status') }}</flux:heading>
            @php
                $labels = [
                    LicenseStatus::FREE => [__('Free tier'), 'zinc'],
                    LicenseStatus::ACTIVE => [__('Active'), 'green'],
                    LicenseStatus::GRACE => [__('Active (server not reachable)'), 'amber'],
                    LicenseStatus::EXPIRED => [__('Expired'), 'red'],
                    LicenseStatus::REVOKED => [__('Revoked'), 'red'],
                    LicenseStatus::SUSPENDED => [__('Suspended'), 'red'],
                    LicenseStatus::REFUSED => [__('Refused'), 'red'],
                    LicenseStatus::UNREACHABLE => [__('Server not reachable'), 'red'],
                ];
            @endphp
            <flux:badge color="{{ $labels[$status->state][1] }}" data-test="license-state">{{ $labels[$status->state][0] }}</flux:badge>
        </div>

        <flux:text data-test="license-tier">{{ __('Plan: :plan', ['plan' => $status->grantsRights() ? ($status->tier() ?? __('Standard')) : __('Free')]) }}</flux:text>

        @if ($status->grantsRights())
            <flux:text data-test="license-expires">
                {{ $status->expiresAt() ? __('Expires on :date', ['date' => $status->expiresAt()->timezone($timezone)->format('Y-m-d')]) : __('No expiry date') }}
            </flux:text>
            @if ($status->tokenExpiresAt())
                <flux:text size="sm">{{ __('Valid without contact with the server until :date', ['date' => $status->tokenExpiresAt()->timezone($timezone)->format('Y-m-d H:i')]) }}</flux:text>
            @endif
        @endif

        @if ($status->validatedAt)
            <flux:text size="sm" data-test="license-validated">{{ __('Last successful check: :date', ['date' => $status->validatedAt->timezone($timezone)->format('Y-m-d H:i')]) }}</flux:text>
        @endif

        @if ($status->grantsRights())
            <div data-test="license-limits">
                <flux:heading>{{ __('Limits') }}</flux:heading>
                @forelse ($status->limits() as $name => $value)
                    <flux:text size="sm">{{ $name }} : {{ $value }}</flux:text>
                @empty
                    <flux:text size="sm">{{ __('No limit.') }}</flux:text>
                @endforelse
            </div>
        @endif
    </div>

    {{-- Activation --}}
    <div class="max-w-2xl space-y-4" data-test="license-form">
        <flux:input wire:model="licenseKey" type="password" :label="__('License key')" autocomplete="off" viewable data-test="license-key" />
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="activate" variant="primary" data-test="license-activate">{{ __('Activate') }}</flux:button>
            @if ($hasKey)
                <flux:button wire:click="check" icon="arrow-path" data-test="license-check">{{ __('Check now') }}</flux:button>
                <flux:button wire:click="deactivate" wire:confirm="{{ __('Deactivate the license on this instance? The seat is freed on the server and the free tier applies.') }}" variant="danger" data-test="license-deactivate">{{ __('Deactivate') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Licence gratuite --}}
    <div class="max-w-2xl space-y-4" data-test="license-free-form">
        <div>
            <flux:heading size="lg">{{ __('Get a free license') }}</flux:heading>
            <flux:text size="sm">{{ __('Enter your email address. You receive a confirmation email, then the license key by email: paste it in the License key field above and click Activate. One free license per email address.') }}</flux:text>
            @if (! $configured)
                <flux:text size="sm" data-test="license-free-unavailable">{{ __('Unavailable until the license server address and the product key (KINTAI_LICENSE_API_URL and KINTAI_LICENSE_API_KEY in the .env file) are set.') }}</flux:text>
            @endif
        </div>
        <form wire:submit="requestFree" class="space-y-4">
            <flux:input wire:model="freeEmail" type="email" :label="__('Email')" autocomplete="email" :disabled="! $configured" data-test="license-free-email" />
            <flux:button type="submit" :disabled="! $configured" data-test="license-free-submit">{{ __('Request a free license') }}</flux:button>
        </form>
    </div>

    @if ($buyUrl)
        <div class="max-w-2xl">
            <flux:link href="{{ $buyUrl }}" target="_blank" rel="noopener" external data-test="license-buy">{{ __('Buy or change a license') }}</flux:link>
        </div>
    @endif
</section>
