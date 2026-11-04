<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Push\VapidKeys;
use App\Push\WebPushEndpointValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Abonnements Web Push du navigateur de l'utilisateur connecté (JSON, protégé par le CSRF de la session).
 */
class PushSubscriptionController extends Controller
{
    /**
     * Enregistre ou rafraîchit l'abonnement de ce navigateur. Appelé à l'activation, puis par le script quand le
     * navigateur renouvelle l'abonnement. Un abonnement déjà enregistré pour un autre compte passe à celui-ci
     * (navigateur partagé).
     */
    public function store(Request $request, WebPushEndpointValidator $endpoints, VapidKeys $keys): JsonResponse
    {
        abort_unless($keys->isConfigured(), 404);

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            // Clés en base64 URL (alphabet restreint, longueur bornée) : rien d'autre n'est jamais stocké.
            'keys.p256dh' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
        ]);

        $endpoint = trim($data['endpoint']);

        if (! $endpoints->isAcceptable($endpoint)) {
            throw ValidationException::withMessages(['endpoint' => __('This push subscription is not valid.')]);
        }

        /** @var User $user */
        $user = $request->user();

        WebPushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => WebPushSubscription::hashEndpoint($endpoint)],
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Supprime l'abonnement de ce navigateur (l'utilisateur a désactivé les notifications sur cet appareil).
     * Ne touche qu'aux abonnements de l'utilisateur connecté.
     */
    public function destroy(Request $request): JsonResponse
    {
        $endpoint = trim((string) $request->input('endpoint', ''));

        if ($endpoint !== '') {
            WebPushSubscription::query()
                ->where('user_id', $request->user()?->id)
                ->where('endpoint_hash', WebPushSubscription::hashEndpoint($endpoint))
                ->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * État du Web Push : disponible sur l'instance (clés VAPID) et, si `endpoint` est fourni, cet abonnement est-il
     * enregistré pour l'utilisateur connecté ? Sert à afficher l'état réel de l'appareil (un abonnement du
     * navigateur peut avoir expiré côté serveur, ou appartenir à un autre compte).
     */
    public function status(Request $request, VapidKeys $keys): JsonResponse
    {
        $endpoint = trim((string) $request->query('endpoint', ''));

        return response()->json([
            'available' => $keys->isConfigured(),
            'public_key' => $keys->publicKey(),
            'subscribed' => $endpoint !== '' && WebPushSubscription::query()
                ->where('user_id', $request->user()?->id)
                ->where('endpoint_hash', WebPushSubscription::hashEndpoint($endpoint))
                ->exists(),
        ]);
    }
}
