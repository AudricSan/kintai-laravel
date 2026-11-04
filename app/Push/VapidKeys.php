<?php

namespace App\Push;

/**
 * Clés VAPID de l'instance, lues dans la configuration (`kintai.webpush`). Sans clés, le Web Push est désactivé.
 */
class VapidKeys
{
    /** Contact de repli (exigé par les services de push) quand l'application n'est pas en https. */
    private const FALLBACK_SUBJECT = 'https://github.com/AudricSan/Kintai';

    public function publicKey(): ?string
    {
        return $this->setting('public_key');
    }

    public function privateKey(): ?string
    {
        return $this->setting('private_key');
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
        $explicit = $this->setting('subject');

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

    private function setting(string $key): ?string
    {
        $value = config('kintai.webpush.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
