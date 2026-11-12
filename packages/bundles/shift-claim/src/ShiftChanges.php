<?php

namespace Kintai\Bundles\ShiftClaim;

use App\Models\Shift;
use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Events\ShiftDeleted;
use Kintai\Contracts\Events\ShiftReplaced;
use Kintai\Contracts\Events\ShiftUpdated;

/**
 * Suit les événements des shifts du Core pour garder la bourse juste : un shift qui devient ouvert est annoncé aux membres
 * du magasin ; un shift qui n'est plus disponible (supprimé, remplacé, réaffecté) perd ses candidatures en attente (leurs
 * auteurs sont prévenus) et sa proposition éventuelle.
 */
class ShiftChanges
{
    public function __construct(private readonly ClaimActions $actions) {}

    public function created(ShiftCreated $event): void
    {
        $shift = Shift::query()->find($event->shiftId);

        if ($shift?->user_id === null && $shift !== null) {
            $this->actions->announce($shift, $event->actorId);
        }
    }

    public function updated(ShiftUpdated $event): void
    {
        if (! $event->userChanged) {
            return;
        }

        // L'employé a changé : les candidatures et la proposition portaient sur l'ancienne affectation.
        $this->actions->release($event->shiftId, $event->actorId);

        $shift = Shift::query()->find($event->shiftId);

        if ($shift !== null && $shift->user_id === null) {
            $this->actions->announce($shift, $event->actorId);
        }
    }

    public function deleted(ShiftDeleted $event): void
    {
        $this->actions->release($event->shiftId, $event->actorId);
    }

    public function replaced(ShiftReplaced $event): void
    {
        $this->actions->release($event->replacedShiftId, $event->actorId);
    }
}
