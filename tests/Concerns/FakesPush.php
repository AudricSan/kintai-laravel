<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Push\WebPushEndpointValidator;
use App\Push\WebPushSender;
use Tests\Support\Push\FakeWebPushSender;

/**
 * Outils de test du push : clés VAPID de test, expéditeur et DNS factices (aucun réseau).
 */
trait FakesPush
{
    protected function configureVapid(): void
    {
        config([
            'kintai.webpush.public_key' => 'BPublicKeyForTests',
            'kintai.webpush.private_key' => 'PrivateKeyForTests',
        ]);
    }

    protected function fakeWebPushSender(): FakeWebPushSender
    {
        $sender = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $sender);
        // Tout nom se résout vers une adresse publique.
        $this->app->instance(WebPushEndpointValidator::class, new WebPushEndpointValidator(fn (): array => ['93.184.216.34']));

        return $sender;
    }

    protected function subscribe(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): WebPushSubscription
    {
        return WebPushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => WebPushSubscription::hashEndpoint($endpoint),
            'p256dh' => 'p256dh-key',
            'auth' => 'auth-key',
        ]);
    }
}
