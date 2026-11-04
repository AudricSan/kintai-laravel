<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\KintaiNotification;
use App\Push\FcmClient;
use App\Push\PushPayload;
use Throwable;

/**
 * Canal FCM (application mobile). Meilleur effort : ne lève jamais, un échec est journalisé sans empêcher les
 * autres canaux ni l'opération qui a déclenché la notification. Un jeton refusé par FCM comme invalide
 * (UNREGISTERED / 404) est supprimé.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $client) {}

    /**
     * Le canal a-t-il quelque chose à faire pour cet utilisateur (FCM activé et au moins un jeton) ?
     */
    public static function isAvailableFor(User $user): bool
    {
        return app(FcmClient::class)->isEnabled() && $user->devicePushTokens()->exists();
    }

    public function send(object $notifiable, KintaiNotification $notification): void
    {
        if (! $notifiable instanceof User || ! $this->client->isEnabled()) {
            return;
        }

        try {
            $tokens = $notifiable->devicePushTokens()->get();

            if ($tokens->isEmpty()) {
                return;
            }

            $payload = PushPayload::for($notification, $notifiable);

            foreach ($tokens as $token) {
                try {
                    match ($this->client->send($token->token, $payload)) {
                        'sent' => $token->forceFill(['last_used_at' => now()])->save(),
                        'unregistered' => $token->delete(),
                        default => null,
                    };
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
