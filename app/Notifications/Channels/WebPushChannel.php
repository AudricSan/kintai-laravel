<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Notifications\KintaiNotification;
use App\Push\PushPayload;
use App\Push\VapidKeys;
use App\Push\WebPushEndpointValidator;
use App\Push\WebPushResult;
use App\Push\WebPushSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canal Web Push (navigateurs, VAPID). Meilleur effort : ne lève jamais, un échec est journalisé sans empêcher
 * les autres canaux ni l'opération qui a déclenché la notification. Un abonnement que le service de push déclare
 * expiré (404 / 410) est supprimé.
 */
class WebPushChannel
{
    public function __construct(
        private readonly WebPushSender $sender,
        private readonly WebPushEndpointValidator $endpoints,
    ) {}

    /**
     * Le canal a-t-il quelque chose à faire pour cet utilisateur (clés VAPID et au moins un abonnement) ?
     */
    public static function isAvailableFor(User $user): bool
    {
        return app(VapidKeys::class)->isConfigured() && $user->webPushSubscriptions()->exists();
    }

    public function send(object $notifiable, KintaiNotification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        try {
            $payload = PushPayload::for($notification, $notifiable)->toJson();

            foreach ($notifiable->webPushSubscriptions()->get() as $subscription) {
                $this->sendTo($subscription, $payload);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function sendTo(WebPushSubscription $subscription, string $payload): void
    {
        // Adresse contrôlée à chaque envoi : le nom a pu changer de cible depuis l'abonnement (rebinding DNS).
        // Elle n'est pas supprimée pour autant : un échec DNS passager ne doit pas faire perdre l'abonnement.
        if (! $this->endpoints->isAcceptable($subscription->endpoint)) {
            Log::warning('Web Push endpoint rejected.', ['user_id' => $subscription->user_id, 'subscription_id' => $subscription->id]);

            return;
        }

        try {
            $result = $this->sender->send($subscription, $payload);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        match ($result) {
            WebPushResult::Sent => $subscription->forceFill(['last_used_at' => now()])->save(),
            WebPushResult::Expired => $subscription->delete(),
            WebPushResult::Failed => null,
        };
    }
}
