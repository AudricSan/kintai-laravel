<?php

namespace App\Actions\Push;

use App\Models\DevicePushToken;
use App\Models\WebPushSubscription;
use Illuminate\Database\Eloquent\Builder;

/**
 * Supprime les abonnements Web Push et les jetons FCM inactifs depuis plus de 180 jours (`last_used_at`, ou
 * `updated_at` pour une ligne jamais utilisée). Un abonnement actif est rafraîchi à chaque envoi réussi.
 */
class PurgeInactivePushTargets
{
    public const IDLE_DAYS = 180;

    /**
     * @return array{subscriptions: int, tokens: int}
     */
    public function handle(): array
    {
        $cutoff = now()->subDays(self::IDLE_DAYS);

        $inactive = fn (Builder $query): Builder => $query->where(
            fn (Builder $q) => $q->where('last_used_at', '<', $cutoff)
                ->orWhere(fn (Builder $q) => $q->whereNull('last_used_at')->where('updated_at', '<', $cutoff)),
        );

        return [
            'subscriptions' => $inactive(WebPushSubscription::query())->delete(),
            'tokens' => $inactive(DevicePushToken::query())->delete(),
        ];
    }
}
