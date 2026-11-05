<?php

namespace App\Actions\ShiftTypes;

use App\Authorization\Authorizer;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftTypeUpdated;

/**
 * Active ou désactive un type pour un magasin (rattachement), même si le type est partagé avec d'autres magasins.
 */
class ToggleShiftTypeStore
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, ShiftType $type, Store $store): void
    {
        if (! $this->authorizer->allows($actor, 'shifts.update', $store)) {
            throw new AuthorizationException;
        }

        if ($type->stores()->where('stores.id', $store->id)->exists()) {
            if ($type->stores()->count() <= 1) {
                throw ValidationException::withMessages([
                    'store' => __('A shift type must stay available in at least one store.'),
                ]);
            }

            $type->stores()->detach($store->id);
        } else {
            $type->stores()->attach($store->id);
        }

        ShiftTypeUpdated::dispatch($type->id);
    }
}
