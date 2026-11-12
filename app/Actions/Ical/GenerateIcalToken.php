<?php

namespace App\Actions\Ical;

use App\Activity\ActivityLogger;
use App\Models\IcalToken;
use App\Models\Store;
use App\Models\User;

/**
 * Crée ou régénère le lien iCal d'un employé pour un magasin : l'ancien jeton cesse aussitôt de fonctionner.
 * Seule l'empreinte est stockée ; le jeton en clair n'est renvoyé qu'ici et ne peut plus être relu ensuite.
 */
class GenerateIcalToken
{
    use AuthorizesIcalTokens;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @return string Le jeton en clair (64 caractères hexadécimaux).
     */
    public function handle(User $actor, User $target, Store $store): string
    {
        $this->authorizeIcalToken($actor, $target, $store);

        $token = bin2hex(random_bytes(32));

        IcalToken::query()->updateOrCreate(
            ['user_id' => $target->id, 'store_id' => $store->id],
            ['token_hash' => IcalToken::hash($token)],
        );

        $this->activity->record('ical.token_regenerated', $target, $store->id, ['name' => $target->name, 'store' => $store->name], $actor);

        return $token;
    }
}
