<?php

namespace App\Actions\Shifts;

use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftUpdated;

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
        // Le préparateur modifie ce même objet : le créneau d'avant est à relever d'abord.
        $before = $shift->replicate()->slot();

        $shift = $this->preparer->prepare($actor, $input, $shift, $force);

        if ($shift->isDirty()) {
            // À relever avant l'enregistrement : après, les valeurs précédentes ne sont plus connues.
            $userChanged = $shift->isDirty('user_id');
            $previousUserId = $shift->getOriginal('user_id');
            $rescheduled = $shift->isDirty(['store_id', 'shift_date', 'starts_at', 'ends_at']);

            $shift->save();
            ShiftUpdated::dispatch($shift->id, $previousUserId === null ? null : (int) $previousUserId, $userChanged, $rescheduled, $actor->id, $before);
        }

        return $shift;
    }
}
