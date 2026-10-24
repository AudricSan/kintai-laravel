<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use kintai\Core\Repositories\WebPushSubscriptionRepositoryInterface;

/**
 * Web push natif (protocole Web Push + VAPID) vers les navigateurs abonnés, sans Firebase. Distinct de
 * PushNotificationService, qui reste le relais FCM de l'application mobile.
 *
 * Meilleur effort : ne lève jamais. Une erreur d'envoi est journalisée sans relance ; un abonnement que le service de
 * push déclare expiré (404 / 410) est supprimé. Sans clés VAPID ou sans extension PHP nécessaire, ne fait rien.
 */
final class WebPushService
{
    /** Durée de conservation du message par le service de push quand le navigateur est éteint : 24 h. */
    private const TTL = 86400;

    /** @var array<int, array[]> abonnements déjà chargés, par utilisateur */
    private array $cache = [];

    public function __construct(
        private readonly WebPushSubscriptionRepositoryInterface $subscriptions,
        private readonly VapidKeyStore $keys,
        // Injectable pour les tests ; par défaut un client avec des délais courts, qui ne suit pas les redirections.
        private readonly ?ClientInterface $client = null,
        // Contrôle de l'adresse refait à chaque envoi : le nom a pu changer de cible depuis l'abonnement (rebinding DNS).
        private readonly ?WebPushEndpointValidator $endpoints = null,
    ) {}

    /**
     * Charge en une requête les abonnements de ces utilisateurs (envoi groupé).
     *
     * @param list<int> $userIds
     */
    public function preload(array $userIds): void
    {
        $missing = array_values(array_filter(
            array_unique(array_map('intval', $userIds)),
            fn(int $id) => !isset($this->cache[$id]),
        ));
        if ($missing === []) {
            return;
        }
        $found = $this->subscriptions->findByUsers($missing);
        foreach ($missing as $id) {
            $this->cache[$id] = $found[$id] ?? [];
        }
    }

    /**
     * @param array<string, scalar> $data type, link, notification_id : lus par le service worker
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        try {
            if (!$this->keys->isAvailable()) {
                return;
            }
            $this->preload([$userId]);
            $subscriptions = $this->cache[$userId];
            unset($this->cache[$userId]);
            if ($subscriptions === []) {
                return;
            }

            $payload = json_encode(
                ['title' => $title, 'body' => $body] + array_map('strval', $data),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            $webPush = new WebPush(
                ['VAPID' => [
                    'subject'    => $this->keys->subject(),
                    'publicKey'  => (string) $this->keys->publicKey(),
                    'privateKey' => (string) $this->keys->privateKey(),
                ]],
                ['TTL' => self::TTL, 'urgency' => 'normal'],
                $this->client ?? $this->defaultClient(),
            );

            $byEndpoint = [];
            foreach ($subscriptions as $row) {
                // Une adresse qui ne passe plus le contrôle n'est pas contactée (et pas supprimée : un échec DNS passager
                // ne doit pas faire perdre l'abonnement ; la purge des abonnements inutilisés s'en charge).
                if ($this->endpoints !== null && !$this->endpoints->isAcceptable((string) $row['endpoint'])) {
                    Log::warning('web_push_endpoint_rejected', ['user_id' => $userId, 'subscription_id' => (int) $row['id']]);
                    continue;
                }
                $byEndpoint[$row['endpoint']] = $row;
                // aes128gcm (RFC 8291) : le seul encodage accepté par Safari / Apple, et pris en charge par tous les autres.
                $webPush->queueNotification(Subscription::create([
                    'endpoint'        => $row['endpoint'],
                    'keys'            => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
                    'contentEncoding' => ContentEncoding::aes128gcm,
                ]), $payload);
            }

            foreach ($webPush->flush() as $report) {
                $row = $byEndpoint[$report->getEndpoint()] ?? null;
                if ($row === null) {
                    continue;
                }
                if ($report->isSuccess()) {
                    $this->subscriptions->touchLastUsed((int) $row['id']);
                } elseif ($report->isSubscriptionExpired()) {
                    $this->subscriptions->deleteById((int) $row['id']);
                } else {
                    Log::warning('web_push_send_failed', ['user_id' => $userId, 'reason' => $report->getReason()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('web_push_error', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function defaultClient(): ClientInterface
    {
        return new \GuzzleHttp\Client(['timeout' => 5, 'connect_timeout' => 3, 'allow_redirects' => false, 'http_errors' => false]);
    }
}
