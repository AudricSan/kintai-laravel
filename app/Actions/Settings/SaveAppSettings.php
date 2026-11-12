<?php

namespace App\Actions\Settings;

use App\Activity\ActivityLogger;
use App\Models\User;
use App\Settings\AppSettings;
use App\Settings\CoreSettings;
use App\Settings\PublicUrl;
use App\Settings\RuntimeSettings;
use App\Settings\ThemeColorPalette;
use App\Support\Locales;
use Closure;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Enregistre une section de l'écran « Réglages de l'instance » (Owner seulement). Chaque section est validée en entier :
 * une valeur refusée ne modifie rien (l'ancienne valeur est conservée). L'enregistrement est journalisé (`settings.updated`)
 * avec la section et les clés modifiées, jamais les valeurs.
 */
class SaveAppSettings
{
    public const SECTIONS = ['organization', 'appearance', 'mail', 'instance', 'maintenance', 'limits'];

    public function __construct(
        private readonly AppSettings $settings,
        private readonly RuntimeSettings $runtime,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Champs de la section (voir `fields()`).
     * @return list<string> Clés de réglage modifiées.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $section, array $input): array
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        [$rules, $values] = $this->section($section);

        array_walk_recursive($input, function (mixed &$value): void {
            $value = is_string($value) ? trim($value) : $value;
        });

        $validated = Validator::make($input, $rules, $this->messages(), $this->attributes())->validate();
        $new = $values($validated);

        $changed = [];

        foreach ($new as $key => $value) {
            if ($this->differs($this->settings->get($key), $value)) {
                $changed[] = $key;
            }
        }

        $this->settings->setMany($new);
        $this->runtime->apply();

        $this->logger->record('settings.updated', null, null, ['section' => $section, 'keys' => $changed], $actor);

        return $changed;
    }

    /**
     * @return array{0: array<string, mixed>, 1: Closure(array<string, mixed>): array<string, mixed>}
     */
    private function section(string $section): array
    {
        return match ($section) {
            'organization' => [
                [
                    'subtitle' => ['nullable', 'string', 'max:'.CoreSettings::SUBTITLE_MAX],
                    'login_notice' => ['nullable', 'string', 'max:'.CoreSettings::LOGIN_NOTICE_MAX],
                    'support_email' => ['nullable', 'string', 'email:filter', 'max:255'],
                    'public_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && trim($value) !== '' && PublicUrl::normalize($value) === null) {
                            $fail(__('The public URL must be an http(s) address with a domain, without credentials, query or fragment.'));
                        }
                    }],
                ],
                fn (array $v): array => [
                    'organization.subtitle' => $v['subtitle'] ?? '',
                    'organization.login_notice' => $v['login_notice'] ?? '',
                    'organization.support_email' => $v['support_email'] ?? '',
                    'organization.public_url' => PublicUrl::normalize($v['public_url'] ?? '') ?? '',
                ],
            ],
            'appearance' => [$this->appearanceRules(), $this->appearanceValues(...)],
            'mail' => [
                [
                    'from_address' => ['nullable', 'string', 'email:filter', 'max:255'],
                    'from_name' => ['nullable', 'string', 'max:'.CoreSettings::MAIL_FROM_NAME_MAX],
                ],
                fn (array $v): array => [
                    'mail.from_address' => $v['from_address'] ?? '',
                    'mail.from_name' => $v['from_name'] ?? '',
                ],
            ],
            'instance' => [
                [
                    'locale' => ['required', 'string', Rule::in(array_keys(Locales::SUPPORTED))],
                    'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
                ],
                fn (array $v): array => [
                    'instance.locale' => $v['locale'],
                    'instance.timezone' => $v['timezone'],
                ],
            ],
            'maintenance' => [
                [
                    'enabled' => ['required', 'boolean'],
                    'message' => ['nullable', 'string', 'max:'.CoreSettings::MAINTENANCE_MESSAGE_MAX],
                ],
                fn (array $v): array => [
                    'maintenance.enabled' => (bool) $v['enabled'],
                    'maintenance.message' => $v['message'] ?? '',
                ],
            ],
            'limits' => [
                [
                    'api_rate_limit' => ['required', 'integer', 'between:0,'.CoreSettings::API_RATE_LIMIT_MAX],
                    'activity_retention_days' => ['required', 'integer', 'between:0,'.CoreSettings::ACTIVITY_RETENTION_MAX],
                    'notifications_retention_days' => ['required', 'integer', 'between:'.CoreSettings::NOTIFICATIONS_RETENTION_MIN.','.CoreSettings::NOTIFICATIONS_RETENTION_MAX],
                ],
                fn (array $v): array => [
                    'api.rate_limit' => (int) $v['api_rate_limit'],
                    'activity.retention_days' => (int) $v['activity_retention_days'],
                    'notifications.retention_days' => (int) $v['notifications_retention_days'],
                ],
            ],
            default => throw new InvalidArgumentException("Unknown settings section [{$section}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function appearanceRules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $rules = ['dark_mode' => ['required', Rule::in(['auto', 'manual'])], 'colors' => ['nullable', 'array'], 'dark_colors' => ['nullable', 'array']];

        foreach (array_keys(ThemeColorPalette::DEFAULTS) as $group) {
            $rules['colors.'.$group] = $hex;
            $rules['dark_colors.'.$group] = $hex;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function appearanceValues(array $v): array
    {
        $values = ['theme.dark_mode' => $v['dark_mode']];

        foreach (array_keys(ThemeColorPalette::DEFAULTS) as $group) {
            $values['theme.'.$group] = strtolower((string) ($v['colors'][$group] ?? ''));
            $values['theme.'.$group.'_dark'] = strtolower((string) ($v['dark_colors'][$group] ?? ''));
        }

        return $values;
    }

    private function differs(mixed $old, mixed $new): bool
    {
        return (string) $old !== (string) $new;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'colors.*.regex' => __('Enter a color as #rrggbb.'),
            'dark_colors.*.regex' => __('Enter a color as #rrggbb.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'subtitle' => __('subtitle'),
            'login_notice' => __('login page message'),
            'support_email' => __('support email'),
            'public_url' => __('public URL'),
            'from_address' => __('sender address'),
            'from_name' => __('sender name'),
            'locale' => __('default language'),
            'timezone' => __('time zone'),
            'message' => __('message'),
            'api_rate_limit' => __('API limit'),
            'activity_retention_days' => __('activity log retention'),
            'notifications_retention_days' => __('notifications retention'),
        ];
    }
}
