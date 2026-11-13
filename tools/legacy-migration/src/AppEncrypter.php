<?php

declare(strict_types=1);

namespace KintaiLegacy;

/**
 * Chiffrement au format de Laravel (`Crypt::encryptString`, chiffrement AES-256-CBC du projet) avec l'APP_KEY de la cible, pour
 * les colonnes au cast `encrypted`. Réimplémenté ici : l'outil n'utilise ni Composer ni `app/`.
 */
final class AppEncrypter
{
    private string $key;

    /** @param  string  $appKey  Valeur d'APP_KEY, `base64:...` ou clé brute de 32 octets. */
    public function __construct(#[\SensitiveParameter] string $appKey)
    {
        $key = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if ($key === false || strlen($key) !== 32) {
            throw new MigrationException('APP_KEY de la cible invalide : une clé AES-256 de 32 octets est attendue (php artisan key:generate).');
        }
        $this->key = $key;
    }

    public function encryptString(#[\SensitiveParameter] string $value): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $this->key, 0, $iv);
        if ($encrypted === false) {
            throw new MigrationException('Chiffrement impossible : openssl indisponible.');
        }
        $iv = base64_encode($iv);
        $mac = hash_hmac('sha256', $iv.$encrypted, $this->key);

        return base64_encode((string) json_encode(['iv' => $iv, 'value' => $encrypted, 'mac' => $mac, 'tag' => ''], JSON_UNESCAPED_SLASHES));
    }
}
