<?php

namespace App\Push;

use App\Settings\AppSettings;

/**
 * Clés VAPID de l'instance, lues dans les réglages (`webpush.*` : base, la clé privée y est chiffrée, sinon
 * `kintai.webpush` de la configuration). Sans clés, le Web Push est désactivé.
 */
class VapidKeys
{
    /** Contact de repli (exigé par les services de push) quand l'application n'est pas en https. */
    private const FALLBACK_SUBJECT = 'https://github.com/AudricSan/Kintai';

    public function publicKey(): ?string
    {
        return $this->nonEmpty(app(AppSettings::class)->webPushPublicKey());
    }

    public function privateKey(): ?string
    {
        return $this->nonEmpty(app(AppSettings::class)->webPushPrivateKey());
    }

    /**
     * Extensions PHP qu'exige le Web Push (chiffrement, requêtes HTTP, chaînes multi-octets) et qui ne sont pas chargées.
     *
     * @return list<string>
     */
    public static function missingExtensions(): array
    {
        return array_values(array_filter(['openssl', 'curl', 'mbstring'], fn (string $extension): bool => ! extension_loaded($extension)));
    }

    public function isConfigured(): bool
    {
        return $this->publicKey() !== null && $this->privateKey() !== null;
    }

    /**
     * Contact transmis aux services de push : réglage explicite, sinon l'URL de l'application si elle est en https.
     */
    public function subject(): string
    {
        $explicit = $this->nonEmpty(config('kintai.webpush.subject'));

        if ($explicit !== null) {
            return $explicit;
        }

        $url = trim((string) config('app.url'));

        return preg_match('#^https://#i', $url) === 1 ? $url : self::FALLBACK_SUBJECT;
    }

    /**
     * Nouvelle paire P-256 (base64 URL sans remplissage, comme l'attendent la bibliothèque et PushManager.subscribe()).
     *
     * La paire est créée avec openssl_pkey_new() plutôt qu'avec VAPID::createVapidKeys() : certaines installations
     * PHP (Windows surtout) n'ont aucun openssl.cnf et la création échoue alors ; on retente avec
     * `config/openssl.cnf`, un fichier minimal fourni par le dépôt.
     *
     * @return array{public: string, private: string}|null Null si openssl ne sait pas la créer, même avec ce repli.
     */
    public static function generate(): ?array
    {
        if (! extension_loaded('openssl')) {
            return null;
        }

        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($options);

        if ($key === false && is_file(base_path('config/openssl.cnf'))) {
            $key = @openssl_pkey_new($options + ['config' => base_path('config/openssl.cnf')]);
        }

        // Vide la file d'erreurs d'openssl, qui sinon pollue le prochain appel.
        while (openssl_error_string() !== false) {
            // rien
        }

        $ec = $key === false ? null : (openssl_pkey_get_details($key)['ec'] ?? null);

        if (! is_array($ec) || ! isset($ec['x'], $ec['y'], $ec['d'])) {
            return null;
        }

        $pad = fn (string $bytes): string => str_pad($bytes, 32, "\0", STR_PAD_LEFT);
        $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'public' => $encode("\x04".$pad($ec['x']).$pad($ec['y'])),
            'private' => $encode($pad($ec['d'])),
        ];
    }

    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
