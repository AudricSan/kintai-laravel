<?php

namespace Kintai\Bundles\GoogleCalendar;

use Kintai\Contracts\Settings\SettingDefinition;
use Kintai\Contracts\Settings\Settings;

/**
 * Identifiants Google de l'instance : chaque instance auto-hébergée crée son propre projet Google Cloud (client OAuth de
 * type « application Web »). Les réglages vivent dans les réglages de l'instance (`Settings`) ; le secret client y est
 * déclaré chiffré et n'est jamais renvoyé au navigateur.
 */
class GoogleCalendarSettings
{
    public const ENABLED = 'google_calendar.enabled';

    public const CLIENT_ID = 'google_calendar.client_id';

    public const CLIENT_SECRET = 'google_calendar.client_secret';

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinition(self::ENABLED, SettingDefinition::TYPE_BOOL, false),
            new SettingDefinition(self::CLIENT_ID, SettingDefinition::TYPE_STRING, ''),
            new SettingDefinition(self::CLIENT_SECRET, SettingDefinition::TYPE_STRING, '', encrypted: true),
        ];
    }

    public function clientId(): string
    {
        return trim($this->settings->string(self::CLIENT_ID));
    }

    public function clientSecret(): string
    {
        return trim($this->settings->string(self::CLIENT_SECRET));
    }

    public function hasSecret(): bool
    {
        return $this->clientSecret() !== '';
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool(self::ENABLED);
    }

    /**
     * Activée par l'Owner ET identifiants renseignés : condition pour proposer « Connecter Google Calendar ».
     */
    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->clientId() !== '' && $this->hasSecret();
    }

    /**
     * @param  string|null  $clientSecret  null ou vide : le secret enregistré est conservé.
     */
    public function save(bool $enabled, string $clientId, ?string $clientSecret): void
    {
        $this->settings->set(self::ENABLED, $enabled);
        $this->settings->set(self::CLIENT_ID, trim($clientId));

        if ($clientSecret !== null && trim($clientSecret) !== '') {
            $this->settings->set(self::CLIENT_SECRET, trim($clientSecret));
        }
    }
}
