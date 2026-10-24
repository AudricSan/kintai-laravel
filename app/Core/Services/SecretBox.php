<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;

/**
 * Chiffrement des secrets gardés en base (jeton de rafraîchissement Google, secret client OAuth) : AES-256-GCM
 * (openssl, déjà requis par le web push) avec une clé dérivée du secret d'instance, créé au premier besoin dans
 * `app_settings`.
 *
 * Le texte chiffré est préfixé « enc1: » : une valeur sans préfixe est lue telle quelle et un texte altéré ou chiffré par
 * une autre instance est refusé (null) plutôt que déchiffré en désordre (le mode GCM authentifie le contenu).
 */
final class SecretBox
{
    private const PREFIX = 'enc1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES  = 12;
    private const TAG_BYTES = 16;

    public function __construct(private readonly AppSettingsRepositoryInterface $settings) {}

    public function encrypt(string $plain): string
    {
        $iv     = random_bytes(self::IV_BYTES);
        $tag    = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($cipher === false) {
            throw new \RuntimeException('Chiffrement impossible : openssl indisponible.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** @return string|null null si la valeur est illisible (altérée, ou chiffrée avec une autre clé) */
    public function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }
        $iv     = substr($raw, 0, self::IV_BYTES);
        $tag    = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $cipher = substr($raw, self::IV_BYTES + self::TAG_BYTES);
        $plain  = openssl_decrypt($cipher, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        $secret = $this->settings->get(UnsubscribeTokens::SECRET_KEY);
        if ($secret === null || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->settings->set(UnsubscribeTokens::SECRET_KEY, $secret);
        }
        // Clé propre à cet usage : le même secret signe aussi les liens de désabonnement.
        return hash('sha256', 'kintai-secretbox-v1|' . $secret, true);
    }
}
