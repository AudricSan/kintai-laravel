<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un shift vient d'être restauré.
 */
class ShiftRestored
{
    use Dispatchable;

    public function __construct(public readonly int $shiftId, public readonly ?int $actorId = null) {}
}
