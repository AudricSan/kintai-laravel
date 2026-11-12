<?php

namespace App\Push;

use App\Models\WebPushSubscription;

/**
 * Envoi d'un message Web Push chiffré à un abonnement. Isolé derrière une interface pour que les tests
 * n'ouvrent aucune connexion réseau.
 */
interface WebPushSender
{
    public function send(WebPushSubscription $subscription, string $payload): WebPushResult;
}
