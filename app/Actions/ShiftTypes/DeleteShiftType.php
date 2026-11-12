<?php

namespace App\Actions\ShiftTypes;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftTypeDeleted;

/**
 * Supprime définitivement un type de shift qu'aucun shift (même supprimé) n'utilise ; sinon il se désactive.
 */
class DeleteShiftType
{
    use AuthorizesShiftTypes;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, ShiftType $type): void
    {
        $this->authorizeManageType($actor, $type);

        if (Shift::withTrashed()->where('shift_type_id', $type->id)->exists()) {
            throw ValidationException::withMessages([
                'delete' => __('Shifts use this shift type: deactivate it instead of deleting it.'),
            ]);
        }

        $id = $type->id;
        $name = $type->name;
        $code = $type->code;
        $type->delete();

        ShiftTypeDeleted::dispatch($id, $name, $code);
    }
}
