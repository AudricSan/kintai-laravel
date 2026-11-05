<?php

use App\Actions\Notifications\SendTestNotification;
use App\Actions\Settings\RegenerateVapidKeys;
use App\Actions\Settings\SaveAppSettings;
use App\Actions\Settings\SendTestMail;
use App\Models\WebPushSubscription;
use App\Push\FcmClient;
use App\Push\VapidKeys;
use App\Settings\AppSettings;
use App\Settings\ThemeColorPalette;
use App\Support\Locales;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Instance settings')] class extends Component {
    // Organisation
    public string $subtitle = '';

    public string $login_notice = '';

    public string $support_email = '';

    public string $public_url = '';

    // Apparence
    /** @var array<string, string> */
    public array $colors = [];

    /** @var array<string, string> */
    public array $dark_colors = [];

    public string $dark_mode = 'auto';

    // Mails
    public string $from_address = '';

    public string $from_name = '';

    public string $test_to = '';

    /** @var array{success: bool, error: string|null}|null */
    public ?array $test_result = null;

    // Instance
    public string $locale = '';

    public string $timezone = '';

    // Maintenance
    public bool $maintenance_enabled = false;

    public string $maintenance_message = '';

    // Limites et rétentions
    public string $api_rate_limit = '';

    public string $activity_retention_days = '';

    public string $notifications_retention_days = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $this->load();
    }

    public function saveOrganization(SaveAppSettings $save): void
    {
        $this->persist($save, 'organization', [
            'subtitle' => $this->subtitle,
            'login_notice' => $this->login_notice,
            'support_email' => $this->support_email,
            'public_url' => $this->public_url,
        ]);
    }

    public function saveAppearance(SaveAppSettings $save): void
    {
        $this->persist($save, 'appearance', [
            'colors' => $this->colors,
            'dark_colors' => $this->dark_colors,
            'dark_mode' => $this->dark_mode,
        ]);
    }

    public function resetColors(): void
    {
        $this->colors = ThemeColorPalette::DEFAULTS;
        $this->dark_colors = array_map(ThemeColorPalette::autoDarkBase(...), ThemeColorPalette::DEFAULTS);
        $this->dark_mode = 'auto';
    }

    public function saveMail(SaveAppSettings $save): void
    {
        $this->persist($save, 'mail', ['from_address' => $this->from_address, 'from_name' => $this->from_name]);
    }

    public function sendTestMail(SendTestMail $send): void
    {
        $this->resetErrorBag();
        try {
            $this->test_result = $send->handle(Auth::user(), $this->test_to);
        } catch (ValidationException $e) {
            $this->test_result = null;

            throw ValidationException::withMessages(['test_to' => $e->errors()['to'] ?? $e->getMessage()]);
        }
    }

    public function saveInstance(SaveAppSettings $save): void
    {
        $this->persist($save, 'instance', ['locale' => $this->locale, 'timezone' => $this->timezone]);
    }

    public function saveMaintenance(SaveAppSettings $save): void
    {
        $this->persist($save, 'maintenance', ['enabled' => $this->maintenance_enabled, 'message' => $this->maintenance_message]);
    }

    public function saveLimits(SaveAppSettings $save): void
    {
        $this->persist($save, 'limits', [
            'api_rate_limit' => $this->api_rate_limit,
            'activity_retention_days' => $this->activity_retention_days,
            'notifications_retention_days' => $this->notifications_retention_days,
        ]);
    }

    public function confirmRegenerateVapid(): void
    {
        Flux::modal('confirm-vapid-regeneration')->show();
    }

    public function regenerateVapid(RegenerateVapidKeys $regenerate): void
    {
        $this->resetErrorBag();

        $regenerate->handle(Auth::user());

        Flux::modal('confirm-vapid-regeneration')->close();
        Flux::toast(variant: 'success', text: __('Web Push keys regenerated. The previous subscriptions were removed.'));
    }

    public function sendTestNotification(SendTestNotification $send): void
    {
        $send->handle(Auth::user());

        Flux::toast(variant: 'success', text: __('Test notification sent.'));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persist(SaveAppSettings $save, string $section, array $input): void
    {
        $this->resetErrorBag();

        try {
            $save->handle(Auth::user(), $section, $input);
        } catch (ValidationException $e) {
            // Les champs de la maintenance portent un préfixe côté composant.
            $rename = ['enabled' => 'maintenance_enabled', 'message' => 'maintenance_message'];

            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key): array => [($section === 'maintenance' ? ($rename[$key] ?? $key) : $key) => $messages])->all()
            );
        }

        $this->load();

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    private function load(): void
    {
        $settings = app(AppSettings::class);

        $this->subtitle = $settings->subtitle();
        $this->login_notice = $settings->loginNotice();
        $this->support_email = $settings->supportEmail();
        $this->public_url = $settings->publicUrl();

        $this->dark_mode = $settings->themeDarkMode();

        foreach (array_keys(ThemeColorPalette::DEFAULTS) as $group) {
            $this->colors[$group] = $settings->themeColor($group);
            $this->dark_colors[$group] = $settings->themeColorDark($group) ?: ThemeColorPalette::autoDarkBase($this->colors[$group]);
        }

        $this->from_address = $settings->mailFromAddress();
        $this->from_name = $settings->mailFromName();

        $this->locale = $settings->instanceLocale();
        $this->timezone = $settings->instanceTimezone();

        $this->maintenance_enabled = $settings->maintenanceEnabled();
        $this->maintenance_message = $settings->maintenanceMessage();

        $this->api_rate_limit = (string) $settings->apiRateLimit();
        $this->activity_retention_days = (string) $settings->activityRetentionDays();
        $this->notifications_retention_days = (string) $settings->notificationsRetentionDays();
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $mailer = (string) config('mail.default');
        $vapid = app(VapidKeys::class);

        return [
            'locales' => Locales::SUPPORTED,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'groups' => [
                'primary' => __('Primary'),
                'accent' => __('Accent'),
                'table_highlight' => __('Table highlight'),
                'success' => __('Success'),
                'warning' => __('Warning'),
                'danger' => __('Danger'),
                'info' => __('Information'),
            ],
            'mailer' => $mailer,
            // « Configuré » : un transport qui part vraiment (ni `log`, ni `array`).
            'mailConfigured' => ! in_array($mailer, ['log', 'array'], true),
            'webPushReady' => $vapid->isConfigured(),
            'fcmEnabled' => app(FcmClient::class)->isEnabled(),
            'subscriptionCount' => WebPushSubscription::query()->count(),
            'fromPlaceholder' => (string) config('mail.from.address'),
        ];
    }
}; ?>

