<?php

namespace App\Actions\Stores;

use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Kintai\Contracts\Events\StoreDeleted;

/**
 * Supprime un magasin (suppression douce) : réservé à qui a `stores.delete` sans restriction de magasin.
 * Les shifts restent ; le code est libéré pour qu'un nouveau magasin puisse le reprendre.
 */
class DeleteStore
{
    use AuthorizesStoreActions;

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, Store $store): void
    {
        $this->authorizeUnrestricted($actor, 'stores.delete');

        DB::transaction(function () use ($store): void {
            $store->code = mb_substr('DEL-'.$store->id.'-'.$store->code, 0, 255);
            $store->save();
            $store->delete();
        });

        StoreDeleted::dispatch($store->id);
    }
}
