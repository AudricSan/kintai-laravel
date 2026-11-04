<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un shift en a remplacé un autre : l'ancien est dans la corbeille, le nouveau fait foi.
 */
class ShiftReplaced
{
    use Dispatchable;

    public function __construct(public readonly int $replacedShiftId, public readonly int $replacementShiftId) {}
}