<section class="w-full space-y-10 p-4">
    <div>
        <flux:heading size="xl">{{ __('Instance settings') }}</flux:heading>
        <flux:subheading>{{ __('Settings of the whole application. Only the Owner can change them.') }}</flux:subheading>
    </div>

    {{-- Organisation --}}
    <form wire:submit="saveOrganization" class="max-w-2xl space-y-4" data-test="section-organization">
        <div>
            <flux:heading size="lg">{{ __('Organization') }}</flux:heading>
            <flux:subheading>{{ __('Texts shown under the application name and on the login page.') }}</flux:subheading>
        </div>
        <flux:input wire:model="subtitle" :label="__('Subtitle')" maxlength="100" data-test="subtitle" />
        <flux:textarea wire:model="login_notice" :label="__('Login page message')" rows="3" maxlength="300" data-test="login-notice" />
        <flux:input wire:model="support_email" type="email" :label="__('Support email')" data-test="support-email" />
        <flux:input wire:model="public_url" :label="__('Public URL')" :description="__('Used for the links in emails, notifications and calendar feeds generated outside a web request. Example: https://planning.example.com')" placeholder="https://" data-test="public-url" />
        <flux:button variant="primary" type="submit" data-test="save-organization">{{ __('Save') }}</flux:button>
    </form>

    <flux:separator />

    {{-- Apparence --}}
    <form wire:submit="saveAppearance" class="max-w-2xl space-y-4" data-test="section-appearance">
        <div>
            <flux:heading size="lg">{{ __('Appearance') }}</flux:heading>
            <flux:subheading>{{ __('Brand colors of the application. The primary color drives the accent of the interface.') }}</flux:subheading>
        </div>

        <flux:radio.group wire:model.live="dark_mode" :label="__('Dark mode colors')" data-test="dark-mode">
            <flux:radio value="auto" :label="__('Automatic (computed from the light colors)')" />
            <flux:radio value="manual" :label="__('Manual (choose each dark color)')" />
        </flux:radio.group>

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($groups as $group => $groupLabel)
                <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="color-{{ $group }}">
                    <flux:input wire:model="colors.{{ $group }}" type="color" :label="$groupLabel" data-test="color-{{ $group }}" />
                    @if ($dark_mode === 'manual')
                        <flux:input wire:model="dark_colors.{{ $group }}" type="color" :label="__('Dark variant')" data-test="dark-color-{{ $group }}" />
                    @endif
                    @error('colors.'.$group) <flux:text size="sm" class="text-red-600">{{ $message }}</flux:text> @enderror
                    @error('dark_colors.'.$group) <flux:text size="sm" class="text-red-600">{{ $message }}</flux:text> @enderror
                </div>
            @endforeach
        </div>

        <flux:text size="sm">{{ __('The mascot of the previous version is not part of this version.') }}</flux:text>

        <div class="flex flex-wrap gap-3">
            <flux:button variant="primary" type="submit" data-test="save-appearance">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="resetColors" data-test="reset-colors">{{ __('Restore default colors') }}</flux:button>
        </div>
    </form>

    <flux:separator />

    {{-- Mails --}}
    <div class="max-w-2xl space-y-4" data-test="section-mail">
        <form wire:submit="saveMail" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Emails') }}</flux:heading>
                <flux:subheading>{{ __('Sender of every email sent by the application. Empty: the address configured on the server is used.') }}</flux:subheading>
            </div>
            <flux:input wire:model="from_address" type="email" :label="__('Sender address')" :placeholder="$fromPlaceholder" data-test="from-address" />
            <flux:input wire:model="from_name" :label="__('Sender name')" maxlength="100" data-test="from-name" />
            <flux:button variant="primary" type="submit" data-test="save-mail">{{ __('Save') }}</flux:button>
        </form>

        <form wire:submit="sendTestMail" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading>{{ __('Send a test email') }}</flux:heading>
            <flux:input wire:model="test_to" type="email" :label="__('Send to')" data-test="test-to" />
            <flux:button type="submit" data-test="send-test-mail">{{ __('Send test email') }}</flux:button>

            @if ($test_result !== null)
                @if ($test_result['success'])
                    <flux:callout variant="success" icon="check-circle" data-test="mail-test-success">
                        <flux:callout.text>{{ __('The test email was sent. Check the inbox of the recipient.') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout variant="danger" icon="exclamation-triangle" data-test="mail-test-error">
                        <flux:callout.heading>{{ __('The test email could not be sent.') }}</flux:callout.heading>
                        <flux:callout.text>{{ $test_result['error'] }}</flux:callout.text>
                    </flux:callout>
                @endif
            @endif
        </form>
    </div>

    <flux:separator />

    {{-- Instance --}}
    <form wire:submit="saveInstance" class="max-w-2xl space-y-4" data-test="section-instance">
        <div>
            <flux:heading size="lg">{{ __('Instance') }}</flux:heading>
            <flux:subheading>{{ __('Default language (for users who have not chosen one) and time zone of every shift time.') }}</flux:subheading>
        </div>
        <flux:select wire:model="locale" :label="__('Default language')" data-test="locale">
            @foreach ($locales as $code => $name)
                <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model="timezone" :label="__('Time zone')" data-test="timezone">
            @foreach ($timezones as $zone)
                <flux:select.option value="{{ $zone }}">{{ $zone }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button variant="primary" type="submit" data-test="save-instance">{{ __('Save') }}</flux:button>
    </form>

    <flux:separator />

    {{-- Maintenance --}}
    <form wire:submit="saveMaintenance" class="max-w-2xl space-y-4" data-test="section-maintenance">
        <div>
            <flux:heading size="lg">{{ __('Maintenance') }}</flux:heading>
            <flux:subheading>{{ __('While maintenance is on, everybody except you sees a maintenance page. The login page stays reachable.') }}</flux:subheading>
        </div>
        <flux:switch wire:model="maintenance_enabled" :label="__('Maintenance mode')" data-test="maintenance-enabled" />
        <flux:textarea wire:model="maintenance_message" :label="__('Maintenance message')" rows="3" maxlength="500" data-test="maintenance-message" />
        <flux:button variant="primary" type="submit" data-test="save-maintenance">{{ __('Save') }}</flux:button>
    </form>

    <flux:separator />

    {{-- Limites et rétentions --}}
    <form wire:submit="saveLimits" class="max-w-2xl space-y-4" data-test="section-limits">
        <div>
            <flux:heading size="lg">{{ __('Limits and retention') }}</flux:heading>
        </div>
        <flux:input wire:model="api_rate_limit" type="number" min="0" max="100000" :label="__('API requests per minute')" :description="__('Per access token. 0 means no limit.')" data-test="api-rate-limit" />
        <flux:input wire:model="activity_retention_days" type="number" min="0" max="3650" :label="__('Activity log retention (days)')" :description="__('0 keeps the log forever.')" data-test="activity-retention" />
        <flux:input wire:model="notifications_retention_days" type="number" min="7" max="3650" :label="__('Read notifications retention (days)')" :description="__('Unread notifications are never deleted.')" data-test="notifications-retention" />
        <flux:button variant="primary" type="submit" data-test="save-limits">{{ __('Save') }}</flux:button>
    </form>

    <flux:separator />

    {{-- Notifications --}}
    <div class="max-w-2xl space-y-4" data-test="section-notifications">
        <div>
            <flux:heading size="lg">{{ __('Notifications') }}</flux:heading>
            <flux:subheading>{{ __('State of the delivery channels of this instance.') }}</flux:subheading>
        </div>

        <ul class="space-y-2 text-sm">
            <li class="flex items-center gap-2" data-test="channel-mail">
                <flux:badge size="sm" :color="$mailConfigured ? 'green' : 'zinc'">{{ $mailConfigured ? __('Ready') : __('Not configured') }}</flux:badge>
                {{ __('Email') }} ({{ $mailer }})
            </li>
            <li class="flex items-center gap-2" data-test="channel-webpush">
                <flux:badge size="sm" :color="$webPushReady ? 'green' : 'zinc'">{{ $webPushReady ? __('Ready') : __('Not configured') }}</flux:badge>
                {{ __('Web Push (browsers)') }}
            </li>
            <li class="flex items-center gap-2" data-test="channel-fcm">
                <flux:badge size="sm" :color="$fcmEnabled ? 'green' : 'zinc'">{{ $fcmEnabled ? __('Enabled') : __('Disabled') }}</flux:badge>
                {{ __('Mobile push (FCM)') }}
            </li>
        </ul>

        <div class="flex flex-wrap gap-3">
            <flux:button type="button" wire:click="sendTestNotification" data-test="send-test-notification">{{ __('Send me a test notification') }}</flux:button>
            <flux:button type="button" variant="danger" wire:click="confirmRegenerateVapid" data-test="regenerate-vapid">{{ $webPushReady ? __('Regenerate the Web Push keys') : __('Generate the Web Push keys') }}</flux:button>
        </div>
        @error('vapid') <flux:text size="sm" class="text-red-600">{{ $message }}</flux:text> @enderror
    </div>

    <flux:modal name="confirm-vapid-regeneration" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Regenerate the Web Push keys?') }}</flux:heading>
                <flux:subheading>{{ __('The existing browser subscriptions become invalid and are deleted (:count now). Each user must enable notifications again on their devices.', ['count' => $subscriptionCount]) }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="regenerateVapid" data-test="confirm-regenerate-vapid">{{ __('Regenerate') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
