<?php

namespace App\Actions\Ical;

use App\Activity\ActivityLogger;
use App\Models\IcalToken;
use App\Models\Store;
use App\Models\User;

/**
 * Supprime le lien iCal d'un employé pour un magasin : le flux devient introuvable.
 */
class DeleteIcalToken
{
    use AuthorizesIcalTokens;

    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(User $actor, User $target, Store $store): void
    {
        $this->authorizeIcalToken($actor, $target, $store);

        $deleted = IcalToken::query()->where('user_id', $target->id)->where('store_id', $store->id)->delete();

        if ($deleted > 0) {
            $this->activity->record('ical.token_deleted', $target, $store->id, ['name' => $target->name, 'store' => $store->name], $actor);
        }
    }
}
