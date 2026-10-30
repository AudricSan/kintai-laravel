<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailerService;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Models\WebPushSubscription;

/**
 * État des canaux de notification de l'instance, pour l'Owner : ce qui fonctionne, ce qui manque, et par où passerait
 * une notification de test. La cloche est toujours disponible.
 */
final class NotificationChannelStatus
{
    /**
     * @param array $pushConfig contenu de config/push.php
     */
    public function __construct(
        private readonly VapidKeyStore $vapid,
        private readonly MailerService $mailer,
        private readonly PublicUrlResolver $publicUrl,
        private readonly array $pushConfig = [],
    ) {}

    /**
     * @return array{
     *   web_push: array{available: bool, has_keys: bool, missing_extensions: list<string>, subscriptions: int},
     *   email: array{available: bool, configured: bool, driver: string, has_public_url: bool},
     *   mobile: array{available: bool, enabled: bool, has_project: bool, has_credentials: bool}
     * }
     */
    public function all(): array
    {
        $fcm         = $this->pushConfig['fcm'] ?? [];
        $enabled     = (bool) ($fcm['enabled'] ?? false);
        $hasProject  = trim((string) ($fcm['project_id'] ?? '')) !== '';
        $credentials = (string) ($fcm['credentials_path'] ?? '');
        $hasCreds    = $credentials !== '' && is_file($credentials);

        $configured = $this->mailer->isConfigured();
        $hasUrl     = $this->publicUrl->resolve() !== null;

        return [
            'web_push' => [
                'available'          => $this->vapid->isAvailable(),
                'has_keys'           => $this->vapid->publicKey() !== null && $this->vapid->privateKey() !== null,
                'missing_extensions' => VapidKeyStore::missingExtensions(),
                'subscriptions'      => WebPushSubscription::countAll(),
            ],
            'email' => [
                'available'      => $configured && $hasUrl,
                'configured'     => $configured,
                'driver'         => $this->mailer->driver(),
                'has_public_url' => $hasUrl,
            ],
            'mobile' => [
                'available'       => $enabled && $hasProject && $hasCreds,
                'enabled'         => $enabled,
                'has_project'     => $hasProject,
                'has_credentials' => $hasCreds,
            ],
        ];
    }

    /**
     * Canaux par lesquels une notification de test atteindrait cet utilisateur : « bell » toujours ; « web_push » s'il a
     * au moins un navigateur abonné ; « mobile » s'il a au moins un appareil ; « email » s'il a une adresse valide.
     *
     * @return list<string>
     */
    public function testChannels(int $userId): array
    {
        $status   = $this->all();
        $channels = ['bell'];

        if ($status['web_push']['available'] && WebPushSubscription::findByUser($userId) !== []) {
            $channels[] = 'web_push';
        }
        if ($status['mobile']['available'] && DevicePushToken::findByUser($userId) !== []) {
            $channels[] = 'mobile';
        }
        $email = (string) (User::findById($userId)['email'] ?? '');
        if ($status['email']['available'] && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $channels[] = 'email';
        }

        return $channels;
    }
}
