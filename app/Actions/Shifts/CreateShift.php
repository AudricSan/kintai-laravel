<?php

namespace App\Actions\Shifts;

use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftCreated;

/**
 * Crée un shift (ouvert si `user_id` est null). Les avertissements du garde-fou lèvent ShiftWarningsException
 * tant que `$force` est faux.
 */
class CreateShift
{
    public function __construct(private readonly ShiftPreparer $preparer) {}

    /**
     * @param  array<string, mixed>  $input  Voir ShiftPreparer.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarningsException
     */
    public function handle(User $actor, array $input, bool $force = false): Shift
    {
        $shift = $this->preparer->prepare($actor, $input, null, $force);
        $shift->save();

        ShiftCreated::dispatch($shift->id, $actor->id);

        return $shift;
    }
}
