<?php

namespace App\Actions\Ical;

use App\Models\IcalToken;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Retrouve l'employé et le magasin d'un lien de flux iCal. Réponse unique (null) pour un jeton inconnu, un magasin qui
 * ne correspond pas, un employé inactif, supprimé ou anonymisé, un magasin supprimé ou une appartenance disparue ou
 * désactivée : rien ne permet de distinguer ces cas de l'extérieur.
 */
class ResolveIcalFeed
{
    /**
     * @return array{user: User, store: Store}|null
     */
    public function handle(string $token, int $storeId): ?array
    {
        $row = IcalToken::query()->where('token_hash', IcalToken::hash($token))->first();

        if ($row === null || $row->store_id !== $storeId) {
            return null;
        }

        // Les modèles User et Store excluent déjà les comptes et magasins supprimés.
        $user = User::query()->whereKey($row->user_id)->where('is_active', true)->first();
        $store = Store::query()->whereKey($row->store_id)->first();

        if ($user === null || $store === null) {
            return null;
        }

        $active = DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->exists();

        return $active ? ['user' => $user, 'store' => $store] : null;
    }
}
