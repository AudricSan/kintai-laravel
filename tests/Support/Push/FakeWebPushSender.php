<?php

namespace Tests\Support\Push;

use App\Models\WebPushSubscription;
use App\Push\WebPushResult;
use App\Push\WebPushSender;
use RuntimeException;

/**
 * Faux expéditeur Web Push : aucun réseau. Enregistre les envois ; le résultat se règle par adresse.
 */
class FakeWebPushSender implements WebPushSender
{
    /** @var list<array{endpoint: string, payload: array<string, mixed>}> */
    public array $sent = [];

    /** @var array<string, WebPushResult> */
    public array $results = [];

    public bool $throws = false;

    public function send(WebPushSubscription $subscription, string $payload): WebPushResult
    {
        if ($this->throws) {
            throw new RuntimeException('Push service down.');
        }

        $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => json_decode($payload, true, flags: JSON_THROW_ON_ERROR)];

        return $this->results[$subscription->endpoint] ?? WebPushResult::Sent;
    }
}
