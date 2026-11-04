<?php

namespace App\Actions\Shifts;

use App\Authorization\Authorizer;
use App\Events\ShiftRestored;
use App\Exceptions\ShiftRestoreException;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Restaure un shift de la corbeille (`shifts.delete`, le droit inverse de la suppression). Refusé tant que son
 * remplaçant est actif (hook `restoring` du modèle).
 */
class RestoreShift
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Shift $shift): Shift
    {
        if (! $this->authorizer->allows($actor, 'shifts.delete', $shift->store_id)) {
            throw new AuthorizationException;
        }

        if (! $shift->trashed()) {
            throw ValidationException::withMessages(['shift' => __('This shift is not deleted.')]);
        }

        try {
            $shift->restore();
        } catch (ShiftRestoreException) {
            throw ValidationException::withMessages(['shift' => __('This shift was replaced by another shift and cannot be restored.')]);
        }

        ShiftRestored::dispatch($shift->id, $actor->id);

        return $shift;
    }
}
