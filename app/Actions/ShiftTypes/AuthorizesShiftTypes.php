<?php

namespace App\Actions\ShiftTypes;

use App\Authorization\Authorizer;
use App\Models\ShiftType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Droits communs aux actions sur les types de shift : se gérer avec `shifts.update`, magasin par magasin.
 */
trait AuthorizesShiftTypes
{
    /**
     * Modifier ou supprimer un type exige `shifts.update` sur tous les magasins auxquels il est rattaché (ou sans restriction).
     * Un acteur restreint ne touche pas un type sans magasin : il ne le voit pas.
     *
     * @throws AuthorizationException
     */
    protected function authorizeManageType(User $actor, ShiftType $type): void
    {
        if (! $this->canManageType($actor, $type)) {
            throw new AuthorizationException;
        }
    }

    public function canManageType(User $actor, ShiftType $type): bool
    {
        $allowed = app(Authorizer::class)->storeIdsFor($actor, 'shifts.update');

        if ($allowed === null) {
            return true;
        }

        $attached = $type->stores()->pluck('stores.id')->map(fn ($id): int => (int) $id)->all();

        return $attached !== [] && array_diff($attached, $allowed) === [];
    }
}
