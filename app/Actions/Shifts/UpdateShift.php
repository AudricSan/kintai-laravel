<?php

namespace App\Actions\Shifts;

use App\Events\ShiftUpdated;
use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Modifie un shift. Les clés absentes de la saisie gardent leur valeur ; `ical_sequence` s'incrémente
 * (hook du modèle) quand quelque chose change.
 */
class UpdateShift
{
    public function __construct(private readonly ShiftPreparer $preparer) {}

    /**
     * @param  array<string, mixed>  $input  Voir ShiftPreparer.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ShiftWarningsException
     */
    public function handle(User $actor, Shift $shift, array $input, bool $force = false): Shift
    {
        $shift = $this->preparer->prepare($actor, $input, $shift, $force);

        if ($shift->isDirty()) {
            $shift->save();
            ShiftUpdated::dispatch($shift->id);
        }

        return $shift;
    }
}
