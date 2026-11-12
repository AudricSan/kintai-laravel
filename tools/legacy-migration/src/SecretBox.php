<?php

declare(strict_types=1);

namespace KintaiLegacy;

/**
 * Déchiffrement des secrets de l'ancienne version (jeton de rafraîchissement Google) : AES-256-GCM, clé dérivée du secret
 * d'instance (`app_settings.instance_secret`), texte préfixé `enc1:` suivi de base64(iv 12 octets + tag 16 octets + chiffré).
 * Une valeur sans préfixe est un texte en clair. Réimplémenté ici (l'outil est autonome) ; ne chiffre jamais, ne journalise
 * jamais une valeur.
 */
final class SecretBox
{
    private const PREFIX = 'enc1:';

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    /** Vrai si la valeur porte le préfixe du format chiffré actuel. */
    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /** Vrai si le secret d'instance permet de dériver une clé (32 caractères au moins). */
    public static function usableSecret(?string $secret): bool
    {
        return $secret !== null && strlen($secret) >= 32;
    }

    /**
     * @return string|null le texte en clair, ou null si la valeur est illisible : altérée (le mode GCM authentifie le contenu),
     *                     chiffrée avec un autre secret, secret absent, ou préfixe `encN:` inconnu (jamais pris pour du clair)
     */
    public static function decrypt(string $stored, ?string $secret): ?string
    {
        if (! self::isEncrypted($stored)) {
            // Un préfixe de la forme `encN:` d'une autre version du format n'est pas du texte en clair.
            return preg_match('/^enc\d+:/', $stored) === 1 ? null : $stored;
        }
        if (! self::usableSecret($secret)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }
        $key = hash('sha256', 'kintai-secretbox-v1|'.$secret, true);
        $plain = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES), substr($raw, self::IV_BYTES, self::TAG_BYTES),
        );

        return $plain === false ? null : $plain;
    }
}
