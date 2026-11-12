<?php

namespace App\Push;

use App\Models\WebPushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Envoi réel par `minishlink/web-push` (chiffrement aes128gcm, RFC 8291, et authentification VAPID).
 */
class MinishlinkWebPushSender implements WebPushSender
{
    /** Durée de conservation du message par le service de push quand le navigateur est éteint : 24 h. */
    private const TTL = 86400;

    public function __construct(private readonly VapidKeys $keys) {}

    public function send(WebPushSubscription $subscription, string $payload): WebPushResult
    {
        try {
            $webPush = new WebPush(
                ['VAPID' => [
                    'subject' => $this->keys->subject(),
                    'publicKey' => (string) $this->keys->publicKey(),
                    'privateKey' => (string) $this->keys->privateKey(),
                ]],
                ['TTL' => self::TTL, 'urgency' => 'normal'],
                // Délais courts, aucune redirection suivie (une redirection pourrait viser une adresse interne).
                new Client(['timeout' => 5, 'connect_timeout' => 3, 'allow_redirects' => false, 'http_errors' => false]),
            );

            $report = $webPush->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth],
                'contentEncoding' => ContentEncoding::aes128gcm,
            ]), $payload);
        } catch (Throwable $e) {
            report($e);

            return WebPushResult::Failed;
        }

        if ($report->isSuccess()) {
            return WebPushResult::Sent;
        }

        if ($report->isSubscriptionExpired()) {
            return WebPushResult::Expired;
        }

        Log::warning('Web Push send failed.', ['user_id' => $subscription->user_id, 'reason' => $report->getReason()]);

        return WebPushResult::Failed;
    }
}
