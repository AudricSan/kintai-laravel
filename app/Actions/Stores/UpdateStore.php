<?php

namespace App\Actions\Stores;

use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\StoreUpdated;

/**
 * Modifie les réglages d'un magasin et ses fonctionnalités.
 */
class UpdateStore
{
    use AuthorizesStoreActions;
    use ManagesStoreSettings;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Store $store, array $input): Store
    {
        $this->authorizeOnStore($actor, 'stores.view', $store);
        $this->authorizeOnStore($actor, 'stores.update', $store);

        $data = $this->validateStoreInput($input, $store->id);

        DB::transaction(function () use ($store, $data): void {
            $store->fill($this->storeAttributes($data))->save();
            $this->syncStoreFeatures($store, $data['features'] ?? []);
        });

        StoreUpdated::dispatch($store->id);

        return $store;
    }
}
