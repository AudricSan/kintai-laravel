<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;

/**
 * Signature des liens de désabonnement des e-mails de notification : HMAC-SHA256 de (utilisateur, type, canal) avec
 * un secret propre à l'instance, créé au premier besoin et gardé dans `app_settings`.
 *
 * Le lien ne demande aucune connexion (il est ouvert depuis un client mail) : la signature est la seule preuve. Elle
 * n'expire pas, mais ne donne le droit de faire qu'une chose, couper ce canal pour ce type et cet utilisateur.
 */
final class UnsubscribeTokens
{
    public const SECRET_KEY = 'instance_secret';

    public function __construct(private readonly AppSettingsRepositoryInterface $settings) {}

    public function sign(int $userId, string $type, string $channel): string
    {
        return hash_hmac('sha256', $this->payload($userId, $type, $channel), $this->secret());
    }

    public function verify(int $userId, string $type, string $channel, string $signature): bool
    {
        return $userId > 0 && $type !== '' && $signature !== ''
            && hash_equals($this->sign($userId, $type, $channel), $signature);
    }

    private function payload(int $userId, string $type, string $channel): string
    {
        return 'unsubscribe|' . $userId . '|' . $type . '|' . $channel;
    }

    private function secret(): string
    {
        $secret = $this->settings->get(self::SECRET_KEY);
        if ($secret === null || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->settings->set(self::SECRET_KEY, $secret);
        }
        return $secret;
    }
}
