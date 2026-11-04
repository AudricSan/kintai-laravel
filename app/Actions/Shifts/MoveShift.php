<?php

namespace App\Actions\Shifts;

use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Déplace un shift (glisser-déposer) : nouveau jour, nouvelles heures et/ou nouvel employé (`user_id`, null = ouvert).
 * Le reste du shift (pause, notes, ajustements) est conservé.
 */
class MoveShift
{
    public function __construct(private readonly UpdateShift $update) {}

    /**
     * @param  array{shift_date?: string, start_time?: string, end_time?: string, user_id?: int|string|null}  $changes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarningsException
     */
    public function handle(User $actor, Shift $shift, array $changes, bool $force = false): Shift
    {
        return $this->update->handle($actor, $shift, array_intersect_key($changes, array_flip(['shift_date', 'start_time', 'end_time', 'user_id'])), $force);
    }
}
