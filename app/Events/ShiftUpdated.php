<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un shift vient d'être modifié.
 */
class ShiftUpdated
{
    use Dispatchable;

    /**
     * @param  bool  $userChanged  L'employé a changé (assigné, réassigné ou rendu ouvert) : `$previousUserId` est l'ancien.
     * @param  bool  $rescheduled  Le magasin, le jour ou les heures ont changé.
     * @param  int|null  $actorId  Auteur de la modification, quand il est connu.
     * @param  array{store_id: int, date: string, start: string, end: string}|null  $previousSlot  Créneau avant la modification (`Shift::slot()`).
     */
    public function __construct(
        public readonly int $shiftId,
        public readonly ?int $previousUserId = null,
        public readonly bool $userChanged = false,
        public readonly bool $rescheduled = false,
        public readonly ?int $actorId = null,
        public readonly ?array $previousSlot = null,
    ) {}
}
