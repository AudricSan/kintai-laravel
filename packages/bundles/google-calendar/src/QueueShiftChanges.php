<?php

namespace Kintai\Bundles\GoogleCalendar;

use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Events\ShiftDeleted;
use Kintai\Contracts\Events\ShiftReplaced;
use Kintai\Contracts\Events\ShiftRestored;
use Kintai\Contracts\Events\ShiftUpdated;

/**
 * Écouteurs des événements de shift : ils ne font que mettre la source en file (une écriture en base, jamais d'appel à
 * Google pendant la requête de l'utilisateur) ; la commande `google-calendar:sync` fait le reste. L'événement de
 * suppression ne porte ni magasin ni employé : `CalendarSync::shiftChanged()` relit le shift dans la corbeille et
 * retrouve aussi les liaisons par leurs événements déjà créés.
 */
class QueueShiftChanges
{
    public function __construct(private readonly CalendarSync $sync) {}

    public function created(ShiftCreated $event): void
    {
        $this->sync->shiftChanged($event->shiftId);
    }

    public function updated(ShiftUpdated $event): void
    {
        $this->sync->shiftChanged($event->shiftId, $event->previousUserId);
    }

    public function deleted(ShiftDeleted $event): void
    {
        $this->sync->shiftChanged($event->shiftId);
    }

    public function restored(ShiftRestored $event): void
    {
        $this->sync->shiftChanged($event->shiftId);
    }

    public function replaced(ShiftReplaced $event): void
    {
        $this->sync->shiftChanged($event->replacedShiftId);
        $this->sync->shiftChanged($event->replacementShiftId);
    }
}
