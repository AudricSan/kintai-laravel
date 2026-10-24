<?php

declare(strict_types=1);

namespace kintai\Core\Services\Calendar;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Services\SecretBox;

/**
 * Identifiants Google de l'instance : chaque instance auto-hébergée crée son propre projet Google Cloud (client OAuth
 * de type « application Web »). Le secret client est chiffré en base et n'est jamais renvoyé au navigateur.
 */
final class GoogleCalendarSettings
{
    public const CLIENT_ID     = 'google_calendar_client_id';
    public const CLIENT_SECRET = 'google_calendar_client_secret';
    public const ENABLED       = 'google_calendar_enabled';

    public function __construct(
        private readonly AppSettingsRepositoryInterface $settings,
        private readonly SecretBox $secrets,
    ) {}

    public function clientId(): string
    {
        return trim((string) $this->settings->get(self::CLIENT_ID));
    }

    public function clientSecret(): string
    {
        $stored = (string) $this->settings->get(self::CLIENT_SECRET);
        return $stored === '' ? '' : (string) $this->secrets->decrypt($stored);
    }

    public function hasSecret(): bool
    {
        return $this->clientSecret() !== '';
    }

    /** Activée par l'Owner ET identifiants renseignés : c'est la condition pour proposer « Connecter Google Calendar ». */
    public function isAvailable(): bool
    {
        return (string) $this->settings->get(self::ENABLED) === '1' && $this->clientId() !== '' && $this->hasSecret();
    }

    public function isEnabled(): bool
    {
        return (string) $this->settings->get(self::ENABLED) === '1';
    }

    /** @param string|null $clientSecret null ou vide : le secret enregistré est conservé */
    public function save(bool $enabled, string $clientId, ?string $clientSecret): void
    {
        $values = [self::ENABLED => $enabled ? '1' : '0', self::CLIENT_ID => trim($clientId)];
        if ($clientSecret !== null && trim($clientSecret) !== '') {
            $values[self::CLIENT_SECRET] = $this->secrets->encrypt(trim($clientSecret));
        }
        $this->settings->setMany($values);
    }
}
