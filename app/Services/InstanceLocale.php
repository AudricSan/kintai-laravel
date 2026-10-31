<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Language;

/**
 * Langue de l'instance : celle de tous les textes pour un utilisateur qui n'a pas choisi la sienne dans son profil.
 * Choisie par l'Owner à l'installation puis dans les paramètres de l'instance, enregistrée dans le fichier
 * d'environnement (APP_LOCALE).
 *
 * Ordre de choix de la langue d'affichage (I18nMiddleware) :
 * - connecté : langue du profil, sinon langue de l'instance ;
 * - non connecté (page de connexion…) : langue choisie avec le sélecteur (session), puis celle du dernier compte
 *   connecté sur ce navigateur (LocaleCookie), puis celle du navigateur, puis celle de l'instance.
 */
final class InstanceLocale
{
    public const ENV_KEY = 'APP_LOCALE';

    /** Langue proposée à l'installation d'une nouvelle instance quand elle est disponible. */
    public const NEW_INSTANCE_DEFAULT = 'en';

    public function __construct(
        private readonly ?EnvFileWriter $envFile = null,
    ) {}

    public function code(): string
    {
        return self::resolve();
    }

    /** APP_LOCALE si c'est une langue active ; sinon la première langue active, ou l'anglais. */
    public static function resolve(): string
    {
        $active     = array_column(Language::findAllActive(), 'code');
        $configured = (string) env(self::ENV_KEY, '');
        if ($configured !== '' && in_array($configured, $active, true)) {
            return $configured;
        }

        return $active[0] ?? self::NEW_INSTANCE_DEFAULT;
    }

    /**
     * Langue proposée à l'installation d'une nouvelle instance (installateur web, kintai:provision) parmi les codes
     * disponibles : l'anglais s'il y figure, sinon le premier.
     *
     * @param list<string> $codes
     */
    public static function defaultForNewInstance(array $codes): string
    {
        return in_array(self::NEW_INSTANCE_DEFAULT, $codes, true) ? self::NEW_INSTANCE_DEFAULT : ($codes[0] ?? self::NEW_INSTANCE_DEFAULT);
    }

    /** Langue d'un utilisateur : celle de son profil si elle est active, sinon celle de l'instance. */
    public function forUser(?array $user): string
    {
        $language = (string) ($user['language'] ?? '');
        return $language !== '' && $this->isActive($language) ? $language : $this->code();
    }

    public function isActive(string $code): bool
    {
        return in_array($code, array_column(Language::findAllActive(), 'code'), true);
    }

    /**
     * Change la langue de l'instance (écrite dans le fichier d'environnement).
     *
     * @throws \InvalidArgumentException langue inconnue ou inactive
     * @throws \RuntimeException         fichier d'environnement non inscriptible
     */
    public function set(string $code): void
    {
        if (!$this->isActive($code)) {
            throw new \InvalidArgumentException("Langue inactive : {$code}");
        }
        ($this->envFile ?? new EnvFileWriter())->set(self::ENV_KEY, $code);
    }
}
