<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;

/**
 * Clés VAPID du web push natif : une paire par instance, créée automatiquement (installation, ou migration pour une
 * instance existante) et gardée dans `app_settings`. La clé publique est donnée au navigateur ; la clé privée ne
 * quitte jamais le serveur (ni navigateur, ni journaux).
 *
 * Les clés sont créées avec openssl_pkey_new() plutôt qu'avec VAPID::createVapidKeys() de la bibliothèque : certaines
 * installations PHP (Windows surtout) n'ont aucun openssl.cnf et la création échoue alors ; on retente avec
 * config/openssl.cnf, un fichier minimal fourni par le dépôt.
 */
final class VapidKeyStore
{
    public const PUBLIC_KEY  = 'web_push_vapid_public';
    public const PRIVATE_KEY = 'web_push_vapid_private';
    public const SUBJECT     = 'web_push_subject';

    /** Contact de repli (exigé par les services de push) quand l'instance n'a ni e-mail de support ni URL publique. */
    private const FALLBACK_SUBJECT = 'https://github.com/AudricSan/Kintai';

    public function __construct(private readonly AppSettingsRepositoryInterface $settings) {}

    /** Crée la paire si elle n'existe pas. Retourne faux si la création est impossible (openssl indisponible). */
    public function ensure(): bool
    {
        if ($this->publicKey() !== null && $this->privateKey() !== null) {
            return true;
        }
        return $this->regenerate();
    }

    /**
     * Remplace la paire par une nouvelle : tous les abonnements web existants deviennent invalides.
     *
     * @return bool faux si la création est impossible ; l'ancienne paire est alors conservée
     */
    public function regenerate(): bool
    {
        $pair = self::generate();
        if ($pair === null) {
            return false;
        }
        $this->settings->setMany([self::PUBLIC_KEY => $pair['public'], self::PRIVATE_KEY => $pair['private']]);
        return true;
    }

    public function publicKey(): ?string
    {
        $value = $this->settings->get(self::PUBLIC_KEY);
        return $value !== null && $value !== '' ? $value : null;
    }

    public function privateKey(): ?string
    {
        $value = $this->settings->get(self::PRIVATE_KEY);
        return $value !== null && $value !== '' ? $value : null;
    }

    /** Contact transmis aux services de push : réglage explicite, sinon e-mail de support, sinon URL publique. */
    public function subject(): string
    {
        $explicit = trim((string) $this->settings->get(self::SUBJECT));
        if ($explicit !== '') {
            return $explicit;
        }
        $email = trim((string) $this->settings->get('app_support_email'));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return 'mailto:' . $email;
        }
        $url = trim((string) (env('APP_URL', '') ?: $this->settings->get('app_public_url')));
        if (preg_match('#^https://#i', $url) === 1) {
            return $url;
        }
        return self::FALLBACK_SUBJECT;
    }

    /** Extensions PHP nécessaires à l'envoi. @return list<string> extensions manquantes */
    public static function missingExtensions(): array
    {
        return array_values(array_filter(['openssl', 'curl', 'mbstring'], static fn(string $ext) => !extension_loaded($ext)));
    }

    /** Le web push peut-il fonctionner sur cette instance (extensions chargées, clés présentes) ? */
    public function isAvailable(): bool
    {
        return self::missingExtensions() === [] && $this->publicKey() !== null && $this->privateKey() !== null;
    }

    /**
     * Paire de clés VAPID (P-256) encodées en base64 URL sans remplissage, comme l'attendent la bibliothèque
     * et PushManager.subscribe(). Null si openssl ne sait pas créer la clé, même avec la configuration de repli.
     *
     * @return array{public: string, private: string}|null
     */
    public static function generate(): ?array
    {
        if (!extension_loaded('openssl')) {
            return null;
        }
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $fallback = dirname(__DIR__, 3) . '/config/openssl.cnf';

        $key = @openssl_pkey_new($options);
        if ($key === false && is_file($fallback)) {
            $key = @openssl_pkey_new($options + ['config' => $fallback]);
        }
        // Vide la file d'erreurs d'openssl, qui sinon pollue le prochain appel.
        while (openssl_error_string() !== false) {
        }
        if ($key === false) {
            return null;
        }

        $ec = openssl_pkey_get_details($key)['ec'] ?? null;
        if (!is_array($ec) || !isset($ec['x'], $ec['y'], $ec['d'])) {
            return null;
        }
        $pad = static fn(string $bytes): string => str_pad($bytes, 32, "\0", STR_PAD_LEFT);

        return [
            'public'  => self::base64Url("\x04" . $pad($ec['x']) . $pad($ec['y'])),
            'private' => self::base64Url($pad($ec['d'])),
        ];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
