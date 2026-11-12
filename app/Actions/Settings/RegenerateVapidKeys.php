<?php

namespace App\Actions\Settings;

use App\Activity\ActivityLogger;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Push\VapidKeys;
use App\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Génère une nouvelle paire de clés VAPID (Owner seulement) et l'enregistre dans les réglages : la clé privée y est
 * chiffrée. Les abonnements Web Push existants sont liés à l'ancienne clé publique et deviennent invalides : ils sont
 * supprimés, chaque navigateur se réabonne à sa prochaine visite. Journalisé sans aucune clé.
 */
class RegenerateVapidKeys
{
    public function __construct(private readonly AppSettings $settings, private readonly ActivityLogger $logger) {}

    /**
     * @return int Nombre d'abonnements supprimés.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor): int
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $keys = VapidKeys::generate();

        if ($keys === null) {
            throw ValidationException::withMessages(['vapid' => __('The VAPID keys could not be generated (is the openssl extension available?).')]);
        }

        $deleted = DB::transaction(function () use ($keys): int {
            $this->settings->setMany(['webpush.public_key' => $keys['public'], 'webpush.private_key' => $keys['private']]);

            return WebPushSubscription::query()->delete();
        });

        $this->logger->record('settings.vapid_regenerated', null, null, ['subscriptions_deleted' => $deleted], $actor);

        return $deleted;
    }
}
