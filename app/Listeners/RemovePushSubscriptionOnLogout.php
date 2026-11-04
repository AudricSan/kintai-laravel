<?php

namespace App\Listeners;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Auth\Events\Logout;
use Throwable;

/**
 * Le navigateur qui se déconnecte ne doit plus recevoir les notifications de ce compte : son abonnement Web Push
 * (adresse envoyée avec le formulaire de déconnexion par le script) est supprimé. Seul celui de ce compte l'est ;
 * la déconnexion prime sur ce nettoyage.
 */
class RemovePushSubscriptionOnLogout
{
    public function handle(Logout $event): void
    {
        $endpoint = trim((string) request()->input('push_endpoint', ''));

        if ($endpoint === '' || ! $event->user instanceof User) {
            return;
        }

        try {
            WebPushSubscription::query()
                ->where('user_id', $event->user->id)
                ->where('endpoint_hash', WebPushSubscription::hashEndpoint($endpoint))
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
