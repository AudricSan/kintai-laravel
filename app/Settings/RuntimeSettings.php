<?php

namespace App\Settings;

use App\Support\Locales;
use DateTimeZone;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\URL;

/**
 * Applique à la configuration d'exécution les réglages de l'instance qui la remplacent : langue par défaut, fuseau,
 * expéditeur des mails, URL publique. Appelé au démarrage (fournisseur de services) puis après chaque enregistrement
 * de réglages, pour que la requête en cours en tienne compte. Sans valeur en base, la configuration d'origine reste.
 */
class RuntimeSettings
{
    /** @var array<string, mixed>|null Expéditeur de `config/mail.php`, capturé avant tout remplacement. */
    private ?array $originalMailFrom = null;

    /** L'URL racine des liens a-t-elle été forcée par un appel précédent ? */
    private bool $urlForced = false;

    public function __construct(private readonly AppSettings $settings) {}

    public function apply(): void
    {
        $this->applyLocale();
        $this->applyTimezone();
        $this->applyMailSender();
        $this->applyPublicUrl();
    }

    private function applyLocale(): void
    {
        $locale = $this->settings->instanceLocale();

        if ($this->settings->isStored('instance.locale') && Locales::isSupported($locale)) {
            config(['app.locale' => $locale]);
            app()->setLocale($locale);
        }
    }

    private function applyTimezone(): void
    {
        $timezone = $this->settings->instanceTimezone();

        if ($this->settings->isStored('instance.timezone') && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            config(['kintai.timezone' => $timezone]);
        }
    }

    /**
     * L'adresse et le nom d'expéditeur de l'Owner remplacent `mail.from` ; vides, ceux de `config/mail.php` reviennent.
     */
    private function applyMailSender(): void
    {
        $this->originalMailFrom ??= (array) config('mail.from', []);

        $address = $this->settings->mailFromAddress();
        $name = $this->settings->mailFromName();

        config(['mail.from' => [
            'address' => $address !== '' ? $address : ($this->originalMailFrom['address'] ?? null),
            'name' => $name !== '' ? $name : ($this->originalMailFrom['name'] ?? null),
        ]]);

        // Les expéditeurs déjà construits gardent l'ancien `from` : on les reconstruit à la prochaine utilisation.
        $manager = app()->resolved('mail.manager') ? app('mail.manager') : null;

        if ($manager instanceof MailManager) {
            $manager->forgetMailers();
        }
    }

    /**
     * Hors requête web (commandes, tâches planifiées, file d'attente), les liens générés (mails, notifications,
     * flux iCal) partent de l'URL publique réglée par l'Owner, sinon de `APP_URL`. Jamais de l'en-tête Host.
     */
    private function applyPublicUrl(): void
    {
        $url = $this->settings->publicUrl();

        // Rien à défaire tant que nous n'avons rien forcé.
        if (! app()->runningInConsole() || ($url === '' && ! $this->urlForced)) {
            return;
        }

        URL::forceRootUrl($url !== '' ? $url : null);
        URL::forceScheme($url !== '' ? (string) parse_url($url, PHP_URL_SCHEME) : null);
        $this->urlForced = $url !== '';
    }
}
