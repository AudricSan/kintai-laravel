<?php

namespace App\Actions\Stores;

use App\Events\StoreCreated;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crée un magasin : réservé à qui a `stores.create` sans restriction de magasin.
 */
class CreateStore
{
    use AuthorizesStoreActions;
    use ManagesStoreSettings;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $input): Store
    {
        $this->authorizeUnrestricted($actor, 'stores.create');

        $data = $this->validateStoreInput($input);

        $store = DB::transaction(function () use ($data): Store {
            $store = Store::query()->create($this->storeAttributes($data));
            $this->syncStoreFeatures($store, $data['features'] ?? []);

            return $store;
        });

        StoreCreated::dispatch($store->id);

        return $store;
    }
}
