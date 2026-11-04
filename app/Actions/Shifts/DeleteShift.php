<?php

namespace App\Actions\Shifts;

use App\Authorization\Authorizer;
use App\Events\ShiftDeleted;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Envoie un shift à la corbeille (`shifts.delete` sur son magasin).
 */
class DeleteShift
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Shift $shift): void
    {
        if (! $this->authorizer->allows($actor, 'shifts.delete', $shift->store_id)) {
            throw new AuthorizationException;
        }

        if ($shift->trashed()) {
            throw ValidationException::withMessages(['shift' => __('This shift is deleted.')]);
        }

        $shift->delete();

        ShiftDeleted::dispatch($shift->id, $actor->id);
    }
}
