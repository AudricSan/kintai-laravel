<?php

namespace Kintai\Bundles\GoogleCalendar;

use App\Settings\PublicUrl;
use Kintai\Contracts\Settings\Settings;

/**
 * Adresse de retour OAuth, à déclarer à l'identique dans la console Google Cloud : URL publique de l'instance (réglage
 * de l'Owner, normalisée par `PublicUrl`), sinon racine de la requête courante. Affichée à l'Owner pour qu'il la
 * recopie, et envoyée à Google pendant la liaison.
 */
class GoogleRedirectUri
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Racine publique de l'instance, sans « / » final.
     */
    public function root(): string
    {
        return PublicUrl::normalize($this->settings->string('organization.public_url')) ?? rtrim(url('/'), '/');
    }

    public function resolve(): string
    {
        return $this->root().route('google-calendar.callback', [], false);
    }
}
